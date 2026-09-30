<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;

/** Releases an entirely uncollected offer run; custody itself never changes here. */
final class PickupRecovery
{
    private Identity $identity;
    public function __construct(private PDO $db, private Secrets $crypto) {
        $this->identity = new Identity($db, $crypto);
    }
    private function q(string $sql, array $args=[]): \PDOStatement {
        $q=$this->db->prepare($sql); $q->execute($args); return $q;
    }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }

    public function list(string $user,string $search='',string $status=''): array {
        $this->identity->requireRole($user,'ADMIN');
        $where=''; $args=[$this->org()];
        if ($search!=='') { $where.=' AND (u.display_name ILIKE ? OR r.id::text=?)'; array_push($args,'%'.$search.'%',$search); }
        if (in_array($status,['PUBLISHED','ACKNOWLEDGED','IN_PROGRESS'],true)) { $where.=' AND r.state=?'; $args[]=$status; }
        $rows=$this->q("SELECT r.id,r.state,r.revision,r.planned_end,u.display_name AS driver_name,
                COUNT(mi.id) AS package_count,
                COUNT(mi.id) FILTER (WHERE mi.state='LOADED') AS collected_count,
                COUNT(mi.id) FILTER (WHERE mi.state='RELEASED') AS released_count,
                COUNT(mi.id) FILTER (WHERE mi.state='EXPECTED' AND p.state='AT_ORIGIN'
                    AND p.custodian_type='LOCKER' AND p.current_location_id=pd.origin_location_id
                    AND pd.status='ASSIGNED' AND pd.assigned_run_id=r.id
                    AND a.manifest_item_id=mi.id
                    AND NOT EXISTS(SELECT 1 FROM receiving_sessions rs WHERE rs.inbound_run_id=r.id)
                    AND NOT EXISTS(SELECT 1 FROM scan_events se WHERE se.run_id=r.id AND se.action='INBOUND_PICKUP' AND se.result_code='ACCEPTED')
                    AND (SELECT COUNT(*) FROM manifests m WHERE m.run_id=r.id AND m.state='ACTIVE')=1) AS releasable_count
            FROM inbound_offer_runs ir JOIN route_runs r ON r.id=ir.run_id
            JOIN drivers d ON d.id=r.driver_id JOIN users u ON u.id=d.user_id
            JOIN manifest_items mi ON mi.run_id=r.id JOIN packages p ON p.id=mi.package_id
            LEFT JOIN pickup_demands pd ON pd.package_id=p.id
            LEFT JOIN active_allocations a ON a.package_id=p.id
            WHERE r.organization_id=? AND r.state IN ('PUBLISHED','ACKNOWLEDGED','IN_PROGRESS')".$where."
            GROUP BY r.id,u.display_name ORDER BY r.planned_end,r.id LIMIT 50",$args)->fetchAll(PDO::FETCH_ASSOC);
        return ['items'=>array_map(function ($r) {
            $parcels=[];
            if ((int)$r['collected_count']>0) {
                $items=$this->q("SELECT mi.id AS item_id,mi.package_id,mi.state,s.public_reference,si.si,
                        p.state AS package_state,p.custodian_type,p.current_location_id,
                        pd.status AS demand_status,pd.assigned_run_id,pd.origin_location_id,
                        a.manifest_item_id,
                        EXISTS(SELECT 1 FROM scan_events se WHERE se.run_id=mi.run_id
                            AND se.package_id=mi.package_id AND se.action='INBOUND_PICKUP' AND se.result_code='ACCEPTED') AS scanned
                    FROM manifest_items mi JOIN packages p ON p.id=mi.package_id
                    JOIN shipments s ON s.id=p.shipment_id
                    LEFT JOIN shipping_identifiers si ON si.package_id=p.id
                    LEFT JOIN pickup_demands pd ON pd.package_id=p.id
                    LEFT JOIN active_allocations a ON a.package_id=p.id
                    WHERE mi.run_id=? ORDER BY mi.id",[$r['id']])->fetchAll(PDO::FETCH_ASSOC);
                $hasSession=(bool)$this->q('SELECT 1 FROM receiving_sessions WHERE inbound_run_id=?',[$r['id']])->fetchColumn();
                foreach ($items as $item) {
                    $parcels[]=['package_id'=>(string)$item['package_id'],'reference'=>$item['public_reference'],
                        'si'=>$item['si'],'state'=>$item['state'],
                        'can_release'=>!$hasSession && in_array($r['state'],['ACKNOWLEDGED','IN_PROGRESS'],true)
                            && $item['state']==='EXPECTED' && $item['package_state']==='AT_ORIGIN'
                            && $item['custodian_type']==='LOCKER'
                            && (string)$item['current_location_id']===(string)$item['origin_location_id']
                            && $item['demand_status']==='ASSIGNED' && (string)$item['assigned_run_id']===(string)$r['id']
                            && (string)$item['manifest_item_id']===(string)$item['item_id']
                            && !in_array($item['scanned'],[true,'t','1',1],true)];
                }
            }
            return [
                'run_id'=>(string)$r['id'],'state'=>$r['state'],'revision'=>(int)$r['revision'],
                'driver'=>$r['driver_name'],'planned_end'=>$r['planned_end'],
                'package_count'=>(int)$r['package_count'],
                'collected_count'=>(int)$r['collected_count'],'released_count'=>(int)$r['released_count'],
                'can_release'=>in_array($r['state'],['PUBLISHED','ACKNOWLEDGED'],true)
                    && (int)$r['collected_count']===0 && (int)$r['released_count']===0
                    && (int)$r['package_count']===(int)$r['releasable_count'],
                'parcels'=>$parcels,
            ]; },$rows)];
    }

    public function release(string $user,string $runId,array $input,string $key): array {
        $this->identity->requireRole($user,'ADMIN');
        Input::text($runId,1,18); Input::text($key,16,100);
        Input::fields($input,['reason','expected_revision']);
        $reason=trim(Input::text($input['reason'],3,500));
        if (strlen($reason)<3) { throw new Failure(422,'INVALID_INPUT','Explain why this pickup is being reassigned.'); }
        $revision=filter_var($input['expected_revision'],FILTER_VALIDATE_INT);
        if ($revision===false || $revision<1) { throw new Failure(422,'INVALID_INPUT','Run revision is invalid.'); }
        return (new Transaction($this->db))->run(function () use ($user,$runId,$reason,$revision,$key) {
            $scope='pickup-recovery:'.$this->org().':'.$user;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('pickup-recovery',json_encode([$runId,$reason,$revision],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was used for another recovery.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $driver=$this->q("SELECT r.driver_id FROM route_runs r JOIN inbound_offer_runs ir ON ir.run_id=r.id
                WHERE r.id=? AND r.organization_id=?",[$runId,$this->org()])->fetchColumn();
            if (!$driver) { throw new Failure(404,'RUN_NOT_FOUND','Offer-created inbound run not found.'); }
            // Offer acceptance locks this driver before its run. Preserve that order.
            $this->q('SELECT id FROM drivers WHERE id=? FOR UPDATE',[$driver]);
            $run=$this->q("SELECT r.id,r.state,r.revision,r.driver_id,r.departed_at FROM route_runs r
                JOIN inbound_offer_runs ir ON ir.run_id=r.id WHERE r.id=? AND r.organization_id=? FOR UPDATE OF r",
                [$runId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$run || (string)$run['driver_id']!==(string)$driver || !in_array($run['state'],['PUBLISHED','ACKNOWLEDGED'],true)
                || $run['departed_at']!==null || (int)$run['revision']!==$revision) {
                throw new Failure(409,'RUN_CHANGED','Run changed. Refresh before releasing pickup work.');
            }
            if ($this->q('SELECT 1 FROM receiving_sessions WHERE inbound_run_id=?',[$runId])->fetchColumn()
                || $this->q("SELECT 1 FROM scan_events WHERE run_id=? AND action='INBOUND_PICKUP' AND result_code='ACCEPTED'",[$runId])->fetchColumn()) {
                throw new Failure(409,'PICKUP_CUSTODY_AMBIGUOUS','A pickup or receiving session is recorded. Reconcile custody before reassignment.');
            }
            if ((int)$this->q("SELECT COUNT(*) FROM manifests WHERE run_id=? AND state='ACTIVE'",[$runId])->fetchColumn()!==1) {
                throw new Failure(409,'RUN_CHANGED','Active manifest changed. Refresh before releasing pickup work.');
            }
            $items=$this->q('SELECT id,package_id FROM manifest_items WHERE run_id=? ORDER BY package_id',[$runId])->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) { throw new Failure(409,'RUN_EMPTY','Run has no pickup parcels.'); }
            foreach ($items as $item) {
                $package=$this->q('SELECT state,custodian_type,current_location_id FROM packages WHERE id=? FOR UPDATE',[$item['package_id']])->fetch(PDO::FETCH_ASSOC);
                $demand=$this->q('SELECT id,status,assigned_run_id,origin_location_id FROM pickup_demands WHERE package_id=? FOR UPDATE',[$item['package_id']])->fetch(PDO::FETCH_ASSOC);
                $allocation=$this->q('SELECT manifest_item_id FROM active_allocations WHERE package_id=?',[$item['package_id']])->fetchColumn();
                $state=$this->q('SELECT state FROM manifest_items WHERE id=?',[$item['id']])->fetchColumn();
                if (!$package || !$demand || $state!=='EXPECTED' || $package['state']!=='AT_ORIGIN'
                    || $package['custodian_type']!=='LOCKER' || (string)$package['current_location_id']!==(string)$demand['origin_location_id']
                    || $demand['status']!=='ASSIGNED' || (string)$demand['assigned_run_id']!==$runId
                    || (string)$allocation!==(string)$item['id']) {
                    throw new Failure(409,'PICKUP_CUSTODY_AMBIGUOUS','A parcel is no longer confirmed at the origin locker. Reconcile it first.');
                }
            }
            $this->q("UPDATE route_runs SET state='CANCELLED',revision=revision+1 WHERE id=?",[$runId]);
            $this->q("UPDATE manifests SET state='CANCELLED',revision=revision+1 WHERE run_id=? AND state='ACTIVE'",[$runId]);
            $this->q("UPDATE route_run_stops SET state='CANCELLED' WHERE run_id=?",[$runId]);
            foreach ($items as $item) {
                $this->q('DELETE FROM active_allocations WHERE package_id=? AND manifest_item_id=?',[$item['package_id'],$item['id']]);
                $this->q("UPDATE pickup_demands SET status='OPEN',assigned_run_id=NULL,version=version+1,
                    pickup_deadline=GREATEST(pickup_deadline,now()+interval '4 hours') WHERE package_id=?",[$item['package_id']]);
                $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at)
                    VALUES (?,?, 'PICKUP_ASSIGNMENT_RELEASED',?,?::jsonb,now())",
                    [$item['package_id'],Secrets::uuid(),$user,json_encode(['run_id'=>$runId,'reason'=>$reason],JSON_THROW_ON_ERROR)]);
            }
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'PICKUP_RUN_RELEASED','run',?)",[$user,$runId]);
            $result=['run_id'=>$runId,'state'=>'CANCELLED','revision'=>$revision+1,'released_count'=>count($items)];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }

    /** Manual evidence is an operator assertion, not authenticated locker telemetry. */
    public function releaseItem(string $user,string $runId,string $packageId,array $input,string $key): array {
        $this->identity->requireRole($user,'ADMIN');
        Input::text($runId,1,18); Input::text($packageId,1,18); Input::text($key,16,100);
        Input::fields($input,['reason','evidence_kind','evidence_reference','expected_revision']);
        $reason=trim(Input::text($input['reason'],3,500));
        $evidenceKind=Input::text($input['evidence_kind'],1,40);
        $evidenceReference=trim(Input::text($input['evidence_reference'],3,200));
        $revision=filter_var($input['expected_revision'],FILTER_VALIDATE_INT);
        if (strlen($reason)<3 || strlen($evidenceReference)<3
            || !in_array($evidenceKind,['LOCKER_INVENTORY','SITE_INSPECTION'],true)
            || $revision===false || $revision<1) {
            throw new Failure(422,'INVALID_INPUT','Provide a reason, locker verification reference and current run revision.');
        }
        return (new Transaction($this->db))->run(function () use ($user,$runId,$packageId,$reason,$evidenceKind,$evidenceReference,$revision,$key) {
            $scope='pickup-partial-recovery:'.$this->org().':'.$user;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $hash=$this->crypto->digest('pickup-partial-recovery',json_encode([$runId,$packageId,$reason,$evidenceKind,$evidenceReference,$revision],JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was used for another recovery.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $driver=$this->q("SELECT r.driver_id FROM route_runs r JOIN inbound_offer_runs ir ON ir.run_id=r.id
                WHERE r.id=? AND r.organization_id=?",[$runId,$this->org()])->fetchColumn();
            if (!$driver) { throw new Failure(404,'RUN_NOT_FOUND','Offer-created inbound run not found.'); }
            $this->q('SELECT id FROM drivers WHERE id=? FOR UPDATE',[$driver]);
            $run=$this->q("SELECT r.state,r.revision,r.driver_id,r.departed_at FROM route_runs r
                JOIN inbound_offer_runs ir ON ir.run_id=r.id WHERE r.id=? AND r.organization_id=? FOR UPDATE OF r",
                [$runId,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$run || (string)$run['driver_id']!==(string)$driver
                || !in_array($run['state'],['ACKNOWLEDGED','IN_PROGRESS'],true)
                || $run['departed_at']!==null || (int)$run['revision']!==$revision) {
                throw new Failure(409,'RUN_CHANGED','Run changed. Refresh before releasing a parcel.');
            }
            if ($this->q('SELECT 1 FROM receiving_sessions WHERE inbound_run_id=?',[$runId])->fetchColumn()
                || (int)$this->q("SELECT COUNT(*) FROM manifests WHERE run_id=? AND state='ACTIVE'",[$runId])->fetchColumn()!==1
                || !$this->q("SELECT 1 FROM manifest_items WHERE run_id=? AND state='LOADED'",[$runId])->fetchColumn()) {
                throw new Failure(409,'PICKUP_CUSTODY_AMBIGUOUS','This run needs custody reconciliation before a parcel can be reassigned.');
            }
            $package=$this->q("SELECT p.state,p.version,p.custodian_type,p.current_location_id,s.organization_id
                FROM packages p JOIN shipments s ON s.id=p.shipment_id WHERE p.id=? FOR UPDATE OF p",[$packageId])->fetch(PDO::FETCH_ASSOC);
            $demand=$this->q('SELECT status,assigned_run_id,origin_location_id,pickup_deadline FROM pickup_demands WHERE package_id=? FOR UPDATE',[$packageId])->fetch(PDO::FETCH_ASSOC);
            $item=$this->q("SELECT mi.id,mi.state,mi.stop_id,a.manifest_item_id FROM manifest_items mi
                LEFT JOIN active_allocations a ON a.package_id=mi.package_id
                WHERE mi.run_id=? AND mi.package_id=?",[$runId,$packageId])->fetch(PDO::FETCH_ASSOC);
            if (!$package || (string)$package['organization_id']!==$this->org() || !$demand || !$item
                || $item['state']!=='EXPECTED' || $package['state']!=='AT_ORIGIN'
                || $package['custodian_type']!=='LOCKER'
                || (string)$package['current_location_id']!==(string)$demand['origin_location_id']
                || $demand['status']!=='ASSIGNED' || (string)$demand['assigned_run_id']!==$runId
                || (string)$item['manifest_item_id']!==(string)$item['id']
                || $this->q("SELECT 1 FROM scan_events WHERE run_id=? AND package_id=? AND action='INBOUND_PICKUP' AND result_code='ACCEPTED'",[$runId,$packageId])->fetchColumn()) {
                throw new Failure(409,'PICKUP_CUSTODY_AMBIGUOUS','This parcel is not confirmed as uncollected at the origin locker.');
            }
            $this->q("UPDATE manifest_items SET state='RELEASED' WHERE id=?",[$item['id']]);
            $this->q('DELETE FROM active_allocations WHERE package_id=? AND manifest_item_id=?',[$packageId,$item['id']]);
            $deadline=$this->q("UPDATE pickup_demands SET status='OPEN',assigned_run_id=NULL,version=version+1,
                pickup_deadline=GREATEST(pickup_deadline,now()+interval '4 hours')
                WHERE package_id=? RETURNING pickup_deadline",[$packageId])->fetchColumn();
            $this->q("UPDATE route_runs SET revision=revision+1 WHERE id=?",[$runId]);
            $this->q("UPDATE manifests SET revision=revision+1 WHERE run_id=? AND state='ACTIVE'",[$runId]);
            if (!$this->q("SELECT 1 FROM manifest_items WHERE stop_id=? AND state<>'RELEASED'",[$item['stop_id']])->fetchColumn()) {
                $this->q("UPDATE route_run_stops SET state='CANCELLED' WHERE id=?",[$item['stop_id']]);
            }
            $details=['run_id'=>$runId,'reason'=>$reason,'evidence_kind'=>$evidenceKind,
                'evidence_reference'=>$evidenceReference,'previous_deadline'=>$demand['pickup_deadline'],
                'new_deadline'=>$deadline,'package_version'=>(int)$package['version']];
            $this->q("INSERT INTO package_events(package_id,event_uuid,event_type,actor_user_id,details,occurred_at)
                VALUES (?,?,'PICKUP_ASSIGNMENT_RELEASED',?,?::jsonb,now())",
                [$packageId,Secrets::uuid(),$user,json_encode($details,JSON_THROW_ON_ERROR)]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id)
                VALUES (?,'PICKUP_ITEM_RELEASED','package',?)",[$user,$packageId]);
            $result=['run_id'=>$runId,'package_id'=>$packageId,'state'=>'RELEASED','revision'=>$revision+1];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at)
                VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",
                [$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
