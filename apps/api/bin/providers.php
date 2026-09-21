<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Zpx\Identity\Failure;
if (getenv('APP_ENV')!=='development') { exit(1); }
try {
    switch ($argv[1] ?? '') {
        case 'reporting':
            (new Zpx\Payments\AuthorizeNet())->find('ZP000000000000000000');
            echo "Sandbox transaction reporting accepted; transaction details not displayed.\n"; break;
        case 'hosted':
            (new Zpx\Payments\AuthorizeNet())->hosted(600,'ZP'.strtoupper(bin2hex(random_bytes(9))));
            echo "Sandbox hosted form token issued; token not displayed and no charge submitted.\n"; break;
        case 'payment': (new Zpx\Payments\AuthorizeNet())->authenticate(); echo "Authorize.net sandbox authentication accepted.\n"; break;
        case 'mail-test':
            $to=$argv[2] ?? '';
            if (!filter_var($to,FILTER_VALIDATE_EMAIL)) { throw new Failure(422,'INVALID_EMAIL','Valid test recipient required.'); }
            putenv('MAIL_DELIVERY_MODE=smtp');
            (new Zpx\Mail\Smtp())->send(['to'=>$to,'code'=>(string)random_int(100000,999999)],Zpx\Identity\Secrets::uuid(),true);
            echo "One diagnostic email accepted by SMTP; inbox delivery is not independently confirmed.\n"; break;
        case 'mail': (new Zpx\Mail\Smtp())->check(); echo "SMTP connection and authentication accepted.\n"; break;
        default: echo "Use payment or mail for read-only provider checks.\n"; exit(2);
    }
} catch (Failure $e) { fwrite(STDERR,$e->errorCode."\n");exit(1); }
catch (Throwable $e) { fwrite(STDERR,"Provider check failed; sensitive details suppressed.\n");exit(1); }
