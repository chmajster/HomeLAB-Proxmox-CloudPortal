<?php

declare(strict_types=1);

namespace CloudPortal\Database;

use PDO;
use PDOException;

final class MigrationService
{
    public const CURRENT_VERSION = '1.5.0';

    public function __construct(private readonly PDO $pdo, private readonly string $directory)
    {
    }

    /** @return list<string> */
    public function apply(): array
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(32) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_NATURAL);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if ($this->isApplied($version)) continue;
            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                throw new \RuntimeException('Migration is empty or unreadable: ' . $version);
            }
            $lockName = 'cloud_portal_migrate_' . substr(hash('sha256', $version), 0, 32);
            $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, 10)');
            $lock->execute(['name' => $lockName]);
            if ((int) $lock->fetchColumn() !== 1) {
                throw new \RuntimeException('Could not acquire migration lock for ' . $version . '.');
            }
            try {
                if (!$this->isApplied($version)) {
                    $this->executeStatements($sql);
                    if (!$this->isApplied($version)) {
                        $statement = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
                        $statement->execute(['version' => $version]);
                    }
                    $applied[] = $version;
                }
            } finally {
                $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
                $release->execute(['name' => $lockName]);
            }
        }
        return $applied;
    }

    public function currentVersion(): ?string
    {
        $value = $this->pdo->query('SELECT version FROM schema_migrations ORDER BY applied_at DESC, version DESC LIMIT 1')->fetchColumn();
        return is_string($value) ? $value : null;
    }

    public function isCurrent(): bool
    {
        return $this->isApplied(self::CURRENT_VERSION);
    }

    private function executeStatements(string $sql): void
    {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', trim($sql)) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '' || str_starts_with($statement, '--')) continue;
            try {
                $this->pdo->exec($statement);
            } catch (PDOException $exception) {
                $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
                if ($driverCode === 1060) {
                    continue;
                }
                throw $exception;
            }
        }
    }

    private function isApplied(string $version): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
        $statement->execute(['version' => $version]);
        return (bool) $statement->fetchColumn();
    }
}
