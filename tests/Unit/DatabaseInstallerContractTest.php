<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Installer\Validators\InstallerInput;
use PHPUnit\Framework\TestCase;

final class DatabaseInstallerContractTest extends TestCase
{
    public function testEmptyDatabaseNameDefaultsToCloudportalOnlyWhenContinuing(): void
    {
        $continue = InstallerInput::database([
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_name' => '',
            'db_user' => 'portal',
            'db_password' => 'secret',
        ]);
        self::assertSame('cloudportal', $continue['name']);
        self::assertTrue($continue['create_if_missing']);

        $test = InstallerInput::database([
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_name' => '',
            'db_user' => 'portal',
            'db_password' => 'secret',
            'connection_test_only' => '1',
        ]);
        self::assertSame('', $test['name']);
        self::assertTrue($test['connection_test_only']);
    }

    public function testDatabaseResetIsExplicitAndDisabledByDefault(): void
    {
        $default = InstallerInput::database([
            'db_host' => 'db.example.test',
            'db_port' => 3306,
            'db_name' => 'cloudportal',
            'db_user' => 'portal',
        ]);
        self::assertFalse($default['reset_database']);

        $reset = InstallerInput::database([
            'db_host' => 'db.example.test',
            'db_port' => 3306,
            'db_name' => 'cloudportal',
            'db_user' => 'portal',
            'reset_database' => '1',
        ]);
        self::assertTrue($reset['reset_database']);
    }

    public function testInstallerRendersDestructiveResetCheckboxServerSide(): void
    {
        $wizard = (string) file_get_contents(dirname(__DIR__, 2) . '/installer/Views/wizard.php');
        self::assertStringContainsString('id="resetDatabase"', $wizard);
        self::assertStringContainsString('name="reset_database"', $wizard);
        self::assertStringContainsString('Wyczyść bazę danych i utwórz schemat od nowa', $wizard);
        self::assertStringContainsString('Uwaga: operacja destrukcyjna.', $wizard);
        self::assertStringContainsString('id="createDatabaseIfMissing"', $wizard);
    }

    public function testResetImplementationDropsViewsAndTablesOnlyDuringInitialization(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/installer/Services/DatabaseInstaller.php');
        self::assertStringContainsString("if ((bool) (\$config['reset_database'] ?? false))", $source);
        self::assertStringContainsString("SET FOREIGN_KEY_CHECKS=0", $source);
        self::assertStringContainsString('DROP VIEW IF EXISTS', $source);
        self::assertStringContainsString('DROP TABLE IF EXISTS', $source);
        self::assertStringContainsString('uprawnienia DROP', $source);
    }

    public function testFailedFreshInitializationIsCleanedAndPartialSchemaIsDetected(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/installer/Services/DatabaseInstaller.php');

        self::assertStringContainsString("\$cleanupOnFailure = \$existingTables === []", $source);
        self::assertStringContainsString('Częściowo utworzony schemat został automatycznie usunięty', $source);
        self::assertStringContainsString("'partial_portal_schema' => \$partialPortalSchema", $source);
        self::assertStringContainsString('Wykryto częściowo utworzony schemat Cloud Portal', $source);
        self::assertStringContainsString('Wyczyść bazę danych i utwórz schemat od nowa', $source);
    }

    public function testForeignKeyFailuresExposeInnoDbDiagnosticContext(): void
    {
        $root = dirname(__DIR__, 2);
        $installer = (string) file_get_contents($root . '/installer/Services/DatabaseInstaller.php');
        $migrations = (string) file_get_contents($root . '/app/Database/MigrationService.php');

        self::assertStringContainsString('SHOW ENGINE INNODB STATUS', $installer);
        self::assertStringContainsString('LATEST FOREIGN KEY ERROR', $installer);
        self::assertStringContainsString('Szczegóły InnoDB:', $installer);
        self::assertStringContainsString('Migracja %s nie powiodła się podczas wykonywania:', $migrations);
        self::assertStringContainsString('statementSummary', $migrations);
    }

    public function testMysqlReservedLastValueIdentifierIsQuotedEverywhere(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/database/migrations/1.2.0.sql');
        $generator = (string) file_get_contents($root . '/app/Services/Provisioning/HostnameGenerator.php');

        self::assertStringContainsString('`last_value` BIGINT UNSIGNED NOT NULL DEFAULT 0', $migration);
        self::assertStringContainsString('scope_key,`last_value`', $generator);
        self::assertStringContainsString('SELECT `last_value` FROM hostname_sequences', $generator);
        self::assertStringContainsString('SET `last_value`=:counter', $generator);
    }
}
