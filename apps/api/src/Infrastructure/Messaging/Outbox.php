<?php
declare(strict_types=1);
namespace Zpx\Infrastructure\Messaging;
use PDO;
use LogicException;
final class Outbox
{
    public function __construct(private PDO $db) {}
    public function append(string $eventId, string $aggregateType, string $aggregateId, string $eventType, array $payload): void
    {
        if (!$this->db->inTransaction()) { throw new LogicException('Outbox requires the business transaction'); }
        $statement = $this->db->prepare('INSERT INTO outbox_events(event_uuid, aggregate_type, aggregate_id, event_type, payload) VALUES (?, ?, ?, ?, CAST(? AS jsonb))');
        $statement->execute([$eventId, $aggregateType, $aggregateId, $eventType, json_encode($payload, JSON_THROW_ON_ERROR)]);
    }
}
