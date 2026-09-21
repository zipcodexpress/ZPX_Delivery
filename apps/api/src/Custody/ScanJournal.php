<?php
declare(strict_types=1);
namespace Zpx\Custody;

use PDO;
use Throwable;
use Zpx\Identity\Secrets;
use Zpx\Infrastructure\Database\Transaction;

/**
 * Journals refused scans. A refusal row has to outlive the transaction that refused it, so
 * callers stage inside the transaction and the row is written after the rollback.
 */
final class ScanJournal
{
    private ?array $pending = null;

    public function __construct(private PDO $db) {}

    public function stage(string $user, ?string $runId, ?string $packageId, string $action, string $resultCode): void
    {
        $this->pending = [
            'user' => $user,
            'run_id' => $runId,
            'package_id' => $packageId,
            'action' => $action,
            'result_code' => $resultCode,
        ];
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = null;
        if ($pending === null) { return; }

        $q = $this->db->prepare(
            'INSERT INTO scan_events(operation_uuid,package_id,actor_user_id,run_id,action,result_code,received_at) VALUES (?,?,?,?,?,?,now())'
        );
        $q->execute([
            Secrets::uuid(),
            $pending['package_id'],
            $pending['user'],
            $pending['run_id'],
            $pending['action'],
            $pending['result_code'],
        ]);
    }

    /** Runs a scan transaction, then journals any refusal it staged once the rollback is done. */
    public function transact(callable $operation): mixed
    {
        try {
            return (new Transaction($this->db))->run($operation);
        } catch (Throwable $e) {
            $this->flush();
            throw $e;
        }
    }
}
