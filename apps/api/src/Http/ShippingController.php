<?php
declare(strict_types=1);
namespace Zpx\Http;
use think\Request;
use think\Response;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\Shipping\Service;
use Zpx\Infrastructure\Database\Connection;

final class ShippingController
{
    public static function handle(Request $request,string $action,string $shipment=''): Response
    {
        $requestId=Secrets::uuid();
        try {
            $method=$request->method(true);
            $expected=in_array($action,['lookup','wallet-methods','wallet-history','locations','get','tracking','operations','operations-search','operations-get','operations-tracking','operations-payments','pdf','pending-payment'],true)?'GET':'POST';
            if ($action==='shipments') { $expected=in_array($method,['GET','POST'],true)?$method:'GET, POST'; }
            if ($method!==$expected) { throw new Failure(405,'METHOD_NOT_ALLOWED','Unsupported method.'); }
            $origin=$request->header('origin','');
            if ($origin!=='' && !in_array($origin,explode(',',getenv('AUTH_ALLOWED_ORIGINS') ?: ''),true)) { throw new Failure(403,'ORIGIN_REJECTED','Request origin is not allowed.'); }
            $db=Connection::fromEnvironment(); $crypto=new Secrets(); $identity=new Identity($db,$crypto); $shipping=new Service($db,$crypto);
            $cookie=$request->cookie('zpx_delivery_session',''); $auth=$request->header('authorization','');
            if (!is_string($cookie)) { throw new Failure(401,'AUTH_REQUIRED','Sign in to continue.'); }
            if ($cookie!=='' && $auth!=='') { throw new Failure(400,'AMBIGUOUS_AUTH','Use one authentication method.'); }
            $token=$cookie; $kind='BROWSER';
            if ($cookie==='' && preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m)) { $token=$m[1]; $kind='NATIVE'; }
            $session=$identity->authenticate($token,$kind); $user=(string)$session['user_id'];
            $input=[]; $key=''; $match=$request->header('if-match','');
            if ($method==='POST') {
                if ($kind==='BROWSER') { $identity->csrf($token,$request->header('x-csrf-token','')); }
                $identity->limit('shipping:'.$user,100);
                if (strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/json') { throw new Failure(415,'JSON_REQUIRED','Send a JSON request.'); }
                $raw=$request->getInput();
                if (strlen($raw)>16384) { throw new Failure(413,'REQUEST_TOO_LARGE','Request is too large.'); }
                try { $object=json_decode($raw,false,32,JSON_THROW_ON_ERROR); }
                catch (\JsonException $e) { throw new Failure(400,'INVALID_JSON','Request is not valid JSON.'); }
                if (!$object instanceof \stdClass) { throw new Failure(422,'INVALID_INPUT','Send a JSON object.'); }
                $input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                $key=Input::text($request->header('idempotency-key',''),16,100);
            }
            $operations=str_starts_with($action,'operations');
            if ($operations && !array_intersect($identity->profile($user)['roles'],['ADMIN','DISPATCHER','HUB_STAFF','HUB_SUPERVISOR'])) { throw new Failure(403,'ACCESS_DENIED','A staff assignment is required.'); }
            $view=$operations?'operations':'customer';
            if ($action==='pdf') {
                return Response::create($shipping->pdf($user,$shipment),'html',200)->header(['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="zpx-test-label.pdf"','Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff','X-Request-ID'=>$requestId]);
            }
            if (in_array($action,['labels','hosted-session','wallet-manage'],true)) { Input::fields($input,[]); }
            if ($action==='reconcile-payment') { Input::fields($input,[],['transaction_id']); if (isset($input['transaction_id'])) { Input::text($input['transaction_id'],1,20); } }
            $body=match($action) {
                'locations'=>$shipping->locations(),
                'profile-update'=>$identity->updateProfile($user,$input),
                'lookup'=>$shipping->lookup($user,Input::text($request->get('reference',''),1,64)),
                'wallet-methods'=>(new \Zpx\Payments\Wallet($db,$crypto))->methods($user),
                'wallet-history'=>(new \Zpx\Payments\Wallet($db,$crypto))->history($user,Input::text($request->get('cursor',''),0,18)),
                'wallet-manage'=>(new \Zpx\Payments\Wallet($db,$crypto))->manage($user),
                'shipments'=>$method==='POST'?$shipping->create($user,$input,$key):$shipping->list($user,Input::text($request->get('view','sending'),1,20),Input::text($request->get('cursor',''),0,18)),
                'operations-payments'=>$shipping->paymentHistory($user,$shipment),
                'operations'=>$shipping->list($user,'operations',Input::text($request->get('cursor',''),0,18)),
                'operations-search'=>$shipping->searchPackages($user,Input::text($request->get('q',''),1,100)),
                'get','operations-get'=>$shipping->get($user,$shipment,$view),
                'tracking','operations-tracking'=>$shipping->tracking($user,$shipment,$view),
                'hosted-session'=>(new \Zpx\Payments\HostedCheckout($db,$crypto))->prepare($user,$shipment),
                'reconcile-payment'=>(new \Zpx\Payments\HostedCheckout($db,$crypto))->reconcile($user,$shipment,$input['transaction_id'] ?? null),
                'payment'=>$shipping->payment($user,$shipment,$input,$key,$match),
                'pending-payment'=>$shipping->pendingPayment($user,$shipment),
                'confirm-payment'=>$shipping->confirmPayment($user,$shipment,$input,$key),
                'labels'=>$shipping->label($user,$shipment,$key),
                'quotes'=>$shipping->quote($user,$shipment,$input,$key,$match),
                'cancel'=>$shipping->cancel($user,$shipment,$input,$key,$match),
                'claim-challenge'=>$shipping->claimChallenge($user,$input,$key),
                'claim'=>$shipping->claim($user,$input,$key),
            };
            return Reply::json(($action==='shipments' && $method==='POST') || $action==='quotes'?201:200,$body,$requestId);
        } catch (Failure $e) {
            return Reply::json($e->status,['code'=>$e->errorCode,'message'=>$e->getMessage(),'correlation_id'=>$requestId,'retryable'=>in_array($e->status,[429,503],true)],$requestId,$e->status===429?['Retry-After'=>'600']:[]);
        }
    }
}
