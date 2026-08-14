<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Application;
use CloudPortal\Installer\Services\AdministratorInstaller;
use CloudPortal\Installer\Services\DatabaseInstaller;
use CloudPortal\Installer\Services\InstallationFailed;
use CloudPortal\Installer\Services\InstallationFinalizer;
use CloudPortal\Installer\Services\InstallationLock;
use CloudPortal\Installer\Services\ProxmoxTester;
use CloudPortal\Installer\Services\RuntimeConfigWriter;
use CloudPortal\Installer\Validators\InstallerInput;

final class InstallerDatabaseTest extends MariaDbTestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        if (!self::$pdo) self::markTestSkipped('Set TEST_DB_DSN to an isolated MariaDB test database.');
        $this->cleanInstallerFixtures();
    }

    protected function tearDown(): void
    {
        if (self::$pdo) $this->cleanInstallerFixtures();
        foreach ($this->temporaryDirectories as $directory) $this->removeDirectory($directory);
    }

    public function testRealPdoConnectionExistingSchemaAndRepeatableImport(): void
    {
        $installer = $this->databaseInstaller();
        $config = $this->databaseConfig();
        $before = (int) self::$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
        $test = $installer->test($config);
        self::assertTrue($test['existing_tables']);
        self::assertTrue($test['compatible_portal_schema']);
        self::assertSame('utf8mb4', strtolower($test['charset']));
        self::assertGreaterThanOrEqual(count(DatabaseInstaller::REQUIRED_TABLES), $test['portal_table_count']);
        self::assertSame(DatabaseInstaller::SCHEMA_VERSION, $installer->initialize($config)['version']);
        self::assertSame(DatabaseInstaller::SCHEMA_VERSION, $installer->initialize($config)['version']);
        $after = (int) self::$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn();
        self::assertSame($before, $after, 'Repeated schema initialization must not delete or duplicate tables.');
    }

    public function testInvalidDatabaseCredentialsReturnSafeMessage(): void
    {
        $config = $this->databaseConfig();
        $config['password'] = 'a-password-that-must-not-leak';
        $config['port'] = 1;
        try {
            $this->databaseInstaller()->connect($config);
            self::fail('Invalid database endpoint was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Database connection failed. Verify the host, database name, user and password.', $exception->getMessage());
            self::assertStringNotContainsString($config['password'], $exception->getMessage());
        }
    }

    public function testAdministratorIsUniqueAndRefreshDoesNotDuplicateIt(): void
    {
        $service = new AdministratorInstaller();
        $input = ['username' => 'installer_owner', 'email' => 'installer-owner@example.test', 'password' => 'Safe-password-12345', 'resume' => false];
        $id = $service->create(self::$pdo, $input);
        self::assertGreaterThan(0, $id);
        self::assertSame($id, $service->create(self::$pdo, $input, $id));
        self::assertSame(1, (int) self::$pdo->query("SELECT COUNT(*) FROM users WHERE username='installer_owner'")->fetchColumn());
        $this->expectException(\RuntimeException::class);
        $service->create(self::$pdo, [...$input, 'email' => 'another@example.test']);
    }

    public function testExplicitTestAdministratorIsStoredWithHashedPassword(): void
    {
        $input = InstallerInput::administrator(['use_test_administrator' => '1']);
        $id = (new AdministratorInstaller())->create(self::$pdo, $input);
        $statement = self::$pdo->prepare('SELECT username,email,password_hash FROM users WHERE id=:id');
        $statement->execute(['id' => $id]);
        $stored = $statement->fetch();
        self::assertIsArray($stored);
        self::assertSame('admin', $stored['username']);
        self::assertSame('admin@localhost.invalid', $stored['email']);
        self::assertNotSame('1', $stored['password_hash']);
        self::assertTrue(password_verify('1', $stored['password_hash']));
    }

    public function testFailedFinalizationDoesNotCreateLockAndSuccessfulRetryDoes(): void
    {
        $root = $this->temporaryRoot();
        $database = $this->databaseInstaller();
        $writer = new RuntimeConfigWriter($root);
        $lockPath = $root . '/storage/installed.lock';
        $state = $this->state(999999);
        $writer->write($state);
        $finalizer = new InstallationFinalizer($database, $writer, new InstallationLock($lockPath), new ProxmoxTester());
        try {
            $finalizer->finalize($state);
            self::fail('Finalization with a missing administrator succeeded.');
        } catch (InstallationFailed $exception) {
            self::assertNotEmpty($exception->stages);
        }
        self::assertFileDoesNotExist($lockPath);
        self::assertFalse((require $root . '/config/runtime.php')['app']['installed']);

        $administrator = new AdministratorInstaller();
        $adminId = $administrator->create(self::$pdo, ['username' => 'installer_owner', 'email' => 'installer-owner@example.test', 'password' => 'Safe-password-12345', 'resume' => false]);
        $state['administrator'] = ['id' => $adminId, 'username' => 'installer_owner', 'email' => 'installer-owner@example.test'];
        $result = $finalizer->finalize($state);
        self::assertCount(11, $result['stages']);
        self::assertFileExists($lockPath);
        self::assertTrue((new Application($root))->installed());
        self::assertSame('Test Portal', json_decode((string) self::$pdo->query("SELECT value FROM settings WHERE setting_key='portal.name'")->fetchColumn(), true));
    }

    private function databaseInstaller(): DatabaseInstaller
    {
        return new DatabaseInstaller(dirname(__DIR__, 2) . '/database/schema.sql');
    }

    /** @return array<string,mixed> */
    private function databaseConfig(): array
    {
        $dsn = (string) getenv('TEST_DB_DSN');
        preg_match('/host=([^;]+)/i', $dsn, $host);
        preg_match('/port=([^;]+)/i', $dsn, $port);
        preg_match('/dbname=([^;]+)/i', $dsn, $database);
        return [
            'host' => $host[1] ?? '127.0.0.1', 'port' => isset($port[1]) ? (int) $port[1] : 3306,
            'name' => $database[1] ?? '', 'user' => (string) (getenv('TEST_DB_USER') ?: ''),
            'password' => (string) (getenv('TEST_DB_PASSWORD') ?: ''),
        ];
    }

    /** @return array<string,mixed> */
    private function state(int $adminId): array
    {
        return [
            'install_id' => str_repeat('c', 32), 'database' => $this->databaseConfig(),
            'administrator' => ['id' => $adminId, 'username' => 'installer_owner', 'email' => 'installer-owner@example.test'],
            'portal' => ['name' => 'Test Portal', 'url' => 'https://portal.example.test', 'timezone' => 'Europe/Warsaw', 'locale' => 'pl', 'session_lifetime' => 7200],
            'security' => ['app_key' => base64_encode(random_bytes(32)), 'encryption_key' => base64_encode(random_bytes(32)), 'csrf_secret' => base64_encode(random_bytes(32))],
            'proxmox' => ['skipped' => true], 'config_written' => ['verified' => true],
        ];
    }

    private function cleanInstallerFixtures(): void
    {
        self::$pdo->exec("DELETE FROM settings WHERE setting_key IN ('portal.name','portal.default_locale','portal.base_url','portal.timezone','portal.session_lifetime')");
        self::$pdo->exec("DELETE FROM users WHERE username IN ('installer_owner','admin') OR email IN ('installer-owner@example.test','another@example.test','admin@localhost.invalid')");
    }

    private function temporaryRoot(): string
    {
        $root = sys_get_temp_dir() . '/cloud-portal-finalizer-' . bin2hex(random_bytes(8));
        foreach (['config', 'storage', 'storage/logs', 'storage/cache', 'resources/views'] as $directory) mkdir($root . '/' . $directory, 0700, true);
        copy(dirname(__DIR__, 2) . '/config/defaults.php', $root . '/config/defaults.php');
        $this->temporaryDirectories[] = $root;
        return $root;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($directory);
    }
}
