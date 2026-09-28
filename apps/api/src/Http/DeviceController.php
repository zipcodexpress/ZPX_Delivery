<?php
declare(strict_types=1);
namespace Zpx\Http;

use think\Request;
use think\Response;
use Zpx\Custody\DeviceCommands;
use Zpx\Identity\{Failure,Secrets};
use Zpx\Infrastructure\Database\Connection;

final class DeviceController
{
    public static function commands(Request $request): Response
    {
        $id=Secrets::uuid();
        try { return Reply::json(200,(new DeviceCommands(Connection::fromEnvironment()))->poll($request),$id); }
        catch (Failure $e) { return Reply::json($e->status,['code'=>$e->errorCode,'message'=>$e->getMessage(),'request_id'=>$id,'retryable'=>false],$id); }
    }
}
