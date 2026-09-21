<?php
declare(strict_types=1);
namespace Zpx\HubReceiving;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;

/** Hub receiving: independent scan-based custody transfer from driver to hub. */
final class Service
{
    private Identity $identity;

    public function __construct(private PDO $db, private Secrets $crypto)
    {
        $this->identity = new Identity($db, $crypto);
    }

    private function q(string $sql, array $values = []): \PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($values);
        return $q;
    }

    private function org(): string
    {
        return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0');
    }

    /** HUB_STAFF grants are scoped to a hub location, so requireRole must be given that location to match. */
    private function hubId(string $userId): string
    {
        $hub = $this->q(
            'SELECT hs.hub_id, h.location_id FROM hub_staff hs
             JOIN hubs h ON h.id=hs.hub_id
             JOIN locations l ON l.id=h.location_id
             WHERE hs.user_id=? AND l.organization_id=?',
            [$userId, $this->org()]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$hub) { throw new Failure(403, 'ACCESS_DENIED', 'No hub assignment found.'); }
        $this->identity->requireRole($userId, 'HUB_STAFF', (string)$hub['location_id']);
        return (string)$hub['hub_id'];
    }

    // ── Open Receiving Session ───────────────────────────────────────

    public function openSession(string $user, array $input, string $key): array
    {
        Input::fields($input, ['hub_id', 'inbound_run_id']);
        $hubId = Input::text($input['hub_id'], 1, 18);
        $runId = Input::text($input['inbound_run_id'], 1, 18);
        Input::text($key, 16, 100);

        return (new Transaction($this->db))->run(function () use ($user, $hubId, $runId, $key) {
            $scope = 'hub:' . $this->org() . ':' . $user . ':open-session';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            if (!$this->q("SELECT id FROM users WHERE id=? AND organization_id=? AND status='ACTIVE'", [$user, $this->org()])->fetchColumn()) {
                throw new Failure(403, 'ACCESS_DENIED', 'Access denied.');
            }

            // Verify hub assignment
            $myHub = $this->hubId($user);
            if ($myHub !== $hubId) {
                throw new Failure(403, 'ACCESS_DENIED', 'You are not assigned to this hub.');
            }

            // Verify run exists and is inbound to this hub
            $run = $this->q(
                "SELECT id, kind, state, hub_id FROM route_runs WHERE id=? AND hub_id=? AND kind='INBOUND'",
                [$runId, $hubId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$run) {
                throw new Failure(404, 'RUN_NOT_FOUND', 'Inbound run not found for this hub.');
            }

            // Check no open session for this run
            $existing = $this->q(
                "SELECT id FROM receiving_sessions WHERE hub_id=? AND inbound_run_id=? AND status='OPEN'",
                [$hubId, $runId]
            )->fetchColumn();

            if ($existing) {
                throw new Failure(409, 'SESSION_EXISTS', 'An open receiving session already exists for this run.');
            }

            // Count expected packages from manifest
            $expectedCount = (int)$this->q(
                "SELECT COUNT(*) FROM manifest_items WHERE run_id=?",
                [$runId]
            )->fetchColumn();

            $sessionId = $this->insert(
                "INSERT INTO receiving_sessions(hub_id,inbound_run_id,receiver_user_id,status) VALUES (?,?,?,'OPEN')",
                [$hubId, $runId, $user]
            );

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'RECEIVING_SESSION_OPENED','receiving_session',?)", [$user, $sessionId]);

            return [
                'receiving_session_id' => $sessionId,
                'hub_id' => $hubId,
                'inbound_run_id' => $runId,
                'expected_count' => $expectedCount,
                'received_count' => 0,
                'state' => 'OPEN',
            ];
        });
    }

    // ── Receive Scan ─────────────────────────────────────────────────

    public function receiveScan(string $user, array $input, string $key): array
    {
        Input::fields($input, ['label_payload', 'inbound_run_id', 'receiving_session_id', 'expected_package_version']);
        $labelToken = Input::text($input['label_payload'], 1, 500);
        $runId = Input::text($input['inbound_run_id'], 1, 18);
        $sessionId = Input::text($input['receiving_session_id'], 1, 18);
        $expectedVersion = (int)$input['expected_package_version'];
        Input::text($key, 16, 100);

        return (new Transaction($this->db))->run(function () use ($user, $labelToken, $runId, $sessionId, $expectedVersion, $key) {
            $scope = 'hub:' . $this->org() . ':' . $user . ':receive';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            if (!$this->q("SELECT id FROM users WHERE id=? AND organization_id=? AND status='ACTIVE'", [$user, $this->org()])->fetchColumn()) {
                throw new Failure(403, 'ACCESS_DENIED', 'Access denied.');
            }

            $hubId = $this->hubId($user);

            // Verify session is open and belongs to this hub
            $session = $this->q(
                "SELECT * FROM receiving_sessions WHERE id=? AND hub_id=? AND status='OPEN' FOR UPDATE",
                [$sessionId, $hubId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new Failure(404, 'SESSION_NOT_FOUND', 'Receiving session not found or not open.');
            }
            if ((string)$session['inbound_run_id'] !== $runId) {
                throw new Failure(409, 'RUN_MISMATCH', 'Session does not match the specified run.');
            }

            // Resolve label
            $tokenHash = hash('sha256', $labelToken);
            $label = $this->q(
                "SELECT pl.id AS label_id, pl.package_id, pl.status FROM package_labels pl WHERE pl.token_hash=decode(?,'hex')",
                [$tokenHash]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$label) {
                throw new Failure(404, 'LABEL_NOT_FOUND', 'Label not recognized.');
            }
            if ($label['status'] !== 'ACTIVE') {
                throw new Failure(410, 'LABEL_REVOKED', 'This label is no longer active.');
            }

            $packageId = (string)$label['package_id'];

            // Check not already received in this session
            $alreadyReceived = $this->q(
                "SELECT id FROM receiving_items WHERE session_id=? AND package_id=?",
                [$sessionId, $packageId]
            )->fetchColumn();

            if ($alreadyReceived) {
                throw new Failure(409, 'ALREADY_RECEIVED', 'Package already received in this session.');
            }

            // A parcel must belong to this run, not merely be in inbound custody somewhere.
            $onManifest = $this->q(
                "SELECT id FROM manifest_items WHERE run_id=? AND package_id=?",
                [$runId, $packageId]
            )->fetchColumn();

            if (!$onManifest) {
                throw new Failure(409, 'NOT_ON_MANIFEST', 'This package is not on the run manifest.');
            }

            // Lock package and check state
            $package = $this->q(
                "SELECT p.id, p.state, p.version, p.custodian_type, p.custodian_ref FROM packages p WHERE p.id=? FOR UPDATE",
                [$packageId]
            )->fetch(PDO::FETCH_ASSOC);

            if ($package['state'] !== 'INBOUND_CUSTODY') {
                throw new Failure(409, 'WRONG_PACKAGE_STATE', 'Package is not in inbound custody. Current state: ' . $package['state']);
            }

            if ((int)$package['version'] !== $expectedVersion) {
                throw new Failure(409, 'VERSION_MISMATCH', 'Expected package version ' . $expectedVersion . ' but found ' . $package['version'] . '.');
            }

            // Perform custody transfer: driver → hub
            $operationUuid = Secrets::uuid();
            $newVersion = (int)$package['version'] + 1;

            // Get hub location
            $hubLocation = $this->q("SELECT location_id FROM hubs WHERE id=?", [$hubId])->fetchColumn();

            // Update package
            $this->q(
                "UPDATE packages SET state='AT_HUB', custodian_type='HUB', custodian_ref=?, current_location_id=?, version=? WHERE id=?",
                [$hubId, $hubLocation, $newVersion, $packageId]
            );

            // Record scan event
            $this->q(
                "INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at) VALUES (?,?,?,?, 'HUB_RECEIVE','ACCEPTED',now())",
                [$operationUuid, $packageId, $user, $runId]
            );

            // Record custody event
            $this->q(
                "INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at) VALUES (?,?,?,?, 'CUSTODY_TRANSFER',?,?, 'HUB',?, ?, '{}', now())",
                [$packageId, $operationUuid, $newVersion, $user, $package['custodian_type'], $package['custodian_ref'], $hubId, $hubLocation]
            );

            // Record package event
            $this->q(
                "INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?, 'HUB_RECEIVE',?, '{}', now())",
                [$packageId, Secrets::uuid(), $user]
            );

            // Record receiving item
            $this->insert(
                "INSERT INTO receiving_items(session_id,package_id,disposition) VALUES (?,?, 'RECEIVED')",
                [$sessionId, $packageId]
            );

            // Update manifest item
            $this->q(
                "UPDATE manifest_items SET state='UNLOADED' WHERE run_id=? AND package_id=?",
                [$runId, $packageId]
            );

            // Outbox event
            (new Outbox($this->db))->append(
                Secrets::uuid(),
                'package',
                $packageId,
                'custody.hub_receive',
                ['package_id' => $packageId, 'run_id' => $runId, 'hub_id' => $hubId, 'new_version' => $newVersion]
            );

            // Audit
            $this->q(
                "INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'HUB_RECEIVE','package',?)",
                [$user, $packageId]
            );

            // Count received
            $receivedCount = (int)$this->q(
                "SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition='RECEIVED'",
                [$sessionId]
            )->fetchColumn();
            $expectedCount = (int)$this->q(
                "SELECT COUNT(*) FROM manifest_items WHERE run_id=?",
                [$runId]
            )->fetchColumn();

            return [
                'package_id' => $packageId,
                'package_version' => $newVersion,
                'state' => 'AT_HUB',
                'result_code' => 'ACCEPTED',
                'received_count' => $receivedCount,
                'expected_count' => $expectedCount,
            ];
        });
    }

    // ── Close Session ────────────────────────────────────────────────

    public function closeSession(string $user, string $sessionId, string $key): array
    {
        Input::text($sessionId, 1, 18);
        Input::text($key, 16, 100);

        return (new Transaction($this->db))->run(function () use ($user, $sessionId, $key) {
            $scope = 'hub:' . $this->org() . ':' . $user . ':close-session';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $hubId = $this->hubId($user);

            $session = $this->q(
                "SELECT * FROM receiving_sessions WHERE id=? AND hub_id=? AND status='OPEN' FOR UPDATE",
                [$sessionId, $hubId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$session) {
                throw new Failure(404, 'SESSION_NOT_FOUND', 'Receiving session not found or not open.');
            }

            $runId = (string)$session['inbound_run_id'];

            // Mark unreceived manifest items as SHORT
            $this->q(
                "UPDATE manifest_items SET state='SHORT' WHERE run_id=? AND state='EXPECTED' AND package_id NOT IN (SELECT package_id FROM receiving_items WHERE session_id=?)",
                [$runId, $sessionId]
            );

            // Close session
            $this->q("UPDATE receiving_sessions SET status='CLOSED' WHERE id=?", [$sessionId]);

            // Count results
            $receivedCount = (int)$this->q(
                "SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition='RECEIVED'",
                [$sessionId]
            )->fetchColumn();
            $expectedCount = (int)$this->q(
                "SELECT COUNT(*) FROM manifest_items WHERE run_id=?",
                [$runId]
            )->fetchColumn();
            $shortCount = $expectedCount - $receivedCount;

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'RECEIVING_SESSION_CLOSED','receiving_session',?)", [$user, $sessionId]);

            return [
                'receiving_session_id' => $sessionId,
                'hub_id' => $hubId,
                'inbound_run_id' => $runId,
                'expected_count' => $expectedCount,
                'received_count' => $receivedCount,
                'short_count' => $shortCount,
                'state' => 'CLOSED',
            ];
        });
    }

    // ── Session Status ───────────────────────────────────────────────

    public function getSession(string $user, string $sessionId): array
    {
        Input::text($sessionId, 1, 18);
        $hubId = $this->hubId($user);

        $session = $this->q(
            "SELECT * FROM receiving_sessions WHERE id=? AND hub_id=?",
            [$sessionId, $hubId]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new Failure(404, 'SESSION_NOT_FOUND', 'Receiving session not found.');
        }

        $receivedCount = (int)$this->q(
            "SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition='RECEIVED'",
            [$sessionId]
        )->fetchColumn();
        $expectedCount = (int)$this->q(
            "SELECT COUNT(*) FROM manifest_items WHERE run_id=?",
            [$session['inbound_run_id']]
        )->fetchColumn();

        return [
            'receiving_session_id' => (string)$session['id'],
            'hub_id' => (string)$session['hub_id'],
            'inbound_run_id' => (string)$session['inbound_run_id'],
            'expected_count' => $expectedCount,
            'received_count' => $receivedCount,
            'state' => $session['status'],
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function insert(string $sql, array $values): string
    {
        $q = $this->db->prepare($sql . ' RETURNING id');
        $q->execute($values);
        return (string)$q->fetchColumn();
    }
}
