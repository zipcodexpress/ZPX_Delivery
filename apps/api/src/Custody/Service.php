<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Infrastructure\Messaging\Outbox;

/** Custody transfers are physical events. Notification/printing failure never reverses a transfer. */
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

    private function requireDriver(string $user): void
    {
        $this->identity->requireRole($user, 'DRIVER');
    }

    /** Resolution precedes a scan for driver pickup and for independent hub receiving. */
    private function requireResolveAccess(string $user): void
    {
        try { $this->identity->requireRole($user, 'DRIVER'); return; }
        catch (Failure) {}

        // HUB_STAFF grants are scoped to a hub location, so the grant must be matched there.
        $location = $this->q(
            'SELECT h.location_id FROM hub_staff hs
             JOIN hubs h ON h.id=hs.hub_id
             JOIN locations l ON l.id=h.location_id
             WHERE hs.user_id=? AND l.organization_id=?',
            [$user, $this->org()]
        )->fetchColumn();
        if (!$location) { throw new Failure(403, 'ACCESS_DENIED', 'Access denied.'); }
        $this->identity->requireRole($user, 'HUB_STAFF', (string)$location);
    }

    private function driverId(string $userId): string
    {
        $id = $this->q('SELECT id FROM drivers WHERE user_id=?', [$userId])->fetchColumn();
        if (!$id) { throw new Failure(403, 'ACCESS_DENIED', 'No driver profile found.'); }
        return (string)$id;
    }

    private function developmentFixtureToken(array $item): ?string
    {
        if (!in_array(getenv('APP_ENV'), ['development', 'test'], true)
            || !in_array($item['development_only'], [true, 't', '1', 1], true)) { return null; }
        for ($i = 1; $i <= 5; $i++) {
            $token = sprintf('TEST-LABEL-%03d', $i);
            if (hash_equals(hash('sha256', $token), (string)$item['token_hash'])) { return $token; }
        }
        return null;
    }

    public function listRuns(string $user): array
    {
        $this->requireDriver($user);
        $driverId = $this->driverId($user);
        $rows = $this->q(
            "SELECT r.id, r.kind, r.state, r.revision, r.planned_start, r.planned_end, r.departed_at,
                    l.name AS hub_name, v.code AS vehicle_code,
                    (SELECT COUNT(*) FROM manifest_items mi WHERE mi.run_id=r.id AND mi.state='EXPECTED') AS expected_count,
                    (SELECT COUNT(*) FROM manifest_items mi WHERE mi.run_id=r.id AND mi.state='LOADED') AS loaded_count
             FROM route_runs r
             JOIN hubs h ON h.id=r.hub_id
             JOIN locations l ON l.id=h.location_id
             JOIN vehicles v ON v.id=r.vehicle_id
             WHERE r.driver_id=? AND r.organization_id=?
             ORDER BY r.planned_start DESC",
            [$driverId, $this->org()]
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => array_map(fn(array $r) => [
            'id' => (string)$r['id'],
            'kind' => $r['kind'],
            'state' => $r['state'],
            'revision' => (int)$r['revision'],
            'hub' => $r['hub_name'],
            'vehicle' => $r['vehicle_code'],
            'planned_start' => $r['planned_start'],
            'planned_end' => $r['planned_end'],
            'departed_at' => $r['departed_at'],
            'expected_count' => (int)$r['expected_count'],
            'loaded_count' => (int)$r['loaded_count'],
        ], $rows)];
    }

    public function getRun(string $user, string $runId): array
    {
        $this->requireDriver($user);
        Input::text($runId, 1, 18);
        $driverId = $this->driverId($user);

        $run = $this->q(
            "SELECT r.*, h.location_id AS hub_location_id, l.name AS hub_name
             FROM route_runs r
             JOIN hubs h ON h.id=r.hub_id
             JOIN locations l ON l.id=h.location_id
             WHERE r.id=? AND r.driver_id=? AND r.organization_id=?",
            [$runId, $driverId, $this->org()]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$run) { throw new Failure(404, 'RUN_NOT_FOUND', 'Run not found or not assigned to you.'); }

        $stops = $this->q(
            "SELECT rs.id, rs.sequence_no, rs.state, l.name AS location_name, l.code AS location_code, l.address_text
             FROM route_run_stops rs
             JOIN locations l ON l.id=rs.location_id
             WHERE rs.run_id=?
             ORDER BY rs.sequence_no",
            [$runId]
        )->fetchAll(PDO::FETCH_ASSOC);

        $items = $this->q(
            "SELECT mi.id AS manifest_item_id, mi.state, mi.package_id, p.package_uuid, p.state AS package_state,
                    p.custodian_type, p.custodian_ref, p.version AS package_version,
                    s.public_reference, s.development_only, si.si, encode(pl.token_hash,'hex') AS token_hash,
                    dest.name AS destination_name, dest.code AS destination_code,
                    rs.sequence_no AS stop_sequence
             FROM manifest_items mi
             JOIN packages p ON p.id=mi.package_id
             JOIN shipments s ON s.id=p.shipment_id
             LEFT JOIN package_labels pl ON pl.package_id=p.id AND pl.status='ACTIVE'
             LEFT JOIN shipping_identifiers si ON si.package_id=p.id
             JOIN locations dest ON dest.id=s.destination_location_id
             JOIN route_run_stops rs ON rs.id=mi.stop_id
             WHERE mi.run_id=?
             ORDER BY rs.sequence_no, mi.id",
            [$runId]
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'id' => (string)$run['id'],
            'kind' => $run['kind'],
            'state' => $run['state'],
            'revision' => (int)$run['revision'],
            'hub' => $run['hub_name'],
            'planned_start' => $run['planned_start'],
            'planned_end' => $run['planned_end'],
            'departed_at' => $run['departed_at'],
            'stops' => array_map(fn(array $s) => [
                'id' => (string)$s['id'],
                'sequence' => (int)$s['sequence_no'],
                'state' => $s['state'],
                'location' => ['name' => $s['location_name'], 'code' => $s['location_code'], 'address' => $s['address_text']],
            ], $stops),
            'manifest' => array_map(function (array $m): array {
                $item = [
                    'manifest_item_id' => (string)$m['manifest_item_id'],
                    'package_id' => (string)$m['package_id'],
                    'package_uuid' => $m['package_uuid'],
                    'public_reference' => $m['public_reference'],
                    'si' => $m['si'],
                    'state' => $m['state'],
                    'package_state' => $m['package_state'],
                    'custodian_type' => $m['custodian_type'],
                    'custodian_ref' => $m['custodian_ref'],
                    'package_version' => (int)$m['package_version'],
                    'destination' => ['name' => $m['destination_name'], 'code' => $m['destination_code']],
                    'stop_sequence' => (int)$m['stop_sequence'],
                ];
                $token = $this->developmentFixtureToken($m);
                if ($token !== null) { $item['development_label_token'] = $token; }
                return $item;
            }, $items),
        ];
    }

    public function acknowledgeRun(string $user, string $runId, string $key): array
    {
        $this->requireDriver($user);
        Input::text($runId, 1, 18);
        Input::text($key, 16, 100);
        $driverId = $this->driverId($user);

        return (new Transaction($this->db))->run(function () use ($user, $driverId, $runId, $key) {
            $scope = 'custody:' . $this->org() . ':' . $user . ':acknowledge';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            if (!$this->q("SELECT id FROM users WHERE id=? AND organization_id=? AND status='ACTIVE'", [$user, $this->org()])->fetchColumn()) {
                throw new Failure(403, 'ACCESS_DENIED', 'Access denied.');
            }

            $hash = $this->crypto->digest('acknowledge-request', json_encode(['run_id' => $runId], JSON_THROW_ON_ERROR));
            $saved = $this->q(
                "SELECT encode(payload_hash,'hex') AS hash, response_body FROM idempotency_records WHERE scope=? AND request_key=?",
                [$scope, $key]
            )->fetch(PDO::FETCH_ASSOC);

            if ($saved) {
                if (!hash_equals($saved['hash'], $hash)) {
                    throw new Failure(409, 'IDEMPOTENCY_CONFLICT', 'This request key was already used for different details.');
                }
                return json_decode($saved['response_body'], true, 512, JSON_THROW_ON_ERROR);
            }

            $run = $this->q(
                "SELECT id, state, revision FROM route_runs WHERE id=? AND driver_id=? AND organization_id=? FOR UPDATE",
                [$runId, $driverId, $this->org()]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$run) { throw new Failure(404, 'RUN_NOT_FOUND', 'Run not found or not assigned to you.'); }
            if ($run['state'] !== 'PUBLISHED') {
                throw new Failure(409, 'RUN_NOT_ACKNOWLEDGEABLE', 'Run is not in PUBLISHED state.');
            }

            $this->q("UPDATE route_runs SET state='ACKNOWLEDGED' WHERE id=?", [$runId]);
            $this->q(
                "INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'RUN_ACKNOWLEDGED','run',?)",
                [$user, $runId]
            );

            $result = ['id' => $runId, 'state' => 'ACKNOWLEDGED', 'revision' => (int)$run['revision']];

            $this->q(
                "INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",
                [$scope, $key, $hash, json_encode($result, JSON_THROW_ON_ERROR)]
            );

            return $result;
        });
    }

    public function resolveScan(string $user, string $labelToken, string $action = 'INSPECT', ?string $runId = null): array
    {
        $this->requireResolveAccess($user);
        Input::text($labelToken, 1, 500);
        if (!in_array($action, ['INSPECT','INBOUND_PICKUP','HUB_RECEIVE','STAGE','OUTBOUND_LOAD','FINAL_DEPOSIT'], true)) {
            throw new Failure(422, 'INVALID_ACTION', 'Unsupported scan action.');
        }
        if ($runId !== null) { Input::text($runId, 1, 18); }

        $tokenHash = hash('sha256', $labelToken);
        $label = $this->q(
            "SELECT pl.id AS label_id, pl.package_id, pl.status, p.state AS package_state,
                    p.custodian_type,p.custodian_ref,p.current_location_id,p.version AS package_version,
                    s.id AS shipment_id,s.origin_location_id,s.destination_location_id,si.si
             FROM package_labels pl
             JOIN packages p ON p.id=pl.package_id
             JOIN shipments s ON s.id=p.shipment_id
             LEFT JOIN shipping_identifiers si ON si.package_id=p.id
             WHERE pl.token_hash=decode(?,'hex') AND s.organization_id=?",
            [$tokenHash, $this->org()]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$label) { throw new Failure(404, 'LABEL_NOT_FOUND', 'Label not recognized.'); }
        if ($label['status'] !== 'ACTIVE') { throw new Failure(410, 'LABEL_REVOKED', 'This label is no longer active.'); }

        $profile=$this->identity->profile($user); $roles=$profile['roles']; $package=(string)$label['package_id'];
        $allowed=['INSPECT'];
        $driverId=in_array('DRIVER',$roles,true)?$this->q('SELECT id FROM drivers WHERE user_id=?',[$user])->fetchColumn():false;
        $hubId=false;
        if (array_intersect($roles,['HUB_STAFF','HUB_SUPERVISOR'])) {
            $hubId=$this->q('SELECT hs.hub_id FROM hub_staff hs JOIN hubs h ON h.id=hs.hub_id JOIN locations l ON l.id=h.location_id WHERE hs.user_id=? AND l.organization_id=?',[$user,$this->org()])->fetchColumn();
        }
        if ($driverId && $runId !== null && $label['package_state']==='AT_ORIGIN'
            && $this->q("SELECT 1 FROM route_runs r JOIN manifest_items mi ON mi.run_id=r.id WHERE r.id=? AND r.driver_id=? AND r.organization_id=? AND r.kind='INBOUND' AND mi.package_id=?",[$runId,$driverId,$this->org(),$package])->fetchColumn()) { $allowed[]='INBOUND_PICKUP'; }
        if ($hubId && $runId !== null && $label['package_state']==='INBOUND_CUSTODY'
            && $this->q("SELECT 1 FROM route_runs r JOIN manifest_items mi ON mi.run_id=r.id WHERE r.id=? AND r.hub_id=? AND r.organization_id=? AND r.kind='INBOUND' AND mi.package_id=?",[$runId,$hubId,$this->org(),$package])->fetchColumn()) { $allowed[]='HUB_RECEIVE'; }
        if ($hubId && $label['package_state']==='AT_HUB' && $label['custodian_type']==='HUB' && (string)$label['custodian_ref']===(string)$hubId) { $allowed[]='STAGE'; }
        if ($driverId && $runId !== null && $label['package_state']==='STAGED'
            && $this->q('SELECT 1 FROM route_runs r JOIN staging_assignments sa ON sa.outbound_run_id=r.id WHERE r.id=? AND r.driver_id=? AND r.organization_id=? AND sa.package_id=?',[$runId,$driverId,$this->org(),$package])->fetchColumn()) { $allowed[]='OUTBOUND_LOAD'; }
        if ($action!=='INSPECT' && !in_array($action,$allowed,true)) { throw new Failure(409,'SCAN_ACTION_NOT_ALLOWED','This package is not eligible for the requested scan action.'); }

        return [
            'package_id' => $package,
            'si' => $label['si'],
            'destination_location_id' => (string)$label['destination_location_id'],
            'state' => $label['package_state'],
            'version' => (int)$label['package_version'],
            'allowed_actions' => $allowed,
        ];
    }

    public function inboundPickupScan(string $user, string $runId, array $input, string $key, string $match = ''): array
    {
        $this->requireDriver($user);
        Input::text($runId, 1, 18);
        Input::fields($input, ['label_payload','action','client_event_id','run_revision','expected_package_version'], ['client_occurred_at']);
        Input::text($key, 16, 100);
        $labelToken = Input::text($input['label_payload'], 1, 500);
        if ($input['action']!=='INBOUND_PICKUP') { throw new Failure(422,'INVALID_ACTION','This run supports inbound pickup scans.'); }
        $clientEvent=Input::text($input['client_event_id'],36,36);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$clientEvent)) { throw new Failure(422,'INVALID_INPUT','client_event_id must be a UUID.'); }
        $runRevision=(int)$input['run_revision']; $expectedVersion=(int)$input['expected_package_version'];
        if ($runRevision<1 || $expectedVersion<0) { throw new Failure(422,'INVALID_INPUT','Scan revisions are invalid.'); }
        if ($match!=='' && $match!=='"'.$runRevision.'"') { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision precondition does not match.'); }
        $driverId = $this->driverId($user);

        return $this->journal()->transact(function () use ($user, $driverId, $runId, $input, $key, $labelToken, $clientEvent, $runRevision, $expectedVersion) {
            $scope = 'custody:' . $this->org() . ':' . $user . ':inbound-pickup';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$scope . ':' . $key]);

            if (!$this->q("SELECT id FROM users WHERE id=? AND organization_id=? AND status='ACTIVE'", [$user, $this->org()])->fetchColumn()) {
                throw new Failure(403, 'ACCESS_DENIED', 'Access denied.');
            }

            $hash = $this->crypto->digest('inbound-pickup', json_encode([
                'run_id' => $runId,
                'label_payload' => $labelToken,'client_event_id'=>$clientEvent,
                'run_revision'=>$runRevision,'expected_package_version'=>$expectedVersion,
            ], JSON_THROW_ON_ERROR));

            $saved = $this->q(
                "SELECT encode(payload_hash,'hex') AS hash, response_body FROM idempotency_records WHERE scope=? AND request_key=?",
                [$scope, $key]
            )->fetch(PDO::FETCH_ASSOC);

            if ($saved) {
                if (!hash_equals($saved['hash'], $hash)) {
                    throw new Failure(409, 'IDEMPOTENCY_CONFLICT', 'This request key was already used for different details.');
                }
                return json_decode($saved['response_body'], true, 512, JSON_THROW_ON_ERROR);
            }

            // Validate run exists and is assigned to this driver
            $run = $this->q(
                "SELECT id, state, revision FROM route_runs WHERE id=? AND driver_id=? AND organization_id=? FOR UPDATE",
                [$runId, $driverId, $this->org()]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$run) { throw new Failure(404, 'RUN_NOT_FOUND', 'Run not found or not assigned to you.'); }
            if (!in_array($run['state'], ['ACKNOWLEDGED', 'IN_PROGRESS'], true)) {
                throw new Failure(409, 'RUN_NOT_ACTIVE', 'Run must be acknowledged before scanning.');
            }
            if ((int)$run['revision']!==$runRevision) { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision changed. Refresh before scanning.'); }

            // Resolve label
            $tokenHash = hash('sha256', $labelToken);
            $label = $this->q(
                "SELECT pl.id AS label_id,pl.package_id,pl.status,p.version AS package_version,si.si
                 FROM package_labels pl
                 JOIN packages p ON p.id=pl.package_id
                 LEFT JOIN shipping_identifiers si ON si.package_id=p.id
                 WHERE pl.token_hash=decode(?,'hex')",
                [$tokenHash]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$label) {
                $this->recordRejectedScan($user, $runId, null, 'INBOUND_PICKUP', 'LABEL_NOT_FOUND');
                throw new Failure(404, 'LABEL_NOT_FOUND', 'Label not recognized.');
            }
            if ($label['status'] !== 'ACTIVE') {
                $this->recordRejectedScan($user, $runId, (string)$label['package_id'], 'INBOUND_PICKUP', 'LABEL_REVOKED');
                throw new Failure(410, 'LABEL_REVOKED', 'This label is no longer active.');
            }

            $packageId = (string)$label['package_id'];
            if ((int)$label['package_version']!==$expectedVersion) { $this->recordRejectedScan($user,$runId,$packageId,'INBOUND_PICKUP','VERSION_CONFLICT'); throw new Failure(409,'VERSION_CONFLICT','Package version changed. Resolve the label again.'); }

            // Lock package and check state
            $package = $this->q(
                "SELECT p.id, p.state, p.version, p.custodian_type, p.custodian_ref, p.current_location_id,
                        s.origin_location_id
                 FROM packages p
                 JOIN shipments s ON s.id=p.shipment_id
                 WHERE p.id=? FOR UPDATE",
                [$packageId]
            )->fetch(PDO::FETCH_ASSOC);

            if ($package['state'] !== 'AT_ORIGIN') {
                $this->recordRejectedScan($user, $runId, $packageId, 'INBOUND_PICKUP', 'WRONG_STATE');
                throw new Failure(409, 'WRONG_PACKAGE_STATE', 'Package is not at origin. Current state: ' . $package['state']);
            }

            // Check package is in this run's manifest
            $manifestItem = $this->q(
                "SELECT mi.id,mi.state,rrs.sequence_no AS stop_sequence FROM manifest_items mi JOIN route_run_stops rrs ON rrs.id=mi.stop_id AND rrs.run_id=mi.run_id WHERE mi.run_id=? AND mi.package_id=?",
                [$runId, $packageId]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$manifestItem) {
                $this->recordRejectedScan($user, $runId, $packageId, 'INBOUND_PICKUP', 'NOT_ON_MANIFEST');
                throw new Failure(409, 'NOT_ON_MANIFEST', 'This package is not on your run manifest.');
            }
            if ($manifestItem['state'] === 'LOADED') {
                // Already scanned — idempotent if same context
                $this->recordRejectedScan($user, $runId, $packageId, 'INBOUND_PICKUP', 'ALREADY_LOADED');
                throw new Failure(409, 'ALREADY_LOADED', 'Package already scanned for this run.');
            }

            // Perform custody transfer
            $operationUuid = $clientEvent;
            $newVersion = (int)$package['version'] + 1;

            // Update package state and custody
            $this->q(
                "UPDATE packages SET state='INBOUND_CUSTODY', custodian_type='DRIVER', custodian_ref=?, current_location_id=NULL, version=? WHERE id=?",
                [$driverId, $newVersion, $packageId]
            );

            // Record scan event
            $this->q(
                "INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at,client_occurred_at) VALUES (?,?,?,?, 'INBOUND_PICKUP','ACCEPTED',now(),?)",
                [$operationUuid, $packageId, $user, $runId, $input['client_occurred_at'] ?? null]
            );

            // Record custody event
            $this->q(
                "INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at) VALUES (?,?,?,?, 'CUSTODY_TRANSFER',?,?, 'DRIVER',?, NULL, '{}', now())",
                [$packageId, $operationUuid, $newVersion, $user, $package['custodian_type'], $package['custodian_ref'], $driverId]
            );

            // Record package event
            $this->q(
                "INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?, 'INBOUND_PICKUP',?, '{}', now())",
                [$packageId, Secrets::uuid(), $user]
            );

            // Update manifest item
            $this->q("UPDATE manifest_items SET state='LOADED' WHERE id=?", [$manifestItem['id']]);

            // Outbox event
            (new Outbox($this->db))->append(
                Secrets::uuid(),
                'package',
                $packageId,
                'custody.inbound_pickup',
                ['package_id' => $packageId, 'run_id' => $runId, 'driver_id' => $driverId, 'new_version' => $newVersion]
            );

            // Audit
            $this->q(
                "INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'INBOUND_PICKUP','package',?)",
                [$user, $packageId]
            );

            // Count accepted items for this run
            $loadedCount = (int)$this->q(
                "SELECT COUNT(*) FROM manifest_items WHERE run_id=? AND state='LOADED'",
                [$runId]
            )->fetchColumn();
            $expectedCount = (int)$this->q(
                "SELECT COUNT(*) FROM manifest_items WHERE run_id=?",
                [$runId]
            )->fetchColumn();

            $result = [
                'result' => 'ACCEPTED',
                'package_id' => $packageId,
                'si' => $label['si'],
                'final_location_id' => (string)$package['origin_location_id'],
                'package_version' => $newVersion,
                'run_revision' => (int)$run['revision'],
                'stop_sequence' => (int)$manifestItem['stop_sequence'],
                'counts' => ['expected'=>$expectedCount,'accepted'=>$loadedCount,'pending'=>max(0,$expectedCount-$loadedCount)],
                'can_depart' => $loadedCount===$expectedCount,
            ];

            // Save idempotency record
            $this->q(
                "INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",
                [$scope, $key, $hash, json_encode($result, JSON_THROW_ON_ERROR)]
            );

            return $result;
        });
    }

    public function outboundLoadScan(string $user, string $runId, array $input, string $key, string $match = ''): array
    {
        $this->requireDriver($user);
        Input::text($runId, 1, 18);
        Input::fields($input, ['label_payload','action','client_event_id','run_revision','expected_package_version'], ['client_occurred_at']);
        Input::text($key, 16, 100);
        if ($input['action'] !== 'OUTBOUND_LOAD') { throw new Failure(422, 'INVALID_ACTION', 'This run supports outbound load scans.'); }
        $labelToken=Input::text($input['label_payload'],1,500); $clientEvent=Input::text($input['client_event_id'],36,36);
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$clientEvent)) { throw new Failure(422,'INVALID_INPUT','client_event_id must be a UUID.'); }
        $runRevision=(int)$input['run_revision']; $expectedVersion=(int)$input['expected_package_version'];
        if ($runRevision<1 || $expectedVersion<0) { throw new Failure(422,'INVALID_INPUT','Scan revisions are invalid.'); }
        if ($match!=='' && $match!=='"'.$runRevision.'"') { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision precondition does not match.'); }
        $driverId=$this->driverId($user);

        return $this->journal()->transact(function () use ($user,$driverId,$runId,$input,$key,$labelToken,$clientEvent,$runRevision,$expectedVersion) {
            $scope='custody:'.$this->org().':'.$user.':outbound-load';
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('outbound-load',json_encode(['run_id'=>$runId,'label_payload'=>$labelToken,'client_event_id'=>$clientEvent,'run_revision'=>$runRevision,'expected_package_version'=>$expectedVersion],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) { if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was already used for different details.'); } return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR); }

            $run=$this->q("SELECT r.id,r.state,r.revision,r.hub_id,r.departed_at,h.location_id FROM route_runs r JOIN hubs h ON h.id=r.hub_id WHERE r.id=? AND r.driver_id=? AND r.organization_id=? AND r.kind='OUTBOUND' FOR UPDATE OF r",[$runId,$driverId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$run) { throw new Failure(404,'RUN_NOT_FOUND','Outbound run not found or not assigned to you.'); }
            if (!in_array($run['state'],['ACKNOWLEDGED','IN_PROGRESS'],true) || $run['departed_at'] !== null) { throw new Failure(409,'RUN_NOT_LOADABLE','Run is not accepting load scans.'); }
            if ((int)$run['revision']!==$runRevision) { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision changed. Refresh before scanning.'); }

            $label=$this->q("SELECT pl.package_id,pl.status,p.version,si.si FROM package_labels pl JOIN packages p ON p.id=pl.package_id JOIN shipments s ON s.id=p.shipment_id LEFT JOIN shipping_identifiers si ON si.package_id=p.id WHERE pl.token_hash=decode(?,'hex') AND s.organization_id=?",[hash('sha256',$labelToken),$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$label) { $this->recordRejectedScan($user,$runId,null,'OUTBOUND_LOAD','LABEL_NOT_FOUND'); throw new Failure(404,'LABEL_NOT_FOUND','Label not recognized.'); }
            $packageId=(string)$label['package_id'];
            if ($label['status']!=='ACTIVE') { $this->recordRejectedScan($user,$runId,$packageId,'OUTBOUND_LOAD','LABEL_REVOKED'); throw new Failure(410,'LABEL_REVOKED','Label revoked.'); }
            if ((int)$label['version']!==$expectedVersion) { $this->recordRejectedScan($user,$runId,$packageId,'OUTBOUND_LOAD','VERSION_CONFLICT'); throw new Failure(409,'VERSION_CONFLICT','Package version changed. Resolve the label again.'); }

            $item=$this->q("SELECT mi.id,mi.state,rs.sequence_no,p.state AS package_state,p.version,p.custodian_type,p.custodian_ref,p.current_location_id,s.destination_location_id FROM manifest_items mi JOIN route_run_stops rs ON rs.id=mi.stop_id AND rs.run_id=mi.run_id JOIN packages p ON p.id=mi.package_id JOIN shipments s ON s.id=p.shipment_id WHERE mi.run_id=? AND mi.package_id=? FOR UPDATE OF p,mi",[$runId,$packageId])->fetch(PDO::FETCH_ASSOC);
            if (!$item) { $this->recordRejectedScan($user,$runId,$packageId,'OUTBOUND_LOAD','NOT_ON_MANIFEST'); throw new Failure(409,'NOT_ON_MANIFEST','This package is not on your run manifest.'); }
            if ($item['state']==='LOADED' || $item['package_state']==='OUTBOUND_CUSTODY') { $this->recordRejectedScan($user,$runId,$packageId,'OUTBOUND_LOAD','ALREADY_LOADED'); throw new Failure(409,'ALREADY_LOADED','Package already loaded.'); }
            if ($item['package_state']!=='STAGED' || $item['custodian_type']!=='HUB' || (string)$item['custodian_ref']!==(string)$run['hub_id'] || (string)$item['current_location_id']!==(string)$run['location_id']) { $this->recordRejectedScan($user,$runId,$packageId,'OUTBOUND_LOAD','WRONG_HUB_CUSTODY'); throw new Failure(409,'WRONG_HUB_CUSTODY','Package is not staged in custody of this run hub.'); }

            $newVersion=(int)$item['version']+1;
            $this->q("UPDATE packages SET state='OUTBOUND_CUSTODY',custodian_type='DRIVER',custodian_ref=?,current_location_id=NULL,version=? WHERE id=?",[$driverId,$newVersion,$packageId]);
            $this->q("UPDATE manifest_items SET state='LOADED' WHERE id=?",[$item['id']]);
            $this->q("INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at,client_occurred_at) VALUES (?,?,?,?, 'OUTBOUND_LOAD','ACCEPTED',now(),?)",[$clientEvent,$packageId,$user,$runId,$input['client_occurred_at']??null]);
            $this->q("INSERT INTO custody_events(package_id,operation_uuid,package_version,actor_user_id,event_type,previous_custodian_type,previous_custodian_ref,new_custodian_type,new_custodian_ref,location_id,evidence,occurred_at) VALUES (?,?,?,?, 'CUSTODY_TRANSFER','HUB',?,'DRIVER',?,?,'{}',now())",[$packageId,$clientEvent,$newVersion,$user,$run['hub_id'],$driverId,$run['location_id']]);
            $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at) VALUES (?,?,'OUTBOUND_LOAD',?,'{}',now())",[$packageId,Secrets::uuid(),$user]);
            (new Outbox($this->db))->append(Secrets::uuid(),'package',$packageId,'custody.outbound_load',['package_id'=>$packageId,'run_id'=>$runId,'driver_id'=>$driverId,'new_version'=>$newVersion]);

            $counts=$this->q("SELECT COUNT(*) AS expected,COUNT(*) FILTER (WHERE state='LOADED') AS accepted FROM manifest_items WHERE run_id=?",[$runId])->fetch(PDO::FETCH_ASSOC);
            $expected=(int)$counts['expected']; $accepted=(int)$counts['accepted'];
            $result=['result'=>'ACCEPTED','package_id'=>$packageId,'si'=>$label['si'],'final_location_id'=>(string)$item['destination_location_id'],'package_version'=>$newVersion,'run_revision'=>(int)$run['revision'],'stop_sequence'=>(int)$item['sequence_no'],'counts'=>['expected'=>$expected,'accepted'=>$accepted,'pending'=>$expected-$accepted],'can_depart'=>$expected>0&&$accepted===$expected];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    public function departRun(string $user,string $runId,array $input,string $key,string $match=''): array
    {
        $this->requireDriver($user); Input::text($runId,1,18); Input::fields($input,['expected_revision']); Input::text($key,16,100);
        $revision=(int)$input['expected_revision']; if ($revision<1) { throw new Failure(422,'INVALID_INPUT','expected_revision must be positive.'); }
        if ($match!=='' && $match!=='"'.$revision.'"') { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision precondition does not match.'); }
        $driverId=$this->driverId($user);
        return (new Transaction($this->db))->run(function () use ($user,$driverId,$runId,$key,$revision) {
            $scope='custody:'.$this->org().':'.$user.':depart'; $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('depart-run',json_encode(['run_id'=>$runId,'expected_revision'=>$revision],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) { if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was already used for different details.'); } return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR); }
            $run=$this->q("SELECT * FROM route_runs WHERE id=? AND driver_id=? AND organization_id=? AND kind='OUTBOUND' FOR UPDATE",[$runId,$driverId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$run) { throw new Failure(404,'RUN_NOT_FOUND','Outbound run not found or not assigned to you.'); }
            if ((int)$run['revision']!==$revision) { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision changed. Refresh before departure.'); }
            if ($run['departed_at']!==null) { throw new Failure(409,'ALREADY_DEPARTED','Run has already departed.'); }
            $counts=$this->q("SELECT COUNT(*) AS expected,COUNT(*) FILTER (WHERE mi.state='LOADED' AND p.state='OUTBOUND_CUSTODY' AND p.custodian_type='DRIVER' AND p.custodian_ref=?) AS valid_loaded FROM manifest_items mi JOIN packages p ON p.id=mi.package_id WHERE mi.run_id=?",[$driverId,$runId])->fetch(PDO::FETCH_ASSOC);
            if ((int)$counts['expected']===0 || (int)$counts['valid_loaded']!==(int)$counts['expected']) { throw new Failure(409,'DEPARTURE_BLOCKED','Every unique expected package must be loaded in this driver custody before departure.'); }
            $departed=gmdate('Y-m-d H:i:s'); $this->q("UPDATE route_runs SET state='IN_PROGRESS',departed_at=? WHERE id=?",[$departed,$runId]);
            $this->q("UPDATE dispatch_calls SET status='DISPATCHED',actual_pickup_at=COALESCE(actual_pickup_at,?) WHERE id=?",[$departed,$run['dispatch_call_id']]);
            $this->q("UPDATE hub_slots SET status='AVAILABLE' WHERE id=(SELECT slot_id FROM dispatch_calls WHERE id=?)",[$run['dispatch_call_id']]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'RUN_DEPARTED','run',?)",[$user,$runId]);
            $result=['run_id'=>$runId,'kind'=>'OUTBOUND','driver_id'=>$driverId,'hub_id'=>(string)$run['hub_id'],'revision'=>$revision,'state'=>'IN_PROGRESS','stops'=>$this->runStops($runId),'expected_count'=>(int)$counts['expected'],'loaded_count'=>(int)$counts['valid_loaded'],'departed_at'=>$departed];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    private function runStops(string $runId): array
    {
        $rows=$this->q("SELECT rs.id,rs.sequence_no,rs.location_id,rs.state,COALESCE(array_agg(mi.package_id::text ORDER BY mi.id) FILTER (WHERE mi.id IS NOT NULL),'{}') AS package_ids FROM route_run_stops rs LEFT JOIN manifest_items mi ON mi.stop_id=rs.id AND mi.run_id=rs.run_id WHERE rs.run_id=? GROUP BY rs.id ORDER BY rs.sequence_no",[$runId])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn($r)=>['stop_id'=>(string)$r['id'],'sequence'=>(int)$r['sequence_no'],'location_id'=>(string)$r['location_id'],'package_ids'=>$this->pgArray((string)$r['package_ids']),'state'=>$r['state']],$rows);
    }

    private function pgArray(string $value): array { return $value==='{}'?[]:str_getcsv(trim($value,'{}')); }

    private function recordRejectedScan(string $user, string $runId, ?string $packageId, string $action, string $resultCode): void
    {
        $this->journal()->stage($user, $runId, $packageId, $action, $resultCode);
    }
}
