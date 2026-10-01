<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use PDOException;
use think\Request;
use Zpx\Identity\Failure;
use Zpx\Identity\Input;
use Zpx\Infrastructure\Database\Transaction;

/** Door authorization for an enrolled terminal; the terminal must journal before actuation. */
final class DeviceCommands
{
    private const PATH='/api/delivery/v1/devices/me/commands';
    private const CONFIG_PATH='/api/delivery/v1/devices/me/cabinet-config';
    private const MODELS_PATH='/api/delivery/v1/devices/me/box-models';
    private const EVENTS_PATH='/api/delivery/v1/devices/me/command-events';
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }

    public function poll(Request $request): array
    {
        if ($request->method(true)!=='GET') { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
        return (new Transaction($this->db))->run(function () use ($request) {
            $credential=$this->authenticate($request,self::PATH);
            $rows=$this->q("SELECT dc.id,dc.command_uuid,dc.expires_at,encode(dc.payload_hash,'hex') AS payload_hash,ls.id AS session_id,
                    c.locker_id,cb.board_address,cb.protocol_profile,c.door_address,co.generation
                FROM device_commands dc JOIN locker_sessions ls ON ls.id=dc.session_id
                JOIN compartments c ON c.id=ls.compartment_id JOIN controller_boards cb ON cb.id=c.controller_board_id AND cb.locker_id=c.locker_id
                JOIN compartment_claims cc ON cc.session_id=ls.id AND cc.compartment_id=c.id AND cc.package_id=ls.package_id
                JOIN compartment_ownership co ON co.compartment_id=c.id AND co.owner='DELIVERY'
                JOIN ownership_manifests om ON om.id=co.manifest_id AND om.locker_id=c.locker_id AND om.generation=co.generation AND om.state='ACTIVE'
                JOIN terminal_pairing_sessions tp ON tp.id=ls.pairing_id
                JOIN packages p ON p.id=ls.package_id
                WHERE dc.device_id=? AND dc.status='PENDING' AND dc.expires_at>now()
                  AND ls.action='FINAL_DEPOSIT' AND ls.status='READY' AND ls.expires_at>now()
                  AND ls.expected_package_version=p.version AND ls.ownership_generation=co.generation AND dc.ownership_generation=co.generation
                  AND cc.state='HELD' AND c.status='AVAILABLE' AND c.locker_id=? AND c.door_address IS NOT NULL
                  AND tp.device_id=dc.device_id AND tp.actor_user_id=ls.actor_user_id AND tp.status='CONSUMED'
                  AND om.generation=(SELECT max(generation) FROM ownership_manifests WHERE locker_id=c.locker_id AND state='ACTIVE')
                  AND p.state='OUTBOUND_CUSTODY' AND p.custodian_type='DRIVER'
                  AND EXISTS (SELECT 1 FROM manifest_items mi JOIN route_runs r ON r.id=mi.run_id
                    JOIN route_run_stops rs ON rs.id=mi.stop_id AND rs.run_id=r.id
                    JOIN drivers dr ON dr.id=r.driver_id
                    JOIN shipments s ON s.id=p.shipment_id JOIN lockers k ON k.location_id=rs.location_id
                    JOIN locations l ON l.id=k.location_id AND l.site_mode='DELIVERY_ONLY' AND l.status='ACTIVE'
                    WHERE mi.package_id=p.id AND mi.state='LOADED' AND r.kind='OUTBOUND' AND r.state='IN_PROGRESS'
                      AND r.organization_id=? AND rs.state='ARRIVED' AND k.id=c.locker_id
                      AND s.destination_location_id=rs.location_id AND dr.user_id=ls.actor_user_id
                      AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)
                      AND p.custodian_ref=dr.id::text)
                ORDER BY dc.id LIMIT 20 FOR UPDATE OF dc",[$credential['id'],$credential['locker_id'],(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0')])->fetchAll(PDO::FETCH_ASSOC);
            $commands=[];
            foreach ($rows as $row) {
                $command=['command_id'=>$row['command_uuid'],'session_id'=>(string)$row['session_id'],'action'=>'OPEN',
                    'address'=>['locker_id'=>(string)$row['locker_id'],'board_address'=>(int)$row['board_address'],'door_address'=>(int)$row['door_address']],
                    'protocol_profile'=>$row['protocol_profile'],'ownership_generation'=>(int)$row['generation'],'expires_at'=>$row['expires_at']];
                $hash=hash('sha256',json_encode($command,JSON_THROW_ON_ERROR));
                if ($row['payload_hash']!==null && !hash_equals($row['payload_hash'],$hash)) {
                    throw new Failure(409,'COMMAND_PAYLOAD_CHANGED','Physical address or command policy changed; reconcile before actuation.');
                }
                $this->q("UPDATE device_commands SET dispatched_at=COALESCE(dispatched_at,now()),payload_hash=decode(?,'hex') WHERE id=?",[$hash,$row['id']]);
                $commands[]=$command;
            }
            return ['items'=>$commands];
        });
    }

    public function boxModels(Request $request): array
    {
        $config=$this->cabinetConfig($request,self::MODELS_PATH);
        return ['revision'=>$config['revision'],'items'=>$config['boxModels']];
    }

    /** Record signed terminal observations; this never confirms physical custody. */
    public function reportEvent(Request $request): array
    {
        if ($request->method(true)!=='POST') { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
        if (strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') {
            throw new Failure(415,'JSON_REQUIRED','Send a JSON request.');
        }
        $raw=$request->getInput();
        if (strlen($raw)>4096) { throw new Failure(413,'REQUEST_TOO_LARGE','Request is too large.'); }
        try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new Failure(400,'INVALID_JSON','Request is not valid JSON.'); }
        if (!$object instanceof \stdClass) { throw new Failure(422,'INVALID_INPUT','Send a JSON object.'); }
        $input=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        Input::fields($input,['command_id','event_id','event_type']);
        foreach (['command_id','event_id'] as $field) {
            if (!is_string($input[$field]) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8a-f][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$input[$field])) {
                throw new Failure(422,'INVALID_INPUT','Command and event IDs must be UUIDs.');
            }
        }
        $next=['DISPATCH_RECORDED'=>['PENDING','READY'],'OPEN_OBSERVED'=>['DISPATCH_RECORDED','READY'],
            'CLOSE_OBSERVED'=>['OPEN_OBSERVED','OPEN'],'UNKNOWN'=>null];
        if (!is_string($input['event_type']) || !array_key_exists($input['event_type'],$next)) {
            throw new Failure(422,'INVALID_EVENT','Unsupported command event.');
        }
        return (new Transaction($this->db))->run(function () use ($request,$input,$next) {
            $credential=$this->authenticate($request,self::EVENTS_PATH,'POST');
            $row=$this->q("SELECT dc.id,dc.status AS command_status,dc.dispatched_at,dc.payload_hash,dc.expires_at,
                    ls.id AS session_id,ls.status AS session_status,ls.action,ls.evidence_policy
                FROM device_commands dc JOIN locker_sessions ls ON ls.id=dc.session_id
                JOIN compartments c ON c.id=ls.compartment_id
                WHERE dc.command_uuid=?::uuid AND dc.device_id=? AND c.locker_id=?
                  AND ls.action='FINAL_DEPOSIT' AND ls.evidence_policy='ENROLLED_DOOR_PLUS_ACTOR'
                FOR UPDATE OF dc,ls",[$input['command_id'],$credential['id'],$credential['locker_id']])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'COMMAND_NOT_FOUND','Device command not found.'); }
            $existing=$this->q("SELECT command_id,evidence->>'event_type' AS event_type FROM device_events
                WHERE device_id=? AND external_event_id=?",[$credential['id'],$input['event_id']])->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ((string)$existing['command_id']!==(string)$row['id'] || $existing['event_type']!==$input['event_type']) {
                    throw new Failure(409,'EVENT_ID_CONFLICT','Event ID was already used for another observation.');
                }
                return ['command_id'=>$input['command_id'],'event_id'=>$input['event_id'],'recorded'=>true,'replayed'=>true,
                    'custody_transferred'=>false];
            }
            if ($row['dispatched_at']===null || $row['payload_hash']===null) {
                throw new Failure(409,'COMMAND_NOT_DISPATCHED','Poll and journal the command before reporting an observation.');
            }
            $event=$input['event_type'];
            if ($event==='DISPATCH_RECORDED' && strtotime($row['expires_at'])<=time()) {
                throw new Failure(409,'COMMAND_EXPIRED','Command expired before dispatch.');
            }
            if ($event==='DISPATCH_RECORDED' && !$this->q("SELECT 1 FROM locker_sessions ls
                JOIN device_commands dc ON dc.session_id=ls.id AND dc.id=?
                JOIN packages p ON p.id=ls.package_id
                JOIN compartments c ON c.id=ls.compartment_id
                JOIN compartment_claims cc ON cc.session_id=ls.id AND cc.package_id=p.id AND cc.compartment_id=c.id AND cc.state='HELD'
                JOIN compartment_ownership co ON co.compartment_id=c.id AND co.owner='DELIVERY'
                JOIN ownership_manifests om ON om.id=co.manifest_id AND om.locker_id=c.locker_id
                  AND om.generation=co.generation AND om.state='ACTIVE'
                WHERE ls.id=? AND ls.status='READY' AND ls.expires_at>now()
                  AND ls.expected_package_version=p.version AND ls.ownership_generation=co.generation
                  AND dc.ownership_generation=co.generation
                  AND p.state='OUTBOUND_CUSTODY' AND p.custodian_type='DRIVER'
                  AND c.status='AVAILABLE' AND c.door_address IS NOT NULL
                  AND om.generation=(SELECT max(generation) FROM ownership_manifests WHERE locker_id=c.locker_id AND state='ACTIVE')
                  AND EXISTS (SELECT 1 FROM manifest_items mi JOIN route_runs r ON r.id=mi.run_id
                    JOIN route_run_stops rs ON rs.id=mi.stop_id AND rs.run_id=r.id
                    JOIN drivers dr ON dr.id=r.driver_id
                    JOIN shipments s ON s.id=p.shipment_id JOIN lockers k ON k.location_id=rs.location_id
                    JOIN locations l ON l.id=k.location_id AND l.site_mode='DELIVERY_ONLY' AND l.status='ACTIVE'
                    WHERE mi.package_id=p.id AND mi.state='LOADED' AND r.kind='OUTBOUND' AND r.state='IN_PROGRESS'
                      AND r.organization_id=? AND rs.state='ARRIVED' AND k.id=c.locker_id
                      AND s.destination_location_id=rs.location_id AND dr.user_id=ls.actor_user_id
                      AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)
                      AND p.custodian_ref=dr.id::text)",
                [$row['id'],$row['session_id'],(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0')])->fetchColumn()) {
                throw new Failure(409,'COMMAND_STALE','Command authorization changed before dispatch.');
            }
            if ($event==='UNKNOWN') {
                if (!in_array($row['command_status'],['PENDING','DISPATCH_RECORDED','OPEN_OBSERVED'],true)) {
                    throw new Failure(409,'EVENT_SEQUENCE_INVALID','Command cannot enter an unknown state.');
                }
            } elseif ([$row['command_status'],$row['session_status']]!==$next[$event]) {
                throw new Failure(409,'EVENT_SEQUENCE_INVALID','Command observations must be reported in order.');
            }
            $evidence=['event_type'=>$event,'source'=>'SIGNED_TERMINAL_REPORT',
                'synthetic_simulation'=>false,'physical_hardware_verified'=>false];
            $this->q("INSERT INTO device_events(device_id,command_id,external_event_id,occurred_at,evidence)
                VALUES (?,?,?,now(),?::jsonb)",[$credential['id'],$row['id'],$input['event_id'],json_encode($evidence,JSON_THROW_ON_ERROR)]);
            $this->q('UPDATE device_commands SET status=? WHERE id=?',[$event,$row['id']]);
            $sessionStatus=match($event) {'OPEN_OBSERVED'=>'OPEN','CLOSE_OBSERVED'=>'CLOSED','UNKNOWN'=>'UNKNOWN',default=>'READY'};
            $this->q('UPDATE locker_sessions SET status=? WHERE id=?',[$sessionStatus,$row['session_id']]);
            return ['command_id'=>$input['command_id'],'event_id'=>$input['event_id'],'recorded'=>true,'replayed'=>false,
                'command_status'=>$event,'session_status'=>$sessionStatus,'custody_transferred'=>false];
        });
    }

    public function cabinetConfig(Request $request,string $path=self::CONFIG_PATH): array
    {
        if ($request->method(true)!=='GET') { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
        return (new Transaction($this->db))->run(function () use ($request,$path) {
            $credential=$this->authenticate($request,$path);
            $cabinets=$this->q("SELECT c.cabinet_id,c.version,c.address,c.zipcode,l.address_text
                FROM cabinet c JOIN locations l ON l.id=c.bound_location_id
                JOIN lockers k ON k.id=c.bound_locker_id AND k.location_id=l.id
                WHERE c.bound_locker_id=? AND c.organization_id=? AND c.status='BOUND'
                  AND c.legacy_cabinet_id IS NULL AND l.status='ACTIVE' AND l.site_mode='DELIVERY_ONLY'
                  AND NOT EXISTS (SELECT 1 FROM legacy_location_links ll WHERE ll.location_id=l.id)",
                [$credential['locker_id'],(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0')])->fetchAll(PDO::FETCH_ASSOC);
            if (count($cabinets)!==1) { throw new Failure(404,'CABINET_CONFIG_UNAVAILABLE','No unique commissioned Delivery cabinet configuration.'); }
            $cabinet=$cabinets[0];
            $rows=$this->q('SELECT b.body_id,b.sequence,b.display_sequence,b.addr AS lock_addr,b.protocol_profile,b.body_model_id,
                    bm.model_name AS body_model_name,x.box_id,x.addr AS box_addr,x."row",x."column",
                    xm.model_id AS box_model_id,xm.model_name AS box_model_name,xm.size_class,
                    cp.door_address,cp.width_mm,cp.height_mm,cp.depth_mm,cp.max_weight_g,
                    cb.board_address,cb.protocol_profile AS board_profile
                FROM cabinet_body b JOIN cabinet_body_model bm ON bm.model_id=b.body_model_id AND bm.organization_id=?
                JOIN cabinet_box x ON x.body_id=b.body_id AND x.cabinet_id=b.cabinet_id
                JOIN cabinet_box_model xm ON xm.model_id=x.box_model_id AND xm.organization_id=?
                JOIN compartments cp ON cp.id=x.compartment_id AND cp.locker_id=?
                JOIN controller_boards cb ON cb.id=cp.controller_board_id AND cb.locker_id=cp.locker_id
                WHERE b.cabinet_id=? ORDER BY b.display_sequence,x."row",x."column",x.box_id',
                [(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'),(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0'),
                    $credential['locker_id'],$cabinet['cabinet_id']])->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows || count($rows)!==(int)$this->q('SELECT count(*) FROM cabinet_box WHERE cabinet_id=?',[$cabinet['cabinet_id']])->fetchColumn()
                || count(array_unique(array_column($rows,'body_id')))!==(int)$this->q('SELECT count(*) FROM cabinet_body WHERE cabinet_id=?',[$cabinet['cabinet_id']])->fetchColumn()) {
                throw new Failure(409,'CABINET_CONFIG_INCOMPLETE','Cabinet boxes are not fully mapped to inventory.');
            }
            $bodies=[]; $models=[];
            foreach ($rows as $row) {
                if ($row['lock_addr']===null || $row['box_addr']===null
                    || (int)$row['lock_addr']!==(int)$row['board_address'] || (int)$row['box_addr']!==(int)$row['door_address']
                    || $row['protocol_profile']!==$row['board_profile']) {
                    throw new Failure(409,'CABINET_ADDRESS_MISMATCH','Cabinet configuration and installed inventory differ.');
                }
                if ($row['protocol_profile']==='UNVERIFIED'
                    || ($row['protocol_profile']==='SIMULATED_24' && !in_array(getenv('APP_ENV'),['test','development'],true))) {
                    throw new Failure(409,'CABINET_PROFILE_UNVERIFIED','Controller profile is not commissioned for this environment.');
                }
                $bodyId=(string)$row['body_id'];
                if (!isset($bodies[$bodyId])) {
                    $bodies[$bodyId]=['bodyId'=>$bodyId,'sequence'=>$row['sequence'],'displaySequence'=>(int)$row['display_sequence'],
                        'cabinetType'=>$row['body_model_name'],'model'=>(string)$row['body_model_id'],
                        'lockAddr'=>(int)$row['lock_addr'],'protocolProfile'=>$row['protocol_profile'],'boxes'=>[]];
                }
                $bodies[$bodyId]['boxes'][]=['boxId'=>(string)$row['box_id'],'boxAddr'=>(int)$row['box_addr'],
                    'row'=>(int)$row['row'],'column'=>(int)$row['column'],'model'=>(string)$row['box_model_id'],
                    'boxModelId'=>(string)$row['box_model_id'],'boxModelName'=>$row['box_model_name'],
                    'dimensionsMm'=>['width'=>(int)$row['width_mm'],'height'=>(int)$row['height_mm'],
                        'depth'=>(int)$row['depth_mm']],'maxWeightG'=>(int)$row['max_weight_g'],
                    'isAllocable'=>'0','blocked'=>1];
                $models[(string)$row['box_model_id']]=['boxModelId'=>(string)$row['box_model_id'],
                    'boxModelName'=>$row['box_model_name'],'sizeClass'=>$row['size_class'],'availableCount'=>0];
            }
            return ['revision'=>(int)$cabinet['version'],'boxConfig'=>[
                'cabinetId'=>(string)$cabinet['cabinet_id'],'address'=>$cabinet['address'] ?: $cabinet['address_text'],
                'zipcode'=>$cabinet['zipcode'] ?: '', 'cabinets'=>array_values($bodies)],
                'boxModels'=>array_values($models)];
        });
    }

    public function authenticate(Request $request,string $path,string $method='GET'): array
    {
        $key=$request->header('x-device-key-id','');
        $time=$request->header('x-device-timestamp','');
        $nonce=$request->header('x-device-nonce','');
        $signature=$request->header('x-device-signature','');
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9._-]{1,80}$/D',$key)
            || !is_string($time) || !preg_match('/^[0-9]{10}$/D',$time) || abs(time()-(int)$time)>60
            || !is_string($nonce) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8a-f][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$nonce)
            || !is_string($signature)) { throw new Failure(401,'DEVICE_AUTH_REQUIRED','Valid signed device headers are required.'); }
        $sig=base64_decode($signature,true);
        if ($sig===false || strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES) { throw new Failure(401,'DEVICE_AUTH_REQUIRED','Valid device signature required.'); }
        $credential=$this->q("SELECT d.id,d.locker_id,dc.public_key FROM device_credentials dc
            JOIN locker_devices d ON d.id=dc.device_id JOIN lockers k ON k.id=d.locker_id
            JOIN locations l ON l.id=k.location_id
            WHERE dc.key_id=? AND dc.revoked_at IS NULL AND dc.valid_from<=now()
              AND (dc.expires_at IS NULL OR dc.expires_at>now()) AND d.status='ACTIVE'
              AND l.organization_id=? AND k.capabilities->>'physical_commands_enabled'='true'
              AND k.capabilities->>'synthetic'='false'",[$key,(string)(getenv('ZPX_ORGANIZATION_ID') ?: '0')])->fetch(PDO::FETCH_ASSOC);
        if (!$credential) { throw new Failure(401,'DEVICE_AUTH_REQUIRED','Device credential unavailable.'); }
        $public=base64_decode((string)$credential['public_key'],true);
        $canonical=$method."\n".$path."\n".$time."\n".$nonce."\n".hash('sha256',$request->getInput());
        if ($public===false || strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($sig,$canonical,$public)) {
            throw new Failure(401,'DEVICE_AUTH_REQUIRED','Device signature rejected.');
        }
        try { $this->q('INSERT INTO device_request_nonces(device_id,nonce) VALUES (?,?::uuid)',[$credential['id'],$nonce]); }
        catch (PDOException $e) {
            if ($e->getCode()==='23505') { throw new Failure(409,'DEVICE_REQUEST_REPLAY','Signed device request already used.'); }
            throw $e;
        }
        $this->q("DELETE FROM device_request_nonces WHERE observed_at<now()-interval '10 minutes'");
        return $credential;
    }
}
