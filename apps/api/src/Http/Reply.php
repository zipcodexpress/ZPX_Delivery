<?php
declare(strict_types=1);
namespace Zpx\Http;
use think\Response;

final class Reply
{
    public static function json(int $status, array $body, string $id, array $headers = []): Response
    {
        return Response::create($body, 'json', $status)->header($headers + [
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Request-ID' => $id,
        ]);
    }
}
