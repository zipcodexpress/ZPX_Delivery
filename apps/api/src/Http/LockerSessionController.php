<?php
declare(strict_types=1);
namespace Zpx\Http;
use think\{Request,Response};
use Zpx\Custody\{PhysicalSessions,DeviceCommands};
use Zpx\Identity\{Failure,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\{Connection,Transaction};

final class LockerSessionController
{
    public static function handle(Request $request,string $action,string $session=''): Response {
        $id=Secrets::uuid();
        try {
            $method=in_array($action,['status','device-status'],true)?'GET':'POST';
            if ($request->method(true)!==$method) { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
            $origin=$request->header('origin','');
            if ($origin!=='' && !in_array($origin,explode(',',getenv('AUTH_ALLOWED_ORIGINS') ?: ''),true)) { throw new Failure(403,'ORIGIN_REJECTED','Origin unavailable.'); }
            $db=Connection::fromEnvironment();$crypto=new Secrets();$service=new PhysicalSessions($db,$crypto);
            if (in_array($action,['device-confirm','device-didnt-deposit','device-retry','device-scan'],true)) {
                if(strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') throw new Failure(415,'JSON_REQUIRED','Send JSON.');
                $raw=$request->getInput();if(strlen($raw)>4096) throw new Failure(413,'REQUEST_TOO_LARGE','Request too large.');
                try{$object=json_decode($raw,false,16,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new Failure(400,'INVALID_JSON','Invalid JSON.');}
                if(!$object instanceof \stdClass) throw new Failure(422,'INVALID_INPUT','Send an object.');
                $suffix=match($action){'device-confirm'=>'confirm','device-retry'=>'retry','device-scan'=>'scan',default=>'didnt-deposit'};
                $path='/api/delivery/v1/devices/me/'.($suffix==='scan'?'pairings':'sessions').'/'.$session.'/'.$suffix;
                $device=(new Transaction($db))->run(fn()=>(new DeviceCommands($db))->authenticate($request,$path,'POST'));
                $key=$request->header('idempotency-key','');
                $body=match($suffix){'retry'=>$service->retryDeposit((string)$device['id'],$session,(array)$object,$key),'scan'=>$service->scanAtTerminal((string)$device['id'],$session,(array)$object,$key),default=>$service->deviceDecision((string)$device['id'],$session,(array)$object,$key,$suffix==='didnt-deposit')};
            } elseif ($action==='device-status') {
                $body=(new Transaction($db))->run(function () use ($db,$service,$request,$session) {
                    $device=(new DeviceCommands($db))->authenticate($request,'/api/delivery/v1/devices/me/sessions/'.$session);
                    return $service->status($session,null,(string)$device['id']);
                });
            } else {
                $identity=new Identity($db,$crypto);$cookie=$request->cookie('zpx_delivery_session','');$authorization=$request->header('authorization','');
                if (!is_string($cookie) || !is_string($authorization) || ($cookie!=='' && $authorization!=='')) { throw new Failure(400,'AMBIGUOUS_AUTH','Use one authentication method.'); }
                $kind='BROWSER';$token=$cookie;
                if ($token==='' && preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match)) { $kind='NATIVE';$token=$match[1]; }
                if ($token==='') { throw new Failure(401,'AUTH_REQUIRED','Sign in on your phone.'); }
                $user=(string)$identity->authenticate($token,$kind)['user_id'];
                if ($method==='POST' && $kind==='BROWSER') { $identity->csrf($token,$request->header('x-csrf-token','')); }
                $identity->limit('physical-session:'.$user,60);
                if ($method==='GET') { $body=$service->status($session,$user); }
                else {
                    if (strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') { throw new Failure(415,'JSON_REQUIRED','Send JSON.'); }
                    $raw=$request->getInput();if (strlen($raw)>4096) { throw new Failure(413,'REQUEST_TOO_LARGE','Request too large.'); }
                    try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); }
                    catch (\JsonException $e) { throw new Failure(400,'INVALID_JSON','Invalid JSON.'); }
                    if (!$object instanceof \stdClass) { throw new Failure(422,'INVALID_INPUT','Send an object.'); }
                    $input=(array)$object;$key=$request->header('idempotency-key','');
                    $body=match($action) {'prepare'=>$service->prepare($user,$input,$key),'confirm'=>$service->confirm($user,$session,$input,$key),'didnt-deposit'=>$service->didntDeposit($user,$session,$input,$key),'grant'=>$service->issueGrant($user,$input,$key),default=>throw new Failure(404,'NOT_FOUND','Endpoint unavailable.')};
                }
            }
            return Reply::json(200,$body,$id);
        } catch (Failure $e) { return Reply::json($e->status,['code'=>$e->errorCode,'message'=>$e->getMessage(),'retryable'=>$e->status===503],$id); }
    }
}
