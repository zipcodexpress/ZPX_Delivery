<?php
declare(strict_types=1);
namespace Zpx\Http;
use think\{Request,Response};
use Zpx\Identity\Failure;
use Zpx\Custody\CabinetConfigAuth;
use Zpx\Infrastructure\Database\Connection;
final class CabinetConfigController {
    public static function adminCards(Request $request): Response {
        try {
            if($request->method(true)!=='POST')throw new Failure(405,'METHOD_NOT_ALLOWED','Use POST.');
            if(strlen($request->getInput())>4096)throw new Failure(413,'REQUEST_TOO_LARGE','Request too large.');
            if(strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/x-www-form-urlencoded')throw new Failure(415,'FORM_REQUIRED','Use Terminal452 form encoding.');
            parse_str($request->getInput(),$input);
            \Zpx\Identity\Input::fields($input,['accessToken']);
            $token=\Zpx\Identity\Input::text($input['accessToken'],64,64);
            return Response::create((new CabinetConfigAuth(Connection::fromEnvironment()))->adminCards($token),'json',200)->header(['Cache-Control'=>'no-store']);
        }catch(Failure $e){return Response::create(['ret'=>1,'msg'=>$e->getMessage(),'data'=>[]],'json',$e->status)->header(['Cache-Control'=>'no-store']);}
    }
    public static function token(Request $request): Response {
        try {
            if($request->method(true)!=='POST')throw new Failure(405,'METHOD_NOT_ALLOWED','Use POST.');
            if(strlen($request->getInput())>4096)throw new Failure(413,'REQUEST_TOO_LARGE','Request too large.');
            if(strtolower(trim(explode(';',$request->header('content-type',''))[0]))!=='application/x-www-form-urlencoded')throw new Failure(415,'FORM_REQUIRED','Use Terminal452 form encoding.');
            parse_str($request->getInput(),$input);
            return Response::create((new CabinetConfigAuth(Connection::fromEnvironment()))->issue($input),'json',200)->header(['Cache-Control'=>'no-store']);
        }catch(Failure $e){return Response::create(['ret'=>1,'msg'=>$e->getMessage(),'data'=>(object)[]],'json',$e->status)->header(['Cache-Control'=>'no-store']);}
    }
}
