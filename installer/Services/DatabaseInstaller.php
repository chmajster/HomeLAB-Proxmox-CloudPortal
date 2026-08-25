<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

use CloudPortal\Database\MigrationService;
use PDO;

final class DatabaseInstaller
{
    public const SCHEMA_VERSION = '1.5.0';
    public const REQUIRED_TABLES = [
        'schema_migrations', 'roles', 'permissions', 'role_permissions', 'users', 'projects', 'project_users',
        'proxmox_connections', 'proxmox_nodes', 'resource_plans', 'vm_templates', 'networks', 'project_networks',
        'storages', 'project_storages', 'virtual_machines', 'quotas', 'quota_reservations', 'quota_template_limits',
        'ip_addresses', 'jobs', 'snapshots', 'vm_disks', 'vm_nics', 'backups', 'worker_heartbeats', 'webhooks',
        'webhook_deliveries', 'audit_logs', 'settings', 'password_reset_tokens', 'login_attempts',
        'hostname_sequences', 'vm_provisioning', 'vm_provisioning_events', 'user_ssh_keys', 'cloud_init_profiles',
        'cloud_init_profile_ssh_keys',
    ];

    public function __construct(private readonly string $schemaPath)
    {
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function test(array $config): array
    {
        $connectionOnly = (bool) ($config['connection_test_only'] ?? false);
        $createIfMissing = !array_key_exists('create_if_missing', $config) || (bool) $config['create_if_missing'];

        if ($connectionOnly && $createIfMissing) {
            $pdo = $this->connectServer($config);
            return [
                'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                'connection_scope' => 'server',
                'database_check_skipped' => true,
                'database_created' => false,
                'database_name' => (string) ($config['name'] ?? ''),
                'charset' => null,
                'collation' => null,
                'table_count' => null,
                'portal_table_count' => 0,
                'existing_tables' => false,
                'compatible_portal_schema' => false,
                'warning' => null,
                'message' => 'Połączenie z serwerem MariaDB/MySQL działa. Dane logowania zostały zaakceptowane. Istnienie wskazanej bazy nie było sprawdzane.',
            ];
        }

        $databaseCreated = $this->ensureDatabaseExists($config);
        $result = $this->inspectDatabase($config, $databaseCreated);

        // The normal installer submit performs safety checks based on this result.
        // When the user explicitly requested a full reset, existing objects are
        // intentionally not preserved and are removed only later by initialize().
        if (!$connectionOnly && (bool) ($config['reset_database'] ?? false)) {
            $result['reset_requested'] = true;
            $result['existing_tables'] = false;
            $result['portal_table_count'] = 0;
            $result['compatible_portal_schema'] = true;
            $result['warning'] = 'Wybrano wyczyszczenie bazy. Po kliknięciu „Kontynuuj” wszystkie istniejące tabele i widoki zostaną usunięte, a schemat zostanie utworzony od nowa.';
        }

        return $result;
    }

    /** @param array<string,mixed> $config @return array{version:string,tables:int} */
    public function initialize(array $config): array
    {
        $this->ensureDatabaseExists($config);
        $pdo = $this->connectDatabase($config);
        $database = (string) $config['name'];
        $lockName = 'cloud_portal_install_' . substr(hash('sha256', $database), 0, 32);
        $lock = $pdo->prepare('SELECT GET_LOCK(:name, 10)');
        $lock->execute(['name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) throw new \RuntimeException('Inna sesja instalatora inicjalizuje obecnie tę bazę danych.');
        try {
            if ((bool) ($config['reset_database'] ?? false)) {
                $this->resetDatabase($pdo);
            }

            $schema = file_get_contents($this->schemaPath);
            if (!is_string($schema) || trim($schema) === '') throw new \RuntimeException('Nie można odczytać pliku schematu bazy danych.');
            $pdo->exec($schema);
            (new MigrationService($pdo, dirname($this->schemaPath) . '/migrations'))->apply();
            $this->verify($pdo);
            return ['version' => self::SCHEMA_VERSION, 'tables' => count($this->tables($pdo))];
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Inicjalizacja schematu bazy danych nie powiodła się: ' . $this->safeDatabaseMessage($exception), 0, $exception);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
        }
    }

    public function verify(PDO $pdo): void
    {
        $missing = array_diff(self::REQUIRED_TABLES, $this->tables($pdo));
        if ($missing !== []) throw new \RuntimeException('Schemat bazy danych jest niekompletny. Brakujące tabele: ' . implode(', ', $missing));
        $version = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
        $version->execute(['version' => self::SCHEMA_VERSION]);
        if (!$version->fetchColumn()) throw new \RuntimeException('Wersja schematu bazy danych nie została zarejestrowana.');
        if ((int) $pdo->query("SELECT COUNT(*) FROM roles WHERE slug IN ('admin','user')")->fetchColumn() !== 2) throw new \RuntimeException('Początkowe role są niekompletne.');
        if ((int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() < 12) throw new \RuntimeException('Początkowe uprawnienia są niekompletne.');
    }

    /** @param array<string,mixed> $config */
    public function connect(array $config): PDO
    {
        return $this->connectDatabase($config);
    }

    /**
     * Creates the configured database only when it is missing and the installer option allows it.
     *
     * @param array<string,mixed> $config
     * @return bool true when the database was created by this call
     */
    public function ensureDatabaseExists(array $config): bool
    {
        $database = (string) ($config['name'] ?? '');
        $identifier = $this->databaseIdentifier($database);
        $pdo = $this->connectServer($config);

        $exists = $pdo->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :database LIMIT 1');
        $exists->execute(['database' => $database]);
        if ($exists->fetchColumn()) return false;

        $createIfMissing = !array_key_exists('create_if_missing', $config) || (bool) $config['create_if_missing'];
        if (!$createIfMissing) {
            throw new \RuntimeException('Połączenie z serwerem działa, ale wskazana baza danych nie istnieje. Zaznacz „Utwórz bazę danych, jeśli nie istnieje” albo utwórz bazę ręcznie.');
        }

        try {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS {$identifier} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Połączenie z serwerem i dane logowania są prawidłowe, ale użytkownik nie ma uprawnienia do utworzenia bazy danych. Nadaj uprawnienie CREATE DATABASE albo utwórz bazę ręcznie.',
                0,
                $exception,
            );
        }

        $exists->execute(['database' => $database]);
        if (!$exists->fetchColumn()) {
            throw new \RuntimeException('Automatyczne utworzenie bazy danych nie zostało zakończone poprawnie.');
        }

        return true;
    }

    /** @return list<string> */
    public function tables(PDO $pdo): array
    {
        return array_map('strtolower', $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN));
    }

    private function resetDatabase(PDO $pdo): void
    {
        try {
            $objects = $pdo->query(
                'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_TYPE DESC, TABLE_NAME'
            )->fetchAll(PDO::FETCH_ASSOC);

            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach ($objects as $object) {
                $name = $this->quoteIdentifier((string) ($object['TABLE_NAME'] ?? ''));
                $type = strtoupper((string) ($object['TABLE_TYPE'] ?? ''));
                if ($type === 'VIEW') {
                    $pdo->exec("DROP VIEW IF EXISTS {$name}");
                    continue;
                }
                $pdo->exec("DROP TABLE IF EXISTS {$name}");
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'Nie udało się wyczyścić bazy danych. Użytkownik musi mieć uprawnienia DROP do wszystkich istniejących tabel i widoków.',
                0,
                $exception,
            );
        } finally {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (\Throwable) {
                // Preserve the original reset error if restoring the session option also fails.
            }
        }
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function inspectDatabase(array $config, bool $databaseCreated): array
    {
        $pdo = $this->connectDatabase($config);
        $probe = 'cloud_portal_probe_' . bin2hex(random_bytes(5));
        try {
            $pdo->exec("CREATE TEMPORARY TABLE {$probe} (id INT PRIMARY KEY) ENGINE=InnoDB");
            $pdo->exec("DROP TEMPORARY TABLE {$probe}");
        } catch (\Throwable) {
            throw new \RuntimeException('Połączenie z bazą działa, ale użytkownik nie ma uprawnienia do tworzenia tabel. Nadaj uprawnienie CREATE dla tej bazy.');
        }

        $tables = $this->tables($pdo);
        $portalTables = array_values(array_intersect(self::REQUIRED_TABLES, $tables));
        $charset = $pdo->query('SELECT @@character_set_database')->fetchColumn();
        $collation = $pdo->query('SELECT @@collation_database')->fetchColumn();

        return [
            'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'connection_scope' => 'database',
            'database_check_skipped' => false,
            'charset' => (string) $charset,
            'collation' => (string) $collation,
            'table_count' => count($tables),
            'portal_table_count' => count($portalTables),
            'existing_tables' => $tables !== [],
            'compatible_portal_schema' => $this->hasSchemaMarker($pdo),
            'database_created' => $databaseCreated,
            'warning' => $tables === [] ? null : 'Baza nie jest pusta. Instalator nie usunie ani nie nadpisze istniejących tabel.',
        ];
    }

    /** @param array<string,mixed> $config */
    private function connectServer(array $config): PDO
    {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['port']),
                (string) $config['user'],
                (string) $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 8],
            );
            $pdo->exec("SET SESSION time_zone = '+00:00'");
            return $pdo;
        } catch (\Throwable $exception) {
            throw new \RuntimeException($this->connectionFailureMessage($exception, false), 0, $exception);
        }
    }

    /** @param array<string,mixed> $config */
    private function connectDatabase(array $config): PDO
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
            throw new \RuntimeException($this->connectionFailureMessage($exception, true), 0, $exception);
        }
    }

    private function connectionFailureMessage(\Throwable $exception, bool $databaseSelected): string
    {
        $nativeCode = 0;
        if ($exception instanceof \PDOException && is_array($exception->errorInfo) && isset($exception->errorInfo[1])) {
            $nativeCode = (int) $exception->errorInfo[1];
        }
        $raw = strtolower($exception->getMessage());
        if ($nativeCode === 0 && preg_match('/\[(\d{4})\]/', $exception->getMessage(), $matches) === 1) {
            $nativeCode = (int) $matches[1];
        }

        if ($nativeCode === 1044) {
            return 'Login i hasło zostały zaakceptowane, ale użytkownik nie ma dostępu do wskazanej bazy danych. Sprawdź uprawnienia GRANT dla tego użytkownika.';
        }
        if ($nativeCode === 1045 || str_contains($raw, 'access denied for user')) {
            return 'Serwer MariaDB/MySQL odrzucił logowanie. Sprawdź login i hasło. Jeśli są poprawne, sprawdź również, czy to konto może łączyć się z adresu tego serwera WWW.';
        }
        if ($nativeCode === 1049 || str_contains($raw, 'unknown database')) {
            return 'Połączenie z serwerem i dane logowania są prawidłowe, ale wskazana baza danych nie istnieje.';
        }
        if (in_array($nativeCode, [2002, 2003, 2005], true)
            || str_contains($raw, 'connection refused')
            || str_contains($raw, 'getaddrinfo')
            || str_contains($raw, 'name or service not known')
            || str_contains($raw, 'no route to host')) {
            return 'Nie można połączyć się z serwerem MariaDB/MySQL. Sprawdź host, port, czy usługa bazy działa, nasłuchuje na tym adresie oraz czy firewall zezwala na połączenie.';
        }
        if (str_contains($raw, 'timed out') || str_contains($raw, 'timeout')) {
            return 'Przekroczono czas oczekiwania na połączenie z MariaDB/MySQL. Sprawdź host, port, trasę sieciową i reguły firewalla.';
        }

        return $databaseSelected
            ? 'Nie udało się połączyć z wybraną bazą danych. Host i port mogą działać, ale należy sprawdzić login, hasło, nazwę bazy oraz uprawnienia użytkownika.'
            : 'Nie udało się połączyć z serwerem MariaDB/MySQL. Sprawdź host, port, login i hasło.';
    }

    private function databaseIdentifier(string $database): string
    {
        if (preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $database) !== 1) {
            throw new \RuntimeException('Nazwa bazy danych jest nieprawidłowa.');
        }
        return $this->quoteIdentifier($database);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if ($identifier === '') throw new \RuntimeException('Nazwa obiektu bazy danych jest pusta.');
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function hasSchemaMarker(PDO $pdo): bool
    {
        if (!in_array('schema_migrations', $this->tables($pdo), true)) return false;
        try {
            $statement = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version LIMIT 1');
            $statement->execute(['version' => self::SCHEMA_VERSION]);
            return (bool) $statement->fetchColumn();
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
