<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SafeUpdaterTest extends TestCase
{
    public function testUpdaterHasFailClosedTransactionalControls(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/update.sh');
        self::assertIsString($script);
        foreach ([
            'set -Eeuo pipefail',
            '--package',
            '--sha256',
            '--no-checksum',
            '--rollback',
            'pending-jobs',
            'storage/maintenance.json',
            'storage/backups/updates',
            'mysqldump --defaults-extra-file=',
            'mysql --defaults-extra-file=',
            'rsync -a --delete',
            "--exclude='/storage/'",
            "--exclude='/config/runtime.php'",
            "--exclude='/.git/'",
            "--exclude='/vendor/'",
            'php "$ROOT/bin/migrate.php"',
            'Automatic rollback started.',
            'Maintenance mode remains enabled',
        ] as $required) {
            self::assertStringContainsString($required, $script);
        }
        self::assertStringNotContainsString('MYSQL_PWD=', $script);
    }

    public function testMaintenanceResponseRunsBeforeApplicationAutoload(): void
    {
        $front = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertIsString($front);
        $maintenance = strpos($front, 'storage/maintenance.json');
        $autoload = strpos($front, '$autoload =');
        self::assertNotFalse($maintenance);
        self::assertNotFalse($autoload);
        self::assertLessThan($autoload, $maintenance);
        self::assertStringContainsString('http_response_code(503)', $front);
        self::assertStringContainsString("header('Retry-After: 60')", $front);
        self::assertStringContainsString("'maintenance' => true", $front);
    }

    public function testWorkerStopsClaimingJobsDuringMaintenance(): void
    {
        $worker = file_get_contents(dirname(__DIR__, 2) . '/bin/worker.php');
        self::assertIsString($worker);
        self::assertStringContainsString('$maintenancePath = $root . \'/storage/maintenance.json\'', $worker);
        self::assertGreaterThanOrEqual(2, substr_count($worker, 'is_file($maintenancePath)'));
        self::assertStringContainsString('worker will not claim another job', $worker);
    }

    public function testDatabaseCredentialsAreWrittenOnlyToProtectedTemporaryOptionFile(): void
    {
        $helper = file_get_contents(dirname(__DIR__, 2) . '/bin/update-helper.php');
        self::assertIsString($helper);
        self::assertStringContainsString('fopen($target, \'x\')', $helper);
        self::assertStringContainsString('chmod($target, 0600)', $helper);
        self::assertStringContainsString('is_link($target)', $helper);
        self::assertStringContainsString("'password=\"'", $helper);
        self::assertStringNotContainsString('fwrite(STDOUT, $password', $helper);
    }

    public function testReleaseVersionWasIncrementedWithoutInventingSchemaMigration(): void
    {
        $application = file_get_contents(dirname(__DIR__, 2) . '/app/Application.php');
        $migration = file_get_contents(dirname(__DIR__, 2) . '/app/Database/MigrationService.php');
        self::assertIsString($application);
        self::assertIsString($migration);
        self::assertStringContainsString("public const VERSION = '1.4.0'", $application);
        self::assertStringContainsString("public const CURRENT_VERSION = '1.3.0'", $migration);
    }
}
