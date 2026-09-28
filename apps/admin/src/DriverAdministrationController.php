<?php
declare(strict_types=1);
namespace ZpxAdmin;

use think\Request;
use think\Response;
use Zpx\Http\Reply;
use Zpx\Identity\{Failure, Input, Secrets, Service};
use Zpx\Infrastructure\Database\Connection;

final class DriverAdministrationController
{
    public static function handle(Request $request, string $action, string $driver=''): Response
    {
        $id=Secrets::uuid();
        try {
            $method=$request->method(true);
            if ($method!==(in_array($action,['list','detail'],true)?'GET':'POST')) { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
            $origin=$request->header('origin','');
            if ($origin!=='' && !in_array($origin,explode(',',getenv('AUTH_ALLOWED_ORIGINS') ?: ''),true)) {
                throw new Failure(403,'ORIGIN_REJECTED','Request origin is not allowed.');
            }
            $db=Connection::fromEnvironment(); $crypto=new Secrets(); $identity=new Service($db,$crypto);
            $cookie=$request->cookie('zpx_delivery_session',''); $auth=$request->header('authorization','');
            if (!is_string($cookie) || ($cookie!=='' && $auth!=='')) { throw new Failure(400,'AMBIGUOUS_AUTH','Use one authentication method.'); }
            $token=$cookie; $kind='BROWSER';
            if ($token==='' && preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m)) { $token=$m[1]; $kind='NATIVE'; }
            $session=$identity->authenticate($token,$kind); $actor=(string)$session['user_id'];
            $service=new DriverAdministration($db,$crypto);
            if ($action==='list' || $action==='detail') {
                $body=$action==='list' ? $service->list($actor,(string)$request->get('cursor','')) : $service->detail($actor,$driver);
            } else {
                if ($kind==='BROWSER') { $identity->csrf($token,$request->header('x-csrf-token','')); }
                $identity->limit('admin-driver:'.$actor,50);
                if (strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') {
                    throw new Failure(415,'JSON_REQUIRED','Send a JSON request.');
                }
                $raw=$request->getInput();
                if (strlen($raw)>16384) { throw new Failure(413,'REQUEST_TOO_LARGE','Request is too large.'); }
                try { $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
                catch (\JsonException $e) { throw new Failure(400,'INVALID_JSON','Request is not valid JSON.'); }
                if (!is_array($input) || array_is_list($input)) { throw new Failure(422,'INVALID_INPUT','Send a JSON object.'); }
                Input::fields($input,['action','reason']);
                $match=$request->header('if-match','');
                if (!preg_match('/^"(0|[1-9][0-9]*)"$/D',$match,$version)) {
                    throw new Failure(428,'VERSION_REQUIRED','Send a quoted driver version in If-Match.');
                }
                $input['expected_version']=$version[1];
                $key=Input::text($request->header('idempotency-key',''),16,100);
                $body=$service->transition($actor,$driver,$input,$key);
            }
            return Reply::json(200,$body,$id);
        } catch (Failure $error) {
            return Reply::json($error->status,['code'=>$error->errorCode,'message'=>$error->getMessage(),
                'correlation_id'=>$id,'retryable'=>$error->status===503],$id,
                $error->status===405?['Allow'=>in_array($action,['list','detail'],true)?'GET':'POST']:[]);
        }
    }
}
