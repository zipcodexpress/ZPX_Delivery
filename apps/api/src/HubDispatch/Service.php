<?php
declare(strict_types=1);
namespace Zpx\HubDispatch;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;

/** Hub sorting, dispatch calls and driver pickup workflow.
 *  Flow: Hub receives → Sort to lot → Dispatch call → Driver confirms → Driver loads → Driver delivers */
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

    // ── Hub Staff: Stage package to destination lot ──────────────────

    public function stageScan(string $user, array $input, string $key): array
    {
        Input::fields($input, ['label_payload', 'slot_code']);
        $labelToken = Input::text($input['label_payload'], 1, 500);
        $slotCode = Input::text($input['slot_code'], 1, 40);
        Input::text($key, 16, 100);

        return (new Transaction($this->db))->run(function () use ($user, $labelToken, $slotCode, $key) {
            $scope = 'hub:' . $this->org() . ':' . $user . ':stage';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $hubId = $this->hubId($user);

            // Resolve label
            $tokenHash = hash('sha256', $labelToken);
            $label = $this->q(
                "SELECT pl.package_id, pl.status FROM package_labels pl WHERE pl.token_hash=decode(?,'hex')",
                [$tokenHash]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$label) { throw new Failure(404, 'LABEL_NOT_FOUND', 'Label not recognized.'); }
            if ($label['status'] !== 'ACTIVE') { throw new Failure(410, 'LABEL_REVOKED', 'Label revoked.'); }

            $packageId = (string)$label['package_id'];

            // Check package is AT_HUB
            $package = $this->q(
                "SELECT p.id, p.state, p.version, p.current_location_id FROM packages p WHERE p.id=? FOR UPDATE",
                [$packageId]
            )->fetch(PDO::FETCH_ASSOC);

            if ($package['state'] !== 'AT_HUB') {
                throw new Failure(409, 'WRONG_STATE', 'Package is not at hub. State: ' . $package['state']);
            }

            // Find the slot
            $slot = $this->q(
                "SELECT id, destination_location_id, status FROM hub_slots WHERE hub_id=? AND code=? FOR UPDATE",
                [$hubId, $slotCode]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$slot) { throw new Failure(404, 'SLOT_NOT_FOUND', 'Slot not found at this hub.'); }

            // Check if already staged
            $existing = $this->q("SELECT id FROM staging_assignments WHERE package_id=?", [$packageId])->fetchColumn();
            if ($existing) { throw new Failure(409, 'ALREADY_STAGED', 'Package already staged.'); }

            // Create staging assignment
            $assignmentId = $this->insert(
                "INSERT INTO staging_assignments(package_id,slot_id,assigned_by,routing_revision) VALUES (?,?,?,1)",
                [$packageId, $slot['id'], $user]
            );

            // Update package state to STAGED
            $newVersion = (int)$package['version'] + 1;
            $this->q(
                "UPDATE packages SET state='STAGED', version=? WHERE id=?",
                [$newVersion, $packageId]
            );

            // Record package event
            $this->q(
                "INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?, 'STAGE',?, '{}', now())",
                [$packageId, Secrets::uuid(), $user]
            );

            // Update slot status
            $this->q("UPDATE hub_slots SET status='OCCUPIED' WHERE id=?", [$slot['id']]);

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'PACKAGE_STAGED','package',?)", [$user, $packageId]);

            return [
                'package_id' => $packageId,
                'package_version' => $newVersion,
                'state' => 'STAGED',
                'slot_code' => $slotCode,
                'slot_id' => (string)$slot['id'],
                'destination_location_id' => (string)$slot['destination_location_id'],
            ];
        });
    }

    // ── Hub Staff: Create dispatch call for drivers ──────────────────

    public function createDispatchCall(string $user, array $input, string $key): array
    {
        Input::fields($input, ['slot_id', 'minutes_to_pickup']);
        $slotId = Input::text($input['slot_id'], 1, 18);
        $minutesToPickup = (int)($input['minutes_to_pickup'] ?? 30);
        if ($minutesToPickup < 5 || $minutesToPickup > 120) {
            throw new Failure(422, 'INVALID_INPUT', 'Pickup window must be 5-120 minutes.');
        }
        Input::text($key, 16, 100);

        return (new Transaction($this->db))->run(function () use ($user, $slotId, $minutesToPickup, $key) {
            $scope = 'hub:' . $this->org() . ':' . $user . ':dispatch';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $hubId = $this->hubId($user);

            // Verify slot exists and has staged packages
            $slot = $this->q(
                "SELECT hs.*, l.name AS destination_name, l.code AS destination_code
                 FROM hub_slots hs
                 JOIN locations l ON l.id=hs.destination_location_id
                 WHERE hs.id=? AND hs.hub_id=? FOR UPDATE",
                [$slotId, $hubId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$slot) { throw new Failure(404, 'SLOT_NOT_FOUND', 'Slot not found.'); }

            // Count staged packages in this slot
            $packageCount = (int)$this->q(
                "SELECT COUNT(*) FROM staging_assignments sa JOIN packages p ON p.id=sa.package_id WHERE sa.slot_id=? AND p.state='STAGED'",
                [$slotId]
            )->fetchColumn();

            if ($packageCount === 0) {
                throw new Failure(409, 'NO_PACKAGES', 'No staged packages in this slot.');
            }

            // Create dispatch call
            $expiresAt = gmdate('Y-m-d H:i:s', time() + ($minutesToPickup * 60));
            $expectedPickup = gmdate('Y-m-d H:i:s', time() + (($minutesToPickup + 15) * 60));

            $callId = $this->insert(
                "INSERT INTO dispatch_calls(hub_id,slot_id,destination_location_id,package_count,status,expires_at,expected_pickup_at) VALUES (?,?,?,?,'PENDING',?,?)",
                [$hubId, $slotId, $slot['destination_location_id'], $packageCount, $expiresAt, $expectedPickup]
            );

            // Update slot status
            $this->q("UPDATE hub_slots SET status='DISPATCHED' WHERE id=?", [$slotId]);

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'DISPATCH_CALLED','dispatch_call',?)", [$user, $callId]);

            return [
                'dispatch_call_id' => $callId,
                'hub_id' => $hubId,
                'slot_id' => $slotId,
                'destination' => $slot['destination_code'],
                'package_count' => $packageCount,
                'status' => 'PENDING',
                'expires_at' => $expiresAt,
                'expected_pickup_at' => $expectedPickup,
            ];
        });
    }

    // ── Driver: List available dispatch calls ────────────────────────

    public function listAvailableCalls(string $user): array
    {
        $this->identity->requireRole($user, 'DRIVER');
        $driverId = $this->driverId($user);

        $rows = $this->q(
            "SELECT dc.id, dc.destination_location_id, dc.package_count, dc.status,
                    dc.called_at, dc.expires_at, dc.expected_pickup_at,
                    l.code AS destination_code, l.name AS destination_name,
                    h.location_id AS hub_location_id, hl.name AS hub_name
             FROM dispatch_calls dc
             JOIN hubs h ON h.id=dc.hub_id
             JOIN locations hl ON hl.id=h.location_id
             JOIN locations l ON l.id=dc.destination_location_id
             WHERE dc.status='PENDING' AND dc.expires_at > now()
             ORDER BY dc.expires_at ASC",
            []
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => array_map(fn($r) => [
            'dispatch_call_id' => (string)$r['id'],
            'hub_name' => $r['hub_name'],
            'destination' => $r['destination_code'],
            'package_count' => (int)$r['package_count'],
            'status' => $r['status'],
            'called_at' => $r['called_at'],
            'expires_at' => $r['expires_at'],
            'expected_pickup_at' => $r['expected_pickup_at'],
        ], $rows)];
    }

    // ── Driver: Accept dispatch call ─────────────────────────────────

    public function acceptDispatch(string $user, string $callId, string $key): array
    {
        $this->identity->requireRole($user, 'DRIVER');
        Input::text($callId, 1, 18);
        Input::text($key, 16, 100);
        $driverId = $this->driverId($user);

        return (new Transaction($this->db))->run(function () use ($user, $driverId, $callId, $key) {
            $scope = 'driver:' . $this->org() . ':' . $user . ':accept-dispatch';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $call = $this->q(
                "SELECT * FROM dispatch_calls WHERE id=? FOR UPDATE",
                [$callId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$call) { throw new Failure(404, 'CALL_NOT_FOUND', 'Dispatch call not found.'); }
            if ($call['status'] !== 'PENDING') {
                throw new Failure(409, 'CALL_NOT_AVAILABLE', 'Call is no longer available. Status: ' . $call['status']);
            }
            if (strtotime($call['expires_at']) <= time()) {
                $this->q("UPDATE dispatch_calls SET status='EXPIRED' WHERE id=?", [$callId]);
                throw new Failure(410, 'CALL_EXPIRED', 'This dispatch call has expired.');
            }

            // Driver confirms — they will come pick up
            $confirmedAt = gmdate('Y-m-d H:i:s');
            $expectedPickup = gmdate('Y-m-d H:i:s', time() + (30 * 60)); // 30 min to arrive

            $this->q(
                "UPDATE dispatch_calls SET status='ACCEPTED', driver_id=?, confirmed_at=?, expected_pickup_at=? WHERE id=?",
                [$driverId, $confirmedAt, $expectedPickup, $callId]
            );

            // Create outbound run for this driver
            $vehicle = $this->q(
                "SELECT vehicle_id FROM driver_shifts WHERE driver_id=? AND ends_at > now() ORDER BY starts_at DESC LIMIT 1",
                [$driverId]
            )->fetchColumn();

            if (!$vehicle) { throw new Failure(409, 'NO_ACTIVE_SHIFT', 'No active shift found.'); }

            $runId = $this->insert(
                "INSERT INTO route_runs(organization_id,hub_id,driver_id,vehicle_id,kind,state,revision,planned_start,planned_end,dispatch_call_id,expected_pickup_at) VALUES (?,?,?,?, 'OUTBOUND','ACKNOWLEDGED',1,now(),now()+interval '6 hours',?,?)",
                [$this->org(), $call['hub_id'], $driverId, $vehicle, $callId, $expectedPickup]
            );

            // Assign staged packages to this run
            $this->q(
                "UPDATE staging_assignments SET outbound_run_id=? WHERE slot_id=? AND package_id IN (SELECT id FROM packages WHERE state='STAGED')",
                [$runId, $call['slot_id']]
            );

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'DISPATCH_ACCEPTED','dispatch_call',?)", [$user, $callId]);

            return [
                'dispatch_call_id' => $callId,
                'status' => 'ACCEPTED',
                'run_id' => $runId,
                'confirmed_at' => $confirmedAt,
                'expected_pickup_at' => $expectedPickup,
            ];
        });
    }

    // ── Driver: Load packages at hub ─────────────────────────────────

    public function loadPackages(string $user, string $callId, string $key): array
    {
        $this->identity->requireRole($user, 'DRIVER');
        Input::text($callId, 1, 18);
        Input::text($key, 16, 100);
        $driverId = $this->driverId($user);

        return (new Transaction($this->db))->run(function () use ($user, $driverId, $callId, $key) {
            $scope = 'driver:' . $this->org() . ':' . $user . ':load';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $call = $this->q(
                "SELECT * FROM dispatch_calls WHERE id=? AND driver_id=? FOR UPDATE",
                [$callId, $driverId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$call) { throw new Failure(404, 'CALL_NOT_FOUND', 'Dispatch call not found for this driver.'); }
            if ($call['status'] !== 'ACCEPTED') {
                throw new Failure(409, 'CALL_NOT_ACCEPTED', 'Call must be accepted before loading.');
            }

            // Find the outbound run
            $run = $this->q(
                "SELECT id FROM route_runs WHERE dispatch_call_id=? AND driver_id=?",
                [$callId, $driverId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$run) { throw new Failure(404, 'RUN_NOT_FOUND', 'Outbound run not found.'); }

            // Get staged packages for this slot
            $packages = $this->q(
                "SELECT sa.package_id, p.version FROM staging_assignments sa JOIN packages p ON p.id=sa.package_id WHERE sa.slot_id=? AND sa.outbound_run_id=? AND p.state='STAGED'",
                [$call['slot_id'], $run['id']]
            )->fetchAll(PDO::FETCH_ASSOC);

            $loadedCount = 0;
            foreach ($packages as $pkg) {
                $newVersion = (int)$pkg['version'] + 1;
                $operationUuid = Secrets::uuid();

                // Transition: STAGED → OUTBOUND_CUSTODY
                $this->q(
                    "UPDATE packages SET state='OUTBOUND_CUSTODY', custodian_type='DRIVER', custodian_ref=?, current_location_id=NULL, version=? WHERE id=?",
                    [$driverId, $newVersion, $pkg['package_id']]
                );

                // Custody event: hub → driver
                $this->q(
                    "INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at) VALUES (?,?,?,?, 'CUSTODY_TRANSFER','HUB',?, 'DRIVER',?, NULL, '{}', now())",
                    [$pkg['package_id'], $operationUuid, $newVersion, $user, $call['hub_id'], $driverId]
                );

                // Scan event
                $this->q(
                    "INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at) VALUES (?,?,?,?, 'OUTBOUND_LOAD','ACCEPTED',now())",
                    [$operationUuid, $pkg['package_id'], $user, $run['id']]
                );

                // Update manifest item
                $this->q(
                    "UPDATE manifest_items SET state='LOADED' WHERE run_id=? AND package_id=?",
                    [$run['id'], $pkg['package_id']]
                );

                $loadedCount++;
            }

            // Record actual pickup time
            $actualPickup = gmdate('Y-m-d H:i:s');
            $this->q(
                "UPDATE dispatch_calls SET actual_pickup_at=?, status='DISPATCHED' WHERE id=?",
                [$actualPickup, $callId]
            );
            $this->q(
                "UPDATE route_runs SET actual_pickup_at=? WHERE id=?",
                [$actualPickup, $run['id']]
            );

            // Clear slot
            $this->q("UPDATE hub_slots SET status='AVAILABLE' WHERE id=?", [$call['slot_id']]);

            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'PACKAGES_LOADED','dispatch_call',?)", [$user, $callId]);

            return [
                'dispatch_call_id' => $callId,
                'run_id' => (string)$run['id'],
                'loaded_count' => $loadedCount,
                'actual_pickup_at' => $actualPickup,
                'status' => 'DISPATCHED',
            ];
        });
    }

    // ── Hub Staff: List slots ────────────────────────────────────────

    public function listSlots(string $user): array
    {
        $hubId = $this->hubId($user);

        $rows = $this->q(
            "SELECT hs.id, hs.code, hs.status, hs.destination_location_id,
                    l.code AS destination_code, l.name AS destination_name,
                    (SELECT COUNT(*) FROM staging_assignments sa JOIN packages p ON p.id=sa.package_id WHERE sa.slot_id=hs.id AND p.state='STAGED') AS staged_count
             FROM hub_slots hs
             JOIN locations l ON l.id=hs.destination_location_id
             WHERE hs.hub_id=?
             ORDER BY hs.code",
            [$hubId]
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => array_map(fn($r) => [
            'slot_id' => (string)$r['id'],
            'code' => $r['code'],
            'status' => $r['status'],
            'destination' => $r['destination_code'],
            'destination_name' => $r['destination_name'],
            'staged_count' => (int)$r['staged_count'],
        ], $rows)];
    }

    // ── Helpers ──────────────────────────────────────────────────────

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

    private function driverId(string $userId): string
    {
        $id = $this->q('SELECT id FROM drivers WHERE user_id=?', [$userId])->fetchColumn();
        if (!$id) { throw new Failure(403, 'ACCESS_DENIED', 'No driver profile found.'); }
        return (string)$id;
    }

    private function insert(string $sql, array $values): string
    {
        $q = $this->db->prepare($sql . ' RETURNING id');
        $q->execute($values);
        return (string)$q->fetchColumn();
    }
}
