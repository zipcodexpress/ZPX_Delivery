<?php
declare(strict_types=1);
namespace Zpx\Infrastructure\Database;
use PDO;
use RuntimeException;
use Throwable;
final class Migrator
{
    public function __construct(private PDO $db, private string $directory) {}
    public function files(): array
    {
        $files = glob($this->directory . '/[0-9][0-9][0-9]_*.sql');
        sort($files, SORT_STRING);
        if (!$files) { throw new RuntimeException('No migrations found'); }
        return $files;
    }
    public function assertCurrent(): void
    {
        $applied = $this->db->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
        $files = $this->files();
        if (count($applied) !== count($files)) { throw new RuntimeException('Migrations pending or application behind database'); }
        foreach ($files as $file) {
            if (($applied[basename($file)] ?? '') !== hash_file('sha256', $file)) { throw new RuntimeException('Migration checksum mismatch'); }
        }
    }
    public function up(): int
    {
        $this->db->query('SELECT pg_advisory_lock(762940117)');
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            $applied = $this->db->query('SELECT version, checksum FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_KEY_PAIR);
            $files = $this->files();
            $known = array_map('basename', $files);
            foreach ($applied as $version => $checksum) {
                if (!in_array($version, $known, true)) { throw new RuntimeException('Database has unknown migration'); }
            }
            foreach ($files as $file) {
                if (isset($applied[basename($file)]) && $applied[basename($file)] !== hash_file('sha256', $file)) { throw new RuntimeException('Migration checksum mismatch'); }
            }
            $count = 0;
            foreach ($files as $file) {
                $version = basename($file);
                if (isset($applied[$version])) { continue; }
                $this->db->beginTransaction();
                try {
                    $this->db->exec(file_get_contents($file));
                    $statement = $this->db->prepare('INSERT INTO schema_migrations(version, checksum) VALUES (?, ?)');
                    $statement->execute([$version, hash_file('sha256', $file)]);
                    $this->db->commit();
                    $count++;
                } catch (Throwable $error) {
                    if ($this->db->inTransaction()) { $this->db->rollBack(); }
                    throw $error;
                }
            }
            return $count;
        } finally { $this->db->query('SELECT pg_advisory_unlock(762940117)'); }
    }
}
