<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

use PDO;

final class DatabaseInstaller
{
    public const SCHEMA_VERSION = '1.0.0';
    public const REQUIRED_TABLES = [
        'schema_migrations', 'roles', 'permissions', 'role_permissions', 'users', 'projects', 'project_users',
        'proxmox_connections', 'proxmox_nodes', 'resource_plans', 'vm_templates', 'networks', 'project_networks',
        'storages', 'project_storages', 'virtual_machines', 'quotas', 'quota_reservations', 'ip_addresses',
        'jobs', 'snapshots', 'audit_logs', 'settings', 'password_reset_tokens', 'login_attempts',
    ];

    public function __construct(private readonly string $schemaPath)
    {
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function test(array $config): array
    {
        $pdo = $this->connect($config);
        $probe = 'cloud_portal_probe_' . bin2hex(random_bytes(5));
        try {
            $pdo->exec("CREATE TEMPORARY TABLE {$probe} (id INT PRIMARY KEY) ENGINE=InnoDB");
            $pdo->exec("DROP TEMPORARY TABLE {$probe}");
        } catch (\Throwable) {
            throw new \RuntimeException('Database user cannot create tables in the selected database.');
        }
        $tables = $this->tables($pdo);
        $portalTables = array_values(array_intersect(self::REQUIRED_TABLES, $tables));
        $charset = $pdo->query('SELECT @@character_set_database')->fetchColumn();
        $collation = $pdo->query('SELECT @@collation_database')->fetchColumn();
        return [
            'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'charset' => (string) $charset,
            'collation' => (string) $collation,
            'table_count' => count($tables),
            'portal_table_count' => count($portalTables),
            'existing_tables' => $tables !== [],
            'compatible_portal_schema' => $this->hasSchemaMarker($pdo),
            'warning' => $tables === [] ? null : 'The database is not empty. Existing tables will never be deleted or overwritten.',
        ];
    }

    /** @param array<string,mixed> $config @return array{version:string,tables:int} */
    public function initialize(array $config): array
    {
        $pdo = $this->connect($config);
        $database = (string) $config['name'];
        $lockName = 'cloud_portal_install_' . substr(hash('sha256', $database), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute(['name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) throw new \RuntimeException('Another installer session is initializing this database.');
        try {
            $schema = file_get_contents($this->schemaPath);
            if (!is_string($schema) || trim($schema) === '') throw new \RuntimeException('The database schema file cannot be read.');
            $pdo->exec($schema);
            $this->verify($pdo);
            return ['version' => self::SCHEMA_VERSION, 'tables' => count($this->tables($pdo))];
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Database schema initialization failed: ' . $this->safeDatabaseMessage($exception), 0, $exception);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        }
    }

    public function verify(PDO $pdo): void
    {
        $missing = array_diff(self::REQUIRED_TABLES, $this->tables($pdo));
        if ($missing !== []) throw new \RuntimeException('Database schema is incomplete. Missing tables: ' . implode(', ', $missing));
        $version = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $version->execute(['version' => self::SCHEMA_VERSION]);
        if (!$version->fetchColumn()) throw new \RuntimeException('Database schema version is not registered.');
        if ((int) $pdo->query("SELECT COUNT(*) FROM roles WHERE slug IN ('admin','user')")->fetchColumn() !== 2) throw new \RuntimeException('Initial roles are incomplete.');
        if ((int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() < 12) throw new \RuntimeException('Initial permissions are incomplete.');
    }

    /** @param array<string,mixed> $config */
    public function connect(array $config): PDO
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['name']),
                (string) $config['user'],
                (string) $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 8],
            );
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            return $pdo;
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Database connection failed. Verify the host, database name, user and password.', 0, $exception);
        }
    }

    /** @return list<string> */
    public function tables(PDO $pdo): array
    {
        return array_map('strtolower', $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function hasSchemaMarker(PDO $pdo): bool
    {
        if (!in_array('schema_migrations', $this->tables($pdo), true)) return false;
        try {
            return (bool) $pdo->query("SELECT 1 FROM schema_migrations WHERE version='" . self::SCHEMA_VERSION . "' LIMIT 1")->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    private function safeDatabaseMessage(\Throwable $exception): string
    {
        $message = preg_replace('/SQLSTATE\[[^]]+\](?:\[[^]]+\])?:?\s*/', '', $exception->getMessage()) ?? '';
        return mb_substr(str_replace(["\r", "\n"], ' ', $message), 0, 500);
    }
}
