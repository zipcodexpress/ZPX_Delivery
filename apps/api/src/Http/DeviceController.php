<?php
declare(strict_types=1);
namespace Zpx\Http;

use think\Request;
use think\Response;
use Zpx\Custody\DeviceCommands;
use Zpx\Custody\DevicePairings;
use Zpx\Custody\DeviceEvents;
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

    public static function pairings(Request $request): Response
    {
        $id=Secrets::uuid();
        try { $db=Connection::fromEnvironment(); return Reply::json(201,(new DevicePairings($db,new Secrets()))->create($request),$id); }
        catch (Failure $e) { return Reply::json($e->status,['code'=>$e->errorCode,'message'=>$e->getMessage(),'request_id'=>$id,'retryable'=>false],$id); }
    }

    public static function events(Request $request): Response
    {
        $id=Secrets::uuid();
        try { return Reply::json(200,(new DeviceEvents(Connection::fromEnvironment()))->record($request),$id); }
        catch (Failure $e) { return Reply::json($e->status,['code'=>$e->errorCode,'message'=>$e->getMessage(),'request_id'=>$id,'retryable'=>false],$id); }
    }
}
