<?php
declare(strict_types=1);
namespace Zpx\HubDispatch;

use PDO;
use Zpx\Custody\ScanJournal;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;

/** Hub sorting, dispatch calls and driver pickup workflow.
 *  Flow: Hub receives → Sort to lot → Dispatch call → Driver confirms → Driver loads → Driver delivers */
final class Service
{
    private Identity $identity;
    private ?ScanJournal $journal = null;

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

    private function journal(): ScanJournal { return $this->journal ??= new ScanJournal($this->db); }
    private function rejectStage(string $user, ?string $package, string $code): void { $this->journal()->stage($user, null, $package, 'HUB_STAGE', $code); }

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

        return $this->journal()->transact(function () use ($user, $labelToken, $slotCode, $key) {
            $scope = 'hub:' . $this->org() . ':' . $user . ':stage';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            $hubId = $this->hubId($user);

            // Resolve label
            $tokenHash = hash('sha256', $labelToken);
            $label = $this->q(
                "SELECT pl.package_id, pl.status FROM package_labels pl WHERE pl.token_hash=decode(?,'hex')",
                [$tokenHash]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$label) { $this->rejectStage($user, null, 'LABEL_NOT_FOUND'); throw new Failure(404, 'LABEL_NOT_FOUND', 'Label not recognized.'); }
            if ($label['status'] !== 'ACTIVE') { $this->rejectStage($user, (string)$label['package_id'], 'LABEL_REVOKED'); throw new Failure(410, 'LABEL_REVOKED', 'Label revoked.'); }

            $packageId = (string)$label['package_id'];

            // Check package is AT_HUB
            $package = $this->q(
                "SELECT p.id,p.state,p.version,p.current_location_id,p.custodian_type,p.custodian_ref,
                        s.destination_location_id,h.location_id AS hub_location_id
                 FROM packages p JOIN shipments s ON s.id=p.shipment_id JOIN hubs h ON h.id=?
                 WHERE p.id=? AND s.organization_id=? FOR UPDATE OF p",
                [$hubId, $packageId, $this->org()]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$package) { $this->rejectStage($user, null, 'PACKAGE_NOT_FOUND'); throw new Failure(404, 'PACKAGE_NOT_FOUND', 'Package not found.'); }
            if ($package['state'] !== 'AT_HUB' || $package['custodian_type'] !== 'HUB'
                || (string)$package['custodian_ref'] !== $hubId || (string)$package['current_location_id'] !== (string)$package['hub_location_id']) {
                $this->rejectStage($user, $packageId, 'WRONG_STATE');
                throw new Failure(409, 'WRONG_STATE', 'Package is not at this hub.');
            }

            // Find the slot
            $slot = $this->q(
                "SELECT id, destination_location_id, status FROM hub_slots WHERE hub_id=? AND code=? FOR UPDATE",
                [$hubId, $slotCode]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$slot) { $this->rejectStage($user, $packageId, 'SLOT_NOT_FOUND'); throw new Failure(404, 'SLOT_NOT_FOUND', 'Slot not found at this hub.'); }
            if ((string)$slot['destination_location_id'] !== (string)$package['destination_location_id']) {
                $this->rejectStage($user, $packageId, 'WRONG_DESTINATION');
                throw new Failure(409, 'WRONG_DESTINATION', 'The staging slot does not match the package destination.');
            }

            // Check if already staged
            $existing = $this->q("SELECT id FROM staging_assignments WHERE package_id=?", [$packageId])->fetchColumn();
            if ($existing) { $this->rejectStage($user, $packageId, 'ALREADY_STAGED'); throw new Failure(409, 'ALREADY_STAGED', 'Package already staged.'); }

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
            $this->q("INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,action,result_code,received_at) VALUES (?,?,?,'HUB_STAGE','ACCEPTED',now())", [Secrets::uuid(), $packageId, $user]);

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
             WHERE hl.organization_id=? AND dc.status='PENDING' AND dc.expires_at > now()
             ORDER BY dc.expires_at ASC",
            [$this->org()]
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

    // ── Hub Staff: Dispatch workbench ───────────────────────────────

    public function listDispatchCalls(string $user): array
    {
        $hubId = $this->hubId($user);
        $rows = $this->q(
            "SELECT dc.id,dc.slot_id,dc.package_count,dc.status,dc.called_at,dc.expires_at,
                    dc.confirmed_at,dc.expected_pickup_at,dc.actual_pickup_at,
                    destination.code AS destination,destination.name AS destination_name,
                    hs.code AS slot_code,u.display_name AS driver_name,rr.id AS run_id,
                    (SELECT COUNT(DISTINCT se.package_id) FROM scan_events se
                     WHERE se.run_id=rr.id AND se.action='OUTBOUND_LOAD' AND se.result_code='ACCEPTED') AS loaded_count
             FROM dispatch_calls dc
             JOIN locations destination ON destination.id=dc.destination_location_id
             LEFT JOIN hub_slots hs ON hs.id=dc.slot_id
             LEFT JOIN drivers d ON d.id=dc.driver_id
             LEFT JOIN users u ON u.id=d.user_id
             LEFT JOIN route_runs rr ON rr.dispatch_call_id=dc.id
             WHERE dc.hub_id=? ORDER BY dc.called_at DESC,dc.id DESC LIMIT 100",
            [$hubId]
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => array_map(static fn($r) => [
            'dispatch_call_id' => (string)$r['id'],
            'slot_id' => $r['slot_id'] === null ? null : (string)$r['slot_id'],
            'slot_code' => $r['slot_code'],
            'destination' => $r['destination'],
            'destination_name' => $r['destination_name'],
            'package_count' => (int)$r['package_count'],
            'loaded_count' => (int)$r['loaded_count'],
            'remaining_count' => max(0, (int)$r['package_count'] - (int)$r['loaded_count']),
            'status' => $r['status'],
            'driver_name' => $r['driver_name'],
            'run_id' => $r['run_id'] === null ? null : (string)$r['run_id'],
            'called_at' => gmdate('c', strtotime($r['called_at'])),
            'expires_at' => gmdate('c', strtotime($r['expires_at'])),
            'confirmed_at' => $r['confirmed_at'] === null ? null : gmdate('c', strtotime($r['confirmed_at'])),
            'expected_pickup_at' => $r['expected_pickup_at'] === null ? null : gmdate('c', strtotime($r['expected_pickup_at'])),
            'actual_pickup_at' => $r['actual_pickup_at'] === null ? null : gmdate('c', strtotime($r['actual_pickup_at'])),
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
                "SELECT dc.* FROM dispatch_calls dc JOIN hubs h ON h.id=dc.hub_id JOIN locations l ON l.id=h.location_id WHERE dc.id=? AND l.organization_id=? FOR UPDATE OF dc",
                [$callId, $this->org()]
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

            // Freeze the physical package set into an authoritative, ordered run manifest.
            // A dispatch call currently represents one destination slot, but the manifest model
            // supports multiple ordered stops for planned multi-destination runs.
            $staged = $this->q(
                "SELECT sa.package_id,s.destination_location_id FROM staging_assignments sa
                 JOIN packages p ON p.id=sa.package_id JOIN shipments s ON s.id=p.shipment_id
                 WHERE sa.slot_id=? AND p.state='STAGED' AND p.custodian_type='HUB'
                   AND p.custodian_ref=? AND s.organization_id=? ORDER BY sa.package_id FOR UPDATE OF p",
                [$call['slot_id'], $call['hub_id'], $this->org()]
            )->fetchAll(PDO::FETCH_ASSOC);
            if (!$staged || count($staged) !== (int)$call['package_count']) {
                throw new Failure(409, 'DISPATCH_MANIFEST_CHANGED', 'Staged package set changed; create a new dispatch call.');
            }
            $manifestId = $this->insert("INSERT INTO manifests(run_id,revision,state) VALUES (?,1,'ACTIVE')", [$runId]);
            $stopByDestination = [];
            foreach ($staged as $pkg) {
                $destination = (string)$pkg['destination_location_id'];
                if (!isset($stopByDestination[$destination])) {
                    $stopByDestination[$destination] = $this->insert(
                        "INSERT INTO route_run_stops(run_id,location_id,sequence_no,state) VALUES (?,?,?,'EXPECTED')",
                        [$runId, $destination, count($stopByDestination) + 1]
                    );
                }
                $this->insert(
                    "INSERT INTO manifest_items(manifest_id,run_id,package_id,stop_id,state) VALUES (?,?,?,?,'EXPECTED')",
                    [$manifestId, $runId, $pkg['package_id'], $stopByDestination[$destination]]
                );
            }

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
