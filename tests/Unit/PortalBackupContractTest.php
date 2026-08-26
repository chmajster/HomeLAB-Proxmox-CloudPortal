<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PortalBackupContractTest extends TestCase
{
    public function testBackupContainsDatabaseRuntimeLockAndIntegrityManifest(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/portal-backup.php');

        foreach (['database.sql', 'runtime.php', 'installed.lock', 'manifest.json'] as $required) {
            self::assertStringContainsString($required, $source);
        }
        self::assertStringContainsString("hash_file('sha256'", $source);
        self::assertStringContainsString("hash_equals(\$expected", $source);
        self::assertStringContainsString("@chmod(\$output, 0600)", $source);
    }

    public function testRestoreIsExplicitAndFailClosed(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/portal-backup.php');

        self::assertStringContainsString("(\$options['force'] ?? false) !== true", $source);
        self::assertStringContainsString('disaster_recovery_restore', $source);
        self::assertStringContainsString('/pre-restore-', $source);
        self::assertStringContainsString("\$restored['database'] = \$current['database'];", $source);
        self::assertStringContainsString('Maintenance mode remains active', $source);
    }

    public function testExternalCommandsBypassShell(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/portal-backup.php');

        self::assertStringContainsString("proc_open(\$command", $source);
        self::assertStringContainsString("['bypass_shell' => true]", $source);
        self::assertStringNotContainsString('shell_exec(', $source);
        self::assertStringNotContainsString('exec(', $source);
        self::assertStringNotContainsString('system(', $source);
    }
}
