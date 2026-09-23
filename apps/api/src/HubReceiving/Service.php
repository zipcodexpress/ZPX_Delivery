<?php
declare(strict_types=1);
namespace Zpx\HubReceiving;

use PDO;
use Zpx\Custody\ScanJournal;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;

/** Hub receiving: independent scan-based custody transfer from driver to hub. */
final class Service
{
    private Identity $identity;
    private ?ScanJournal $journal = null;

    public function __construct(private PDO $db, private Secrets $crypto)
    {
        $this->identity = new Identity($db, $crypto);
    }

    private function journal(): ScanJournal
    {
        return $this->journal ??= new ScanJournal($this->db);
    }

    private function recordRejectedScan(string $user, ?string $runId, ?string $packageId, string $resultCode): void
    {
        $this->journal()->stage($user, $runId, $packageId, 'HUB_RECEIVE', $resultCode);
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

    private function discrepancy(string $user, string $hubId, string $runId, string $sessionId, string $packageId, string $driverId, string $code, ?string $notes): string
    {
        $id = $this->insert(
            "INSERT INTO exceptions(organization_id,hub_id,driver_id,receiving_session_id,package_id,run_id,code,status,recorded_by,notes)
             VALUES (?,?,?,?,?,?,?,'OPEN',?,?) ON CONFLICT (receiving_session_id,package_id,code) WHERE receiving_session_id IS NOT NULL DO UPDATE SET notes=COALESCE(EXCLUDED.notes,exceptions.notes)",
            [$this->org(), $hubId, $driverId, $sessionId, $packageId, $runId, $code, $user, $notes]
        );
        $this->q(
            "INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,?,?,?::jsonb,now())",
            [$packageId, Secrets::uuid(), $code . '_REPORTED', $user, json_encode(['exception_id' => $id, 'run_id' => $runId, 'hub_id' => $hubId], JSON_THROW_ON_ERROR)]
        );
        $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'RECEIVING_DISCREPANCY_REPORTED','exception',?)", [$user, $id]);
        return $id;
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
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['receiving-run:' . $this->org() . ':' . $hubId . ':' . $runId]);

