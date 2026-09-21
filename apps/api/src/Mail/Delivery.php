<?php
declare(strict_types=1);
namespace Zpx\Mail;
final class Delivery
{
    public static function channel(string $kind,string $address): string {
        if ($kind!=='EMAIL' || getenv('MAIL_DELIVERY_MODE')!=='smtp') { return 'LOCAL_ONLY'; }
        $domain=strtolower(substr(strrchr($address,'@') ?: '',1));
        if ($domain==='' || str_ends_with($domain,'.invalid') || in_array($domain,['example.com','example.org','example.net'],true)) { return 'LOCAL_ONLY'; }
        // Development recipients are explicitly opted in; production is not enabled here.
        $allowed=array_map('strtolower',array_map('trim',explode(',',getenv('MAIL_ALLOWED_RECIPIENTS') ?: '')));
        return getenv('APP_ENV')==='development' && in_array(strtolower($address),$allowed,true)?'SMTP':'LOCAL_ONLY';
    }
}
