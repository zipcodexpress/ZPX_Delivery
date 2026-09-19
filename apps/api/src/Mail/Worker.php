<?php
declare(strict_types=1);
namespace Zpx\Mail;
use PDO;
use Zpx\Identity\Secrets;
use Zpx\Infrastructure\Database\Transaction;
final class Worker
{
    public function __construct(private PDO $db,private Secrets $crypto,private ?\Closure $sender=null) {}
    public function once(): bool {
        $row=(new Transaction($this->db))->run(function () {
            $r=$this->db->query("SELECT o.* FROM outbox_events o WHERE o.event_type='identity.contact_verification' AND o.payload->>'delivery'='SMTP' AND o.published_at IS NULL AND o.delivery_status IN ('PENDING','RETRY') AND o.next_attempt_at<=now() AND (o.locked_until IS NULL OR o.locked_until<now()) ORDER BY o.id FOR UPDATE SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$r) { return null; }
            $q=$this->db->prepare("UPDATE outbox_events SET locked_until=now()+interval '2 minutes',attempts=attempts+1 WHERE id=?");$q->execute([$r['id']]);$lease=$this->db->prepare('SELECT locked_until FROM outbox_events WHERE id=?');$lease->execute([$r['id']]);$r['locked_until']=$lease->fetchColumn();return $r;
        });
        if (!$row) { return false; }
        $status='SENT';$error=null;
        try {
            if ((int)$row['attempts']>=5) { throw new \RuntimeException('Delivery attempts exhausted'); }
            $q=$this->db->prepare('SELECT id FROM verification_challenges WHERE id=? AND consumed_at IS NULL AND expires_at>now() AND attempts<5');$q->execute([$row['aggregate_id']]);
            if (!$q->fetchColumn()) { $status='EXPIRED'; }
            else {
                $payload=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);
                $message=json_decode($this->crypto->decrypt($payload['encrypted_message']),true,512,JSON_THROW_ON_ERROR);
                if ($this->sender) { ($this->sender)($message,$row['event_uuid']); }
                else { (new Smtp())->send($message,$row['event_uuid']); }
            }
        } catch (\Throwable $e) { $status=((int)$row['attempts']+1)>=5?'FAILED':'RETRY';$error='SMTP_DELIVERY_FAILED'; }
        $q=$this->db->prepare("UPDATE outbox_events SET delivery_status=?,last_error_code=?,locked_until=NULL,next_attempt_at=now()+interval '1 minute',published_at=CASE WHEN ? IN ('SENT','EXPIRED','FAILED') THEN now() ELSE NULL END WHERE id=? AND locked_until=?");
        $q->execute([$status,$error,$status,$row['id'],$row['locked_until']]);return true;
    }
}