            // Verify run exists and is inbound to this hub
            $run = $this->q(
                "SELECT id, kind, state, hub_id FROM route_runs WHERE id=? AND hub_id=? AND kind='INBOUND'",
                [$runId, $hubId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$run) {
                throw new Failure(404, 'RUN_NOT_FOUND', 'Inbound run not found for this hub.');
            }
            if (!in_array($run['state'], ['ACKNOWLEDGED', 'IN_PROGRESS'], true)) {
                throw new Failure(409, 'RUN_NOT_RECEIVABLE', 'Inbound run is not ready for receiving.');
            }

            // One immutable receiving lifecycle per run. Corrections use discrepancy resolution.
            $existing = $this->q(
                "SELECT id, status FROM receiving_sessions WHERE hub_id=? AND inbound_run_id=?",
                [$hubId, $runId]
            )->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                throw new Failure(409, $existing['status'] === 'OPEN' ? 'SESSION_EXISTS' : 'SESSION_FINALIZED', 'A receiving session already exists for this run.');
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
        Input::fields($input, ['label_payload', 'inbound_run_id', 'receiving_session_id', 'expected_package_version'], ['disposition', 'notes']);
        $labelToken = Input::text($input['label_payload'], 1, 500);
        $runId = Input::text($input['inbound_run_id'], 1, 18);
        $sessionId = Input::text($input['receiving_session_id'], 1, 18);
        $expectedVersion = (int)$input['expected_package_version'];
        $disposition = $input['disposition'] ?? 'RECEIVED';
        if (!in_array($disposition, ['RECEIVED', 'DAMAGED'], true)) { throw new Failure(422, 'INVALID_DISPOSITION', 'Disposition must be RECEIVED or DAMAGED.'); }
        $notes = array_key_exists('notes', $input) && $input['notes'] !== '' ? Input::text($input['notes'], 1, 1000) : null;
        if ($disposition === 'DAMAGED' && $notes === null) { throw new Failure(422, 'DAMAGE_NOTES_REQUIRED', 'Describe the observed damage.'); }
        Input::text($key, 16, 100);

        return $this->journal()->transact(function () use ($user, $labelToken, $runId, $sessionId, $expectedVersion, $disposition, $notes, $key) {
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

            // The caller's run id is only trustworthy once it matches the session, so refusals
            // before that point journal a null run rather than risk a foreign key violation.
            if (!$session) {
                $this->recordRejectedScan($user, null, null, 'SESSION_NOT_FOUND');
                throw new Failure(404, 'SESSION_NOT_FOUND', 'Receiving session not found or not open.');
            }
            if ((string)$session['inbound_run_id'] !== $runId) {
                $this->recordRejectedScan($user, null, null, 'RUN_MISMATCH');
                throw new Failure(409, 'RUN_MISMATCH', 'Session does not match the specified run.');
            }
            $scanRunId = $runId;

            // Resolve label
            $tokenHash = hash('sha256', $labelToken);
            $label = $this->q(
                "SELECT pl.id AS label_id, pl.package_id, pl.status, p.state AS package_state, p.version AS package_version,
                        p.custodian_type, p.custodian_ref, r.driver_id
                 FROM package_labels pl JOIN packages p ON p.id=pl.package_id JOIN shipments s ON s.id=p.shipment_id
                 LEFT JOIN route_runs r ON r.id=? WHERE pl.token_hash=decode(?,'hex') AND s.organization_id=?",
                [$runId, $tokenHash, $this->org()]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$label) {
                $this->recordRejectedScan($user, $scanRunId, null, 'LABEL_NOT_FOUND');
                throw new Failure(404, 'LABEL_NOT_FOUND', 'Label not recognized.');
            }
            if ($label['status'] !== 'ACTIVE') {
                $this->recordRejectedScan($user, $scanRunId, (string)$label['package_id'], 'LABEL_REVOKED');
                throw new Failure(410, 'LABEL_REVOKED', 'This label is no longer active.');
            }

            $packageId = (string)$label['package_id'];
            $responsibleDriver = $label['custodian_type'] === 'DRIVER' ? (string)$label['custodian_ref'] : (string)$label['driver_id'];

            // Check not already received in this session
            $alreadyReceived = $this->q(
                "SELECT id FROM receiving_items WHERE session_id=? AND package_id=?",
                [$sessionId, $packageId]
            )->fetchColumn();

            if ($alreadyReceived) {
                $this->recordRejectedScan($user, $scanRunId, $packageId, 'ALREADY_RECEIVED');
                throw new Failure(409, 'ALREADY_RECEIVED', 'Package already received in this session.');
            }

            // A parcel must belong to this run, not merely be in inbound custody somewhere.
            $onManifest = $this->q(
                "SELECT id FROM manifest_items WHERE run_id=? AND package_id=?",
                [$runId, $packageId]
            )->fetchColumn();

            if (!$onManifest) {
                $this->q(
                    "INSERT INTO receiving_items(session_id,package_id,disposition) VALUES (?,?,'EXTRA') ON CONFLICT(session_id,package_id) DO NOTHING",
                    [$sessionId, $packageId]
                );
                $exceptionId = $this->discrepancy($user, $hubId, $runId, $sessionId, $packageId, $responsibleDriver, 'EXTRA', $notes);
                $this->q(
                    "INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at) VALUES (?,?,?,?, 'HUB_RECEIVE','NOT_ON_MANIFEST',now())",
                    [Secrets::uuid(), $packageId, $user, $runId]
                );
                return ['package_id' => $packageId, 'package_version' => (int)$label['package_version'], 'state' => $label['package_state'], 'result_code' => 'EXTRA_RECORDED', 'disposition' => 'EXTRA', 'exception_id' => $exceptionId,
                    'received_count' => (int)$this->q("SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition IN ('RECEIVED','DAMAGED')", [$sessionId])->fetchColumn(),
                    'expected_count' => (int)$this->q("SELECT COUNT(*) FROM manifest_items WHERE run_id=?", [$runId])->fetchColumn()];
            }

            // Lock package and check state
            $package = $this->q(
                "SELECT p.id, p.state, p.version, p.custodian_type, p.custodian_ref FROM packages p WHERE p.id=? FOR UPDATE",
                [$packageId]
            )->fetch(PDO::FETCH_ASSOC);

            if ($package['state'] !== 'INBOUND_CUSTODY') {
                $this->recordRejectedScan($user, $scanRunId, $packageId, 'WRONG_PACKAGE_STATE');
                throw new Failure(409, 'WRONG_PACKAGE_STATE', 'Package is not in inbound custody. Current state: ' . $package['state']);
            }

            if ((int)$package['version'] !== $expectedVersion) {
                $this->recordRejectedScan($user, $scanRunId, $packageId, 'VERSION_MISMATCH');
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
                "INSERT INTO receiving_items(session_id,package_id,disposition) VALUES (?,?,?)",
                [$sessionId, $packageId, $disposition]
            );

            $exceptionId = null;
            if ($disposition === 'DAMAGED') {
                $exceptionId = $this->discrepancy($user, $hubId, $runId, $sessionId, $packageId, (string)$package['custodian_ref'], 'DAMAGED', $notes);
            }

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
                "SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition IN ('RECEIVED','DAMAGED')",
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
                'disposition' => $disposition,
                'exception_id' => $exceptionId,
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

            $missing = $this->q(
                "SELECT mi.package_id, r.driver_id FROM manifest_items mi JOIN route_runs r ON r.id=mi.run_id
                 WHERE mi.run_id=? AND mi.state='EXPECTED' AND mi.package_id NOT IN (SELECT package_id FROM receiving_items WHERE session_id=?) FOR UPDATE OF mi",
                [$runId, $sessionId]
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($missing as $row) {
                $this->q("UPDATE manifest_items SET state='SHORT' WHERE run_id=? AND package_id=?", [$runId, $row['package_id']]);
                $this->q("INSERT INTO receiving_items(session_id,package_id,disposition) VALUES (?,?,'SHORT')", [$sessionId, $row['package_id']]);
                $this->discrepancy($user, $hubId, $runId, $sessionId, (string)$row['package_id'], (string)$row['driver_id'], 'SHORT', 'Not physically received when the session closed.');
            }

            // Close session
            $this->q("UPDATE receiving_sessions SET status='CLOSED' WHERE id=?", [$sessionId]);
            $this->q("UPDATE route_runs SET state='COMPLETED' WHERE id=?", [$runId]);
            $this->q("UPDATE route_run_stops SET state='COMPLETED' WHERE run_id=?", [$runId]);

            // Count results
            $receivedCount = (int)$this->q(
                "SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition IN ('RECEIVED','DAMAGED')",
                [$sessionId]
            )->fetchColumn();
            $expectedCount = (int)$this->q(
                "SELECT COUNT(*) FROM manifest_items WHERE run_id=?",
                [$runId]
            )->fetchColumn();
            $shortCount = count($missing);
            $damagedCount = (int)$this->q("SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition='DAMAGED'", [$sessionId])->fetchColumn();
            $extraCount = (int)$this->q("SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition='EXTRA'", [$sessionId])->fetchColumn();

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'RECEIVING_SESSION_CLOSED','receiving_session',?)", [$user, $sessionId]);

            return [
                'receiving_session_id' => $sessionId,
                'hub_id' => $hubId,
                'inbound_run_id' => $runId,
                'expected_count' => $expectedCount,
                'received_count' => $receivedCount,
                'short_count' => $shortCount,
                'damaged_count' => $damagedCount,
                'extra_count' => $extraCount,
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
            "SELECT COUNT(*) FROM receiving_items WHERE session_id=? AND disposition IN ('RECEIVED','DAMAGED')",
            [$sessionId]
        )->fetchColumn();
        $expectedCount = (int)$this->q(
            "SELECT COUNT(*) FROM manifest_items WHERE run_id=?",
            [$session['inbound_run_id']]
        )->fetchColumn();

        $counts = $this->q("SELECT disposition,COUNT(*) AS count FROM receiving_items WHERE session_id=? GROUP BY disposition", [$sessionId])->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'receiving_session_id' => (string)$session['id'],
            'hub_id' => (string)$session['hub_id'],
            'inbound_run_id' => (string)$session['inbound_run_id'],
            'expected_count' => $expectedCount,
            'received_count' => $receivedCount,
            'short_count' => (int)($counts['SHORT'] ?? 0),
            'damaged_count' => (int)($counts['DAMAGED'] ?? 0),
            'extra_count' => (int)($counts['EXTRA'] ?? 0),
            'state' => $session['status'],
        ];
    }

    public function listDiscrepancies(string $user): array
    {
        $hubId = $this->hubId($user);
        $rows = $this->q(
            "SELECT e.id,e.package_id,e.run_id,e.code,e.status,e.notes,e.created_at,e.resolution_code,e.resolved_at,
                    s.public_reference,p.state AS package_state,p.custodian_type,p.custodian_ref
             FROM exceptions e JOIN packages p ON p.id=e.package_id JOIN shipments s ON s.id=p.shipment_id
             WHERE e.organization_id=? AND e.hub_id=? ORDER BY (e.status='OPEN') DESC,e.created_at DESC,e.id DESC",
            [$this->org(), $hubId]
        )->fetchAll(PDO::FETCH_ASSOC);
        return ['items' => array_map(fn(array $r) => [
            'id'=>(string)$r['id'],'package_id'=>(string)$r['package_id'],'run_id'=>$r['run_id'] === null ? null : (string)$r['run_id'],
            'public_reference'=>$r['public_reference'],'type'=>$r['code'],'status'=>$r['status'],'notes'=>$r['notes'],
            'package_state'=>$r['package_state'],'custodian_type'=>$r['custodian_type'],'custodian_ref'=>$r['custodian_ref'],
            'reported_at'=>$r['created_at'],'resolution_code'=>$r['resolution_code'],'resolved_at'=>$r['resolved_at'],
        ], $rows)];
    }

    public function resolveDiscrepancy(string $user, string $exceptionId, array $input, string $key): array
    {
        Input::text($exceptionId, 1, 18); Input::text($key, 16, 100);
        Input::fields($input, ['resolution_code'], ['notes']);
        $code = Input::text($input['resolution_code'], 1, 64);
        $notes = array_key_exists('notes', $input) && $input['notes'] !== '' ? Input::text($input['notes'], 1, 1000) : null;
        return (new Transaction($this->db))->run(function () use ($user,$exceptionId,$code,$notes,$key) {
            $hubId = $this->hubId($user);
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['exception:' . $exceptionId . ':' . $key]);
            $row = $this->q("SELECT * FROM exceptions WHERE id=? AND organization_id=? AND hub_id=? FOR UPDATE", [$exceptionId,$this->org(),$hubId])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'DISCREPANCY_NOT_FOUND','Discrepancy not found.'); }
            if ($row['status'] !== 'OPEN') { throw new Failure(409,'DISCREPANCY_RESOLVED','Discrepancy is already resolved.'); }
            $resolution = json_encode(['code'=>$code,'notes'=>$notes], JSON_THROW_ON_ERROR);
            $this->q("UPDATE exceptions SET status='RESOLVED',resolution=?::jsonb,resolution_code=?,resolved_by=?,resolved_at=now() WHERE id=?", [$resolution,$code,$user,$exceptionId]);
            $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?, 'DISCREPANCY_RESOLVED',?,?::jsonb,now())", [$row['package_id'],Secrets::uuid(),$user,$resolution]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'RECEIVING_DISCREPANCY_RESOLVED','exception',?)", [$user,$exceptionId]);
            return ['id'=>$exceptionId,'status'=>'RESOLVED','resolution_code'=>$code];
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function insert(string $sql, array $values): string
    {
        $q = $this->db->prepare($sql . ' RETURNING id');
        $q->execute($values);
        return (string)$q->fetchColumn();
    }
}
