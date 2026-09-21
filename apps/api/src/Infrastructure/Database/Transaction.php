<?php
declare(strict_types=1);
namespace Zpx\Infrastructure\Database;
use PDO;
use LogicException;
use Throwable;
final class Transaction
{
    public function __construct(private PDO $db) {}
    public function run(callable $operation): mixed
    {
        if ($this->db->inTransaction()) { throw new LogicException('Nested transaction not supported'); }
        $this->db->beginTransaction();
        try {
            $result = $operation($this->db);
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
    }
}
