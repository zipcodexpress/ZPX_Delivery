<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Shipping\Service as Shipping;

/** Reserves a physical destination door; custody remains with the driver until evidence is confirmed. */
final class FinalDeposit
{
    private Identity $identity;
    public function __construct(private PDO $db, private Secrets $crypto) { $this->identity = new Identity($db,$crypto); }
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function org(): string { return (string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'); }

    public function prepare(string $user,string $runId,string $stopId,array $input,string $key,string $match=''): array
    {
        $this->identity->requireRole($user,'DRIVER');
        Shipping::id($runId); Shipping::id($stopId);
        Input::fields($input,['package_id','pairing_id','label_payload','expected_package_version','expected_revision']);
        $package=Shipping::id($input['package_id']); $pairing=Shipping::id($input['pairing_id']);
        $label=Input::text($input['label_payload'],1,500); Input::text($key,16,100);
        if (!is_int($input['expected_revision']) || $input['expected_revision']<1 || !is_int($input['expected_package_version']) || $input['expected_package_version']<0) {
            throw new Failure(422,'INVALID_INPUT','Expected revisions must be valid integers.');
        }
        $revision=$input['expected_revision'];
        if ($match!=='' && $match!=='"'.$revision.'"') { throw new Failure(409,'RUN_REVISION_CONFLICT','Run revision precondition does not match.'); }
        return (new Transaction($this->db))->run(function () use ($user,$runId,$stopId,$package,$pairing,$label,$input,$key,$revision) {
            $scope='final-deposit:'.$this->org().':'.$user;
            $this->q('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[$scope.':'.$key]);
            $request=['run_id'=>$runId,'stop_id'=>$stopId]+$input;
            ksort($request);
            $hash=$this->crypto->digest('final-deposit-request',json_encode($request,JSON_THROW_ON_ERROR));
            $saved=$this->q("SELECT encode(payload_hash,'hex') AS hash,response_body FROM idempotency_records WHERE scope=? AND request_key=?",[$scope,$key])->fetch(PDO::FETCH_ASSOC);
            if ($saved) {
                if (!hash_equals($saved['hash'],$hash)) { throw new Failure(409,'IDEMPOTENCY_CONFLICT','This request key was already used for other details.'); }
                return json_decode($saved['response_body'],true,512,JSON_THROW_ON_ERROR);
            }
            $row=$this->q("SELECT r.state AS run_state,r.revision,r.departed_at,rs.state AS stop_state,rs.location_id,
                    mi.state AS item_state,p.version AS package_version,p.state AS package_state,p.custodian_type,p.custodian_ref,
                    p.width_mm,p.height_mm,p.depth_mm,p.weight_g,s.destination_location_id,si.destination_location_id AS si_destination,
                    k.id AS locker_id,k.capabilities
                FROM route_runs r JOIN drivers d ON d.id=r.driver_id
                JOIN route_run_stops rs ON rs.run_id=r.id AND rs.id=?
                JOIN manifest_items mi ON mi.run_id=r.id AND mi.stop_id=rs.id AND mi.package_id=?
                JOIN packages p ON p.id=mi.package_id JOIN shipments s ON s.id=p.shipment_id
                JOIN shipping_identifiers si ON si.package_id=p.id
                JOIN lockers k ON k.location_id=rs.location_id
                WHERE r.id=? AND r.kind='OUTBOUND' AND r.organization_id=? AND d.user_id=? AND s.organization_id=?
                FOR UPDATE OF r,rs,mi,p",[$stopId,$package,$runId,$this->org(),$user,$this->org()])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'DEPOSIT_NOT_FOUND','Parcel is not assigned to this driver and stop.'); }
            if ((int)$row['revision']!==$revision) { throw new Failure(409,'RUN_REVISION_CONFLICT','Run changed. Refresh before deposit.'); }
            if ((int)$row['package_version']!==$input['expected_package_version']) { throw new Failure(409,'PACKAGE_VERSION_CONFLICT','Parcel changed. Refresh before deposit.'); }
            $driver=(string)$this->q('SELECT id FROM drivers WHERE user_id=?',[$user])->fetchColumn();
            if ($row['run_state']!=='IN_PROGRESS' || $row['departed_at']===null || $row['stop_state']!=='ARRIVED' || $row['item_state']!=='LOADED'
                || $row['package_state']!=='OUTBOUND_CUSTODY' || $row['custodian_type']!=='DRIVER' || $row['custodian_ref']!==$driver) {
                throw new Failure(409,'DEPOSIT_NOT_READY','Parcel must remain loaded in the arrived driver run.');
            }
            if ($row['location_id']!==$row['destination_location_id'] || $row['location_id']!==$row['si_destination']) {
                throw new Failure(409,'WRONG_DESTINATION','Stop and shipping identifier must match the destination locker.');
            }
            $cap=json_decode($row['capabilities'] ?? '{}',true);
            if (!is_array($cap) || ($cap['physical_commands_enabled'] ?? false)!==true || ($cap['synthetic'] ?? true)!==false) {
                throw new Failure(409,'PHYSICAL_LOCKER_REQUIRED','Destination is not enabled for physical commands.');
            }
            $token=$this->q("SELECT encode(token_hash,'hex') FROM package_labels WHERE package_id=? AND status='ACTIVE' AND expires_at>now()",[$package])->fetchColumn();
            if (!$token || !hash_equals((string)$token,hash('sha256',$label))) { throw new Failure(422,'LABEL_REJECTED','Scan the active parcel label.'); }
            if ($this->q("SELECT 1 FROM locker_sessions WHERE package_id=? AND action='FINAL_DEPOSIT' AND status IN ('READY','OPEN','CLOSED','UNKNOWN','CONFIRMED') LIMIT 1",[$package])->fetchColumn()) {
                throw new Failure(409,'SESSION_EXISTS','A final deposit session already exists; reconcile it before retrying.');
            }
            $pair=$this->q("SELECT tp.device_id FROM terminal_pairing_sessions tp JOIN locker_devices d ON d.id=tp.device_id
                WHERE tp.id=? AND tp.actor_user_id=? AND tp.workflow='FINAL_DEPOSIT' AND tp.status='APPROVED' AND tp.expires_at>now()
                  AND d.locker_id=? AND d.status='ACTIVE' FOR UPDATE OF tp",[$pairing,$user,$row['locker_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$pair) { throw new Failure(409,'PAIRING_REQUIRED','Approve a current pairing at this destination device.'); }
            if (!$this->q("SELECT 1 FROM device_credentials WHERE device_id=? AND public_key IS NOT NULL AND revoked_at IS NULL
                AND valid_from<=now() AND (expires_at IS NULL OR expires_at>now()) LIMIT 1",[$pair['device_id']])->fetchColumn()) {
                throw new Failure(409,'DEVICE_NOT_ENROLLED','Destination device has no valid enrolled credential.');
            }
            $comp=$this->q("SELECT c.id,c.code,co.generation FROM compartments c
                JOIN compartment_ownership co ON co.compartment_id=c.id AND co.owner='DELIVERY'
                JOIN ownership_manifests om ON om.id=co.manifest_id AND om.locker_id=c.locker_id AND om.generation=co.generation AND om.state='ACTIVE'
                LEFT JOIN compartment_claims cc ON cc.compartment_id=c.id
                WHERE c.locker_id=? AND c.status='AVAILABLE' AND cc.id IS NULL
                  AND c.width_mm>=? AND c.height_mm>=? AND c.depth_mm>=? AND c.max_weight_g>=?
                  AND om.generation=(SELECT max(generation) FROM ownership_manifests WHERE locker_id=c.locker_id AND state='ACTIVE')
                ORDER BY c.width_mm*c.height_mm*c.depth_mm,c.id LIMIT 1 FOR UPDATE OF c SKIP LOCKED",
                [$row['locker_id'],$row['width_mm'],$row['height_mm'],$row['depth_mm'],$row['weight_g']])->fetch(PDO::FETCH_ASSOC);
            if (!$comp) { throw new Failure(409,'NO_COMPARTMENT','No eligible delivery compartment is available.'); }
            $session=(string)$this->q("INSERT INTO locker_sessions(package_id,compartment_id,actor_user_id,action,status,expires_at,credential_hash,evidence_policy,pairing_id,expected_package_version,ownership_generation)
                VALUES (?,?,?,'FINAL_DEPOSIT','READY',now()+interval '10 minutes',decode(?,'hex'),'ENROLLED_DOOR_PLUS_ACTOR',?,?,?) RETURNING id",
                [$package,$comp['id'],$user,$token,$pairing,$row['package_version'],$comp['generation']])->fetchColumn();
            $this->q("INSERT INTO compartment_claims(compartment_id,package_id,session_id,state,expires_at) VALUES (?,?,?,'HELD',now()+interval '10 minutes')",[$comp['id'],$package,$session]);
            $command=Secrets::uuid();
            $this->q("INSERT INTO device_commands(session_id,device_id,command_uuid,status,expires_at,ownership_generation)
                VALUES (?,?,?,'PENDING',now()+interval '10 minutes',?)",[$session,$pair['device_id'],$command,$comp['generation']]);
            $this->q("UPDATE terminal_pairing_sessions SET status='CONSUMED' WHERE id=?",[$pairing]);
            $this->q("INSERT INTO audit_events(actor_user_id,action,entity_type,entity_id) VALUES (?,'FINAL_DEPOSIT_PREPARED','locker_session',?)",[$user,$session]);
            $result=['session_id'=>$session,'command_id'=>$command,'compartment_code'=>$comp['code'],'status'=>'READY',
                'package_version'=>(int)$row['package_version'],'run_revision'=>$revision,'custody_transferred'=>false,'awaiting_device_evidence'=>true];
            $this->q("INSERT INTO idempotency_records(scope,request_key,payload_hash,response_status,response_body,expires_at) VALUES (?,?,decode(?,'hex'),200,?,now()+interval '30 days')",[$scope,$key,$hash,json_encode($result,JSON_THROW_ON_ERROR)]);
            return $result;
        });
    }
}
