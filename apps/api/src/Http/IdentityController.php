<?php
declare(strict_types=1);
namespace Zpx\Http;

use think\Request;
use think\Response;
use Zpx\Identity\{Failure,Input,Secrets,Service};
use Zpx\Infrastructure\Database\Connection;

final class IdentityController
{
    public static function handle(Request $request, string $action): Response
    {
        $id=Secrets::uuid();
        try {
            $method=$request->method(true);
            if ($method!==($action==='me'?'GET':'POST')) { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
            $origin=$request->header('origin','');
            $allowed=explode(',',getenv('AUTH_ALLOWED_ORIGINS') ?: '');
            if ($origin!=='' && !in_array($origin,$allowed,true)) { throw new Failure(403,'ORIGIN_REJECTED','Request origin is not allowed.'); }
            $input=[];
            if ($method==='POST') {
                if (strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') { throw new Failure(415,'JSON_REQUIRED','Send a JSON request.'); }
                $raw=$request->getInput();
                if (strlen($raw)>16384) { throw new Failure(413,'REQUEST_TOO_LARGE','Request is too large.'); }
                try { $object=json_decode($raw,false,32,JSON_THROW_ON_ERROR); }
                catch (\JsonException $e) { throw new Failure(400,'INVALID_JSON','Request is not valid JSON.'); }
                if (!$object instanceof \stdClass) { throw new Failure(422,'INVALID_INPUT','Send a JSON object.'); }
                $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            }
            $secrets=new Secrets();
            $service=new Service(Connection::fromEnvironment(),$secrets);
            // Never trust forwarded IP headers without a separately configured trusted proxy.
            if ($method==='POST') { $service->limit('ip:'.$request->server('REMOTE_ADDR','unknown'),100); }
            $cookie=$request->cookie('zpx_delivery_session','');
            if (!is_string($cookie)) { throw new Failure(401,'AUTH_REQUIRED','Sign in to continue.'); }
            $authorization=$request->header('authorization','');
            $session=null; $token=''; $kind='NATIVE';
            if (in_array($action,['me','logout'],true)) {
                if ($cookie!=='' && $authorization!=='') { throw new Failure(400,'AMBIGUOUS_AUTH','Use one authentication method.'); }
                if ($cookie!=='') { $token=$cookie; $kind='BROWSER'; }
                elseif (preg_match('/^Bearer ([a-f0-9]{64})$/D',$authorization,$match)) { $token=$match[1]; }
                $session=$service->authenticate($token,$kind);
                if ($method==='POST' && $kind==='BROWSER') { $service->csrf($token,$request->header('x-csrf-token','')); }
            }
            if ($action==='logout') {
                Input::fields($input,[]);
                Input::text($request->header('idempotency-key',''),16,100);
            }
            $body=match($action) {
                'register'=>$service->register($input), 'login'=>$service->login($input),
                'challenges'=>$service->challenge($input), 'verify-contact'=>$service->verify($input),
                'refresh'=>$service->refresh($input), 'logout'=>$service->logout($session,$request->header('idempotency-key','')),
                'me'=>$service->profile($session['user_id']),
            };
            $headers=[];
            if (isset($body['_cookie'])) {
                $headers['Set-Cookie']=self::cookie($body['_cookie'],28800);
                unset($body['_cookie']);
            }
            if ($action==='me' && $kind==='BROWSER') { $body['csrf_token']=$secrets->digest('csrf',$token); }
            if ($action==='logout' && $kind==='BROWSER') { $headers['Set-Cookie']=self::cookie('',0); }
            return Reply::json($action==='register'?201:200,$body,$id,$headers);
        } catch (Failure $error) {
            $headers=$error->status===405?['Allow'=>$action==='me'?'GET':'POST']:[];
            if ($error->status===429) { $headers['Retry-After']='600'; }
            return Reply::json($error->status,['code'=>$error->errorCode,'message'=>$error->getMessage(),'correlation_id'=>$id,'retryable'=>in_array($error->status,[429,503],true)],$id,$headers);
        }
    }
    private static function cookie(string $token, int $age): string
    {
        $secure=getenv('APP_ENV')==='development'?'':'; Secure';
        return 'zpx_delivery_session='.$token.'; Path=/api/delivery/v1; Max-Age='.$age.'; HttpOnly; SameSite=Strict'.$secure;
    }
}
