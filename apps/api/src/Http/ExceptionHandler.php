<?php
declare(strict_types=1);
namespace Zpx\Http;
use think\exception\Handle;
use think\Request;
use think\Response;
use Throwable;

final class ExceptionHandler extends Handle
{
    public function report(Throwable $exception): void
    {
        // Never log request bodies, connection strings, or provider secrets.
        error_log('API exception: ' . get_class($exception));
    }
    public function render(Request $request, Throwable $e): Response
    {
        $id = bin2hex(random_bytes(16));
        return Reply::json(500, ['code' => 'INTERNAL_ERROR', 'message' => 'Request could not be completed.', 'correlation_id' => $id, 'retryable' => false], $id);
    }
}
