<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FullCloneContractTest extends TestCase
{
    public function testEveryVmClonePathExplicitlyRequestsAFullClone(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            'app/Services/Provisioning/ProxmoxProvisioner.php',
            'app/Services/Provisioning/PlacedCreateProcessor.php',
            'app/Services/Provisioning/AdvancedJobProcessor.php',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents($root . '/' . $file);

            self::assertStringContainsString('/clone', $source, $file . ' must call the Proxmox clone endpoint.');
            self::assertStringContainsString("'full' => 1", $source, $file . ' must create an independent full clone.');
            self::assertStringNotContainsString("'full' => 0", $source, $file . ' must never create a linked clone.');
        }
    }
}
