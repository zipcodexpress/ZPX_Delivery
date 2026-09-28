<?php
declare(strict_types=1);
namespace Zpx\Http;
use think\Response;

final class SessionCookies
{
    public static function value(string $token, int $age, string $path): string
    {
        $secure = getenv('APP_ENV') === 'development' ? '' : '; Secure';
        return 'zpx_delivery_session='.$token.'; Path='.$path.'; Max-Age='.$age.'; HttpOnly; SameSite=Strict'.$secure;
    }

    public static function clearLegacy(Response $response): Response
    {
        // A second Set-Cookie header is necessary: paths identify distinct browser cookies.
        $response->cookie('zpx_delivery_session', '', [
            'expire'=>-3600, 'path'=>'/api/delivery/v1', 'httponly'=>true,
            'samesite'=>'Strict', 'secure'=>getenv('APP_ENV') !== 'development',
        ]);
        return $response;
    }
}
