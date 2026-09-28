<?php
declare(strict_types=1);
namespace Zpx\Custody;

use DateTimeImmutable;
use PDO;
use PDOException;
use think\Request;
use Zpx\Identity\{Failure,Input};
use Zpx\Infrastructure\Database\Transaction;
use Zpx\Shipping\Service as Shipping;

/** Durable signed terminal telemetry. It never confirms a physical handoff by itself. */
final class DeviceEvents
{
    private const PATH='/api/delivery/v1/devices/me/events';
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): \PDOStatement { $q=$this->db->prepare($sql); $q->execute($args); return $q; }
    private function uuid(string $value): string {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8a-f][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$value)) {
            throw new Failure(422,'INVALID_INPUT','Invalid event identifier.');
        }
        return $value;
    }

    public function record(Request $request): array
    {
        if ($request->method(true)!=='POST') { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
        if (strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') { throw new Failure(415,'JSON_REQUIRED','Send a JSON request.'); }
        $raw=$request->getInput();
        if (strlen($raw)>8192) { throw new Failure(413,'REQUEST_TOO_LARGE','Request is too large.'); }
        try { $input=json_decode($raw,true,24,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new Failure(400,'INVALID_JSON','Request is not valid JSON.'); }
        if (!is_array($input) || array_is_list($input)) { throw new Failure(422,'INVALID_INPUT','Send a JSON object.'); }
        Input::fields($input,['event_id','boot_id','sequence','command_id','session_id','event_type','observed_at','address','ownership_generation'],['frame_hash']);
        $event=$this->uuid(Input::text($input['event_id'],36,36));
        $boot=$this->uuid(Input::text($input['boot_id'],36,36));
        $command=$this->uuid(Input::text($input['command_id'],36,36));
        $session=Shipping::id($input['session_id']);
        if (!is_int($input['sequence']) || $input['sequence']<1 || !is_int($input['ownership_generation']) || $input['ownership_generation']<1
            || !in_array($input['event_type'],['DISPATCH_RECORDED','OPEN_OBSERVED','CLOSE_OBSERVED','UNKNOWN'],true)) {
            throw new Failure(422,'INVALID_INPUT','Invalid device event fields.');
        }
        $observed=Input::text($input['observed_at'],20,40);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D',$observed)) {
            throw new Failure(422,'INVALID_INPUT','Use a UTC observation timestamp.');
        }
        try { $observedTime=new DateTimeImmutable($observed); } catch (\Exception) { throw new Failure(422,'INVALID_INPUT','Invalid observation timestamp.'); }
        if ($observedTime->getTimestamp()>time()+60) { throw new Failure(422,'INVALID_INPUT','Observation timestamp is in the future.'); }
        if (!is_array($input['address']) || array_is_list($input['address'])) { throw new Failure(422,'INVALID_INPUT','Invalid physical address.'); }
        Input::fields($input['address'],['locker_id','board_address','door_address']);
        $address=['locker_id'=>Shipping::id($input['address']['locker_id'])];
        foreach (['board_address','door_address'] as $field) {
            if (!is_int($input['address'][$field]) || $input['address'][$field]<0 || $input['address'][$field]>255) {
                throw new Failure(422,'INVALID_INPUT','Invalid physical address.');
            }
            $address[$field]=$input['address'][$field];
        }
        $frame=$input['frame_hash'] ?? null;
        if ($frame!==null && (!is_string($frame) || !preg_match('/^[a-f0-9]{64}$/D',$frame))) { throw new Failure(422,'INVALID_INPUT','Invalid frame hash.'); }
        if (in_array($input['event_type'],['OPEN_OBSERVED','CLOSE_OBSERVED'],true) && $frame===null) {
            throw new Failure(422,'FRAME_HASH_REQUIRED','Door observations require a frame hash.');
        }
        return (new Transaction($this->db))->run(function () use ($request,$input,$event,$boot,$command,$session,$observed,$observedTime,$address,$frame) {
            $device=(new DeviceAuth($this->db))->authenticate($request,'POST',self::PATH);
            $row=$this->q("SELECT dc.id,dc.created_at,dc.command_payload,ls.status AS session_status
                FROM device_commands dc JOIN locker_sessions ls ON ls.id=dc.session_id
                WHERE dc.command_uuid=?::uuid AND dc.device_id=? AND dc.session_id=? AND ls.action='FINAL_DEPOSIT'
                  AND dc.dispatched_at IS NOT NULL AND dc.payload_hash IS NOT NULL AND dc.command_payload IS NOT NULL
                FOR UPDATE OF dc",[$command,$device['id'],$session])->fetch(PDO::FETCH_ASSOC);
            if (!$row) { throw new Failure(404,'COMMAND_NOT_FOUND','Command is unavailable for this device and session.'); }
            $payload=json_decode($row['command_payload'],true,24,JSON_THROW_ON_ERROR);
            if ($payload['address']!=$address || $payload['ownership_generation']!==$input['ownership_generation']
                || $payload['session_id']!==$session || $payload['command_id']!==$command) {
                throw new Failure(409,'COMMAND_CONTEXT_MISMATCH','Observation does not match the dispatched command.');
            }
            if ($observedTime->getTimestamp() < strtotime($row['created_at'])-60) {
                throw new Failure(422,'INVALID_INPUT','Observation predates the command.');
            }
            $normalized=['event_id'=>$event,'boot_id'=>$boot,'sequence'=>$input['sequence'],'command_id'=>$command,
                'session_id'=>$session,'event_type'=>$input['event_type'],'observed_at'=>$observed,'address'=>$address,
                'ownership_generation'=>$input['ownership_generation'],'frame_hash'=>$frame];
            $digest=hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
            $existing=$this->q("SELECT evidence->>'payload_digest' FROM device_events WHERE device_id=? AND external_event_id=?",[$device['id'],$event])->fetchColumn();
            if ($existing!==false) {
                if (!hash_equals((string)$existing,$digest)) { throw new Failure(409,'DEVICE_EVENT_CONFLICT','Event ID was already used for different telemetry.'); }
                return ['event_id'=>$event,'result'=>'DUPLICATE','session_status'=>$row['session_status'],'custody_transferred'=>false];
            }
            $evidence=$normalized+['payload_digest'=>$digest,'terminal_authenticated'=>true,'board_frame_unverified'=>true,'custody_transferred'=>false];
            try {
                $this->q("INSERT INTO device_events(device_id,command_id,external_event_id,occurred_at,evidence,boot_id,boot_sequence)
                    VALUES (?,?,?,?::timestamptz,?::jsonb,?::uuid,?)",
                    [$device['id'],$row['id'],$event,$observed,json_encode($evidence,JSON_THROW_ON_ERROR),$boot,$input['sequence']]);
            } catch (PDOException $e) {
                if ($e->getCode()==='23505') { throw new Failure(409,'DEVICE_SEQUENCE_CONFLICT','Device boot sequence was already used.'); }
                throw $e;
            }
            return ['event_id'=>$event,'result'=>'RECORDED','session_status'=>$row['session_status'],'custody_transferred'=>false];
        });
    }
}
