<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HostnamePrefixControllerContractTest extends TestCase
{
    public function testManualVmCreationAppliesConfiguredPrefixBeforeProvisioning(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/VmController.php');

        self::assertStringContainsString('$input = $this->withHostnamePrefix($request->all())', $source);
        self::assertStringContainsString("setting_key='hostname_generator.prefix'", $source);
        self::assertStringContainsString('$input[\'name\'] = $prefix . $name', $source);
        self::assertStringContainsString('!str_starts_with(strtolower($name), $prefix)', $source);
    }
}
