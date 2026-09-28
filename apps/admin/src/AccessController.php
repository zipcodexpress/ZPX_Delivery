<?php
declare(strict_types=1);
namespace ZpxAdmin;

use think\Request;
use think\Response;
use Zpx\Http\Reply;
use Zpx\Identity\{Failure, Secrets, Service};
use Zpx\Infrastructure\Database\Connection;

final class AccessController
{
    public static function handle(Request $request): Response
    {
        $id = Secrets::uuid();
        try {
            if ($request->method(true) !== 'GET') {
                throw new Failure(405, 'METHOD_NOT_ALLOWED', 'Use GET.');
            }
            $origin = $request->header('origin', '');
            if ($origin !== '' && !in_array($origin, explode(',', getenv('AUTH_ALLOWED_ORIGINS') ?: ''), true)) {
                throw new Failure(403, 'ORIGIN_REJECTED', 'Request origin is not allowed.');
            }
            $cookie = $request->cookie('zpx_delivery_session', '');
            $auth = $request->header('authorization', '');
            if (!is_string($cookie) || ($cookie !== '' && $auth !== '')) {
                throw new Failure(400, 'AMBIGUOUS_AUTH', 'Use one authentication method.');
            }
            $token = $cookie;
            $kind = 'BROWSER';
            if ($token === '' && preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $match)) {
                $token = $match[1]; $kind = 'NATIVE';
            }
            $db = Connection::fromEnvironment();
            $session = (new Service($db, new Secrets()))->authenticate($token, $kind);
            return Reply::json(200, (new Access($db))->forUser((string)$session['user_id']), $id);
        } catch (Failure $error) {
            return Reply::json($error->status, ['code'=>$error->errorCode,'message'=>$error->getMessage(),
                'correlation_id'=>$id,'retryable'=>false], $id, $error->status===405 ? ['Allow'=>'GET'] : []);
        }
    }
}
