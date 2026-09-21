<?php
declare(strict_types=1);
namespace Zpx\Http;

use think\Request;
use think\Response;
use Zpx\Identity\{Failure,Input,Secrets,Service as Identity};
use Zpx\HubReceiving\Service;
use Zpx\Infrastructure\Database\Connection;

final class HubReceivingController
{
    public static function handle(Request $request, string $action, string $sessionId = ''): Response
    {
        $requestId = Secrets::uuid();
        try {
            $method = $request->method(true);
            $expected = match ($action) {
                'open' => 'POST',
                'scan' => 'POST',
                'close' => 'POST',
                'status' => 'GET',
                default => 'GET',
            };
            if ($method !== $expected) { throw new Failure(405, 'METHOD_NOT_ALLOWED', 'Unsupported method.'); }

            $origin = $request->header('origin', '');
            if ($origin !== '' && !in_array($origin, explode(',', getenv('AUTH_ALLOWED_ORIGINS') ?: ''), true)) {
                throw new Failure(403, 'ORIGIN_REJECTED', 'Request origin is not allowed.');
            }

            $db = Connection::fromEnvironment();
            $crypto = new Secrets();
            $identity = new Identity($db, $crypto);
            $service = new Service($db, $crypto);

            $cookie = $request->cookie('zpx_delivery_session', '');
            $auth = $request->header('authorization', '');
            if (!is_string($cookie)) { throw new Failure(401, 'AUTH_REQUIRED', 'Sign in to continue.'); }
            if ($cookie !== '' && $auth !== '') { throw new Failure(400, 'AMBIGUOUS_AUTH', 'Use one authentication method.'); }
            $token = $cookie;
            $kind = 'BROWSER';
            if ($cookie === '' && preg_match('/^Bearer ([a-f0-9]{64})$/D', $auth, $m)) { $token = $m[1]; $kind = 'NATIVE'; }
            if ($token === '') { throw new Failure(401, 'AUTH_REQUIRED', 'Sign in to continue.'); }

            $session = $identity->authenticate($token, $kind);
            $user = (string)$session['user_id'];

            $key = '';
            $input = [];
            if ($method === 'POST') {
                if ($kind === 'BROWSER') { $identity->csrf($token, $request->header('x-csrf-token', '')); }
                $identity->limit('hub-receiving:' . $user, 100);
                if (strtolower(trim(explode(';', $request->header('content-type', ''))[0])) !== 'application/json') {
                    throw new Failure(415, 'JSON_REQUIRED', 'Send a JSON request.');
                }
                $raw = $request->getInput();
                if (strlen($raw) > 16384) { throw new Failure(413, 'REQUEST_TOO_LARGE', 'Request is too large.'); }
                try { $object = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); }
                catch (\JsonException $e) { throw new Failure(400, 'INVALID_JSON', 'Request is not valid JSON.'); }
                if (!$object instanceof \stdClass) { throw new Failure(422, 'INVALID_INPUT', 'Send a JSON object.'); }
                $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                $key = Input::text($request->header('idempotency-key', ''), 16, 100);
            }

            $body = match ($action) {
                'open' => $service->openSession($user, $input, $key),
                'scan' => $service->receiveScan($user, $input, $key),
                'close' => $service->closeSession($user, $sessionId, $key),
                'status' => $service->getSession($user, $sessionId),
            };

            return Reply::json(200, $body, $requestId);
        } catch (Failure $e) {
            return Reply::json($e->status, [
                'code' => $e->errorCode,
                'message' => $e->getMessage(),
                'request_id' => $requestId,
                'retryable' => $e->status === 503,
            ], $requestId);
        }
    }
}
