<?php
declare(strict_types=1);
namespace Zpx\Mail;
use PHPMailer\PHPMailer\PHPMailer;
use Zpx\Identity\Failure;

final class Smtp
{
    private function value(string $canonical,string $legacy,string $default=''): string { return getenv($canonical) ?: (getenv($legacy) ?: $default); }
    public function connection(): PHPMailer {
        $legacy=!(bool)getenv('MAIL_HOST');
        $host=$this->value('MAIL_HOST','SMTP_SERVER');
        $user=$this->value('MAIL_USERNAME','SMTP_USER');
        $password=$this->value('MAIL_PASSWORD','SMTP_PWD');
        $from=$this->value('MAIL_FROM_ADDRESS','SMTP_USER_EMAIL');
        $port=(int)($legacy?(getenv('SMTP_PORT') ?: 587):(getenv('MAIL_PORT') ?: 587));
        if (!$host || !$user || !$password || !filter_var($from,FILTER_VALIDATE_EMAIL) || !in_array($port,[465,587,25],true)) { throw new Failure(503,'MAIL_NOT_CONFIGURED','SMTP configuration is incomplete.'); }
        $mail=new PHPMailer(true);$mail->isSMTP();$mail->Host=$host;$mail->Port=$port;
        $mail->SMTPAuth=true;$mail->Username=$user;$mail->Password=$password;$mail->Timeout=20;$mail->getSMTPInstance()->Timelimit=25;
        $mail->SMTPSecure=$port===465?PHPMailer::ENCRYPTION_SMTPS:PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPDebug=0;$mail->CharSet='UTF-8';$mail->setFrom($from,getenv('MAIL_FROM_NAME') ?: 'ZPX Delivery');
        return $mail;
    }
    public function check(): void {
        $mail=$this->connection();
        try { if (!$mail->smtpConnect()) { throw new \RuntimeException('SMTP rejected'); } }
        catch (\Throwable $e) { throw new Failure(503,'SMTP_CONNECTION_FAILED','SMTP connection or authentication failed.'); }
        finally { $mail->smtpClose(); }
    }
    public function send(array $message,string $event,bool $diagnostic=false): void {
        if (Delivery::channel('EMAIL',$message['to'])!=='SMTP') { throw new Failure(403,'RECIPIENT_NOT_ALLOWED','This development recipient is not enabled.'); }
        $mail=$this->connection();
        $mail->addAddress($message['to']);$mail->Subject=$diagnostic?'ZPX development email delivery test':'Your ZPX verification code';
        $mail->MessageID='<zpx-'.$event.'@'.substr(strrchr($mail->From,'@'),1).'>';
        $mail->Body="Your ZPX verification code is: ".$message['code']."\n\nThis code expires in 10 minutes and can only be used once.\nIf you did not request it, ignore this email.\n\nZPX Delivery development";
        if ($diagnostic) { $mail->Body="This is the single ZPX development email test you authorized.\n\nTest verification code: ".$message['code']."\n\nThis diagnostic code does not verify or change an account. No payment was made.\n\nZPX Delivery development"; }
        try { $mail->send(); }
        catch (\Throwable $e) { throw new Failure(503,'SMTP_DELIVERY_FAILED','SMTP did not confirm message delivery.'); }
        finally { $mail->smtpClose(); }
    }
}
