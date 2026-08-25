<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Installer\Services\DatabaseInstaller;
use CloudPortal\Installer\Validators\InstallerInput;

final class InstallerDatabaseConnectionModeTest extends MariaDbTestCase
{
    public function testCreateIfMissingConnectionTestDoesNotInspectOrCreateTargetDatabase(): void
    {
        $database = 'cloud_portal_missing_' . bin2hex(random_bytes(6));
        $config = InstallerInput::database([
            'db_host' => $this->host(),
            'db_port' => $this->port(),
            'db_name' => $database,
            'db_user' => (string) (getenv('TEST_DB_USER') ?: ''),
            'db_password' => (string) (getenv('TEST_DB_PASSWORD') ?: ''),
            'create_database_if_missing' => '1',
            'connection_test_only' => '1',
        ]);

        self::assertSame(0, $this->databaseExists($database));

        $result = (new DatabaseInstaller(dirname(__DIR__, 2) . '/database/schema.sql'))->test($config);

        self::assertSame('server', $result['connection_scope']);
        self::assertTrue($result['database_check_skipped']);
        self::assertFalse($result['database_created']);
        self::assertSame($database, $result['database_name']);
        self::assertNull($result['table_count']);
        self::assertSame(0, $this->databaseExists($database));
    }

    public function testEmptyDatabaseNameConnectionTestChecksOnlyServer(): void
    {
        $config = InstallerInput::database([
            'db_host' => $this->host(),
            'db_port' => $this->port(),
            'db_name' => '',
            'db_user' => (string) (getenv('TEST_DB_USER') ?: ''),
            'db_password' => (string) (getenv('TEST_DB_PASSWORD') ?: ''),
            'create_database_if_missing' => '0',
            'connection_test_only' => '1',
        ]);

        self::assertSame('', $config['name']);
        self::assertTrue($config['create_if_missing']);

        $result = (new DatabaseInstaller(dirname(__DIR__, 2) . '/database/schema.sql'))->test($config);

        self::assertSame('server', $result['connection_scope']);
        self::assertTrue($result['database_check_skipped']);
        self::assertSame('', $result['database_name']);
    }

    public function testEmptyDatabaseNameDefaultsToCloudportalOnContinue(): void
    {
        $config = InstallerInput::database([
            'db_host' => $this->host(),
            'db_port' => $this->port(),
            'db_name' => '',
            'db_user' => (string) (getenv('TEST_DB_USER') ?: ''),
            'db_password' => (string) (getenv('TEST_DB_PASSWORD') ?: ''),
            'create_database_if_missing' => '0',
        ]);

        self::assertSame('cloudportal', $config['name']);
        self::assertTrue($config['create_if_missing']);
        self::assertFalse($config['connection_test_only']);
    }

    public function testRejectedLoginReturnsActionableCredentialMessage(): void
    {
        $config = InstallerInput::database([
            'db_host' => $this->host(),
            'db_port' => $this->port(),
            'db_name' => 'cloud_portal_any_name',
            'db_user' => (string) (getenv('TEST_DB_USER') ?: ''),
            'db_password' => (string) (getenv('TEST_DB_PASSWORD') ?: '') . '-invalid',
            'create_database_if_missing' => '1',
            'connection_test_only' => '1',
        ]);

        try {
            (new DatabaseInstaller(dirname(__DIR__, 2) . '/database/schema.sql'))->test($config);
            self::fail('Invalid database credentials were accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('login i hasło', mb_strtolower($exception->getMessage()));
        }
    }

    private function databaseExists(string $database): int
    {
        $statement = self::$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=:database');
        $statement->execute(['database' => $database]);
        return (int) $statement->fetchColumn();
    }

    private function host(): string
    {
        preg_match('/host=([^;]+)/i', (string) getenv('TEST_DB_DSN'), $match);
        return $match[1] ?? '127.0.0.1';
    }

    private function port(): int
    {
        preg_match('/port=([^;]+)/i', (string) getenv('TEST_DB_DSN'), $match);
        return isset($match[1]) ? (int) $match[1] : 3306;
    }
}
