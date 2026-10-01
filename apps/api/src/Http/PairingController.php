<?php
declare(strict_types=1);
namespace Zpx\Http;

use think\Request;
use think\Response;
use Zpx\Custody\Pairings;
use Zpx\Identity\{Failure,Secrets,Service as Identity};
use Zpx\Infrastructure\Database\Connection;

final class PairingController
{
    private static function input(Request $request): array
    {
        if (strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') {
            throw new Failure(415,'JSON_REQUIRED','Send a JSON request.');
        }
        $raw=$request->getInput();
        if (strlen($raw)>4096) { throw new Failure(413,'REQUEST_TOO_LARGE','Request is too large.'); }
        try { $object=json_decode($raw,false,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new Failure(400,'INVALID_JSON','Request is not valid JSON.'); }
        if (!$object instanceof \stdClass) { throw new Failure(422,'INVALID_INPUT','Send a JSON object.'); }
        return json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    }
    public static function handle(Request $request,string $action,string $pairingId=''): Response
    {
        $requestId=Secrets::uuid();
        try {
            $method=$action==='poll'?'GET':'POST';
            if ($request->method(true)!==$method) { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
            $origin=$request->header('origin','');
            if ($origin!=='' && !in_array($origin,explode(',',getenv('AUTH_ALLOWED_ORIGINS') ?: ''),true)) {
                throw new Failure(403,'ORIGIN_REJECTED','Request origin is not allowed.');
            }
            $db=Connection::fromEnvironment(); $crypto=new Secrets();
            $pairings=new Pairings($db,$crypto);
            if ($action==='create') { return Reply::json(201,$pairings->create($request,self::input($request)),$requestId); }
            if ($action==='poll') { return Reply::json(200,$pairings->poll($request,$pairingId),$requestId); }
            $identity=new Identity($db,$crypto);
            $cookie=$request->cookie('zpx_delivery_session',''); $authorization=$request->header('authorization','');
            if (!is_string($cookie) || !is_string($authorization)) { throw new Failure(401,'AUTH_REQUIRED','Sign in to continue.'); }
            if ($cookie!=='' && $authorization!=='') { throw new Failure(400,'AMBIGUOUS_AUTH','Use one authentication method.'); }
            $kind='BROWSER'; $token=$cookie;
            if ($token==='' && preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match)) { $kind='NATIVE'; $token=$match[1]; }
            if ($token==='') { throw new Failure(401,'AUTH_REQUIRED','Sign in to continue.'); }
            $user=(string)$identity->authenticate($token,$kind)['user_id'];
            if ($kind==='BROWSER') { $identity->csrf($token,$request->header('x-csrf-token','')); }
            $identity->limit('pairing:'.$user,30);
            return Reply::json(200,$pairings->approve($user,$pairingId,self::input($request),
                $request->header('idempotency-key','')),$requestId);
        } catch (Failure $e) {
            return Reply::json($e->status,['code'=>$e->errorCode,'message'=>$e->getMessage(),'request_id'=>$requestId,'retryable'=>$e->status===503],$requestId);
        }
    }
}
