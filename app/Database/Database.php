<?php

declare(strict_types=1);

namespace CloudPortal\Database;

use CloudPortal\Support\Config;
use PDO;
use Throwable;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config, ?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = (string) $this->config->get('database.host');
        $port = (int) $this->config->get('database.port');
        $name = (string) $this->config->get('database.name');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        $this->pdo = new PDO($dsn, (string) $this->config->get('database.user'), (string) $this->config->get('database.password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        $this->pdo->exec("SET SESSION time_zone = '+00:00'");
        return $this->pdo;
    }

    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            $savepoint = 'portal_' . bin2hex(random_bytes(6));
            $pdo->exec('SAVEPOINT ' . $savepoint);
            try {
                $result = $callback($pdo);
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                return $result;
            } catch (Throwable $exception) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                throw $exception;
            }
        }
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
