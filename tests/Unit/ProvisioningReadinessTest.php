<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProvisioningReadinessTest extends TestCase
{
    public function testPortalReadinessIsSeparatedFromProvisioningResources(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../app/Controllers/PortalController.php');
        $view = file_get_contents(__DIR__ . '/../../resources/views/pages/portal.php');

        self::assertIsString($controller);
        self::assertIsString($view);
        self::assertStringContainsString('provisioningReadinessChecklist', $controller);
        self::assertStringNotContainsString("'portal' => true, 'administrator' => true", $controller);

        foreach (['proxmox_connections', 'projects', 'networks', 'storages', 'vm_templates', 'resource_plans'] as $table) {
            self::assertStringContainsString($table, $controller);
        }

        self::assertStringContainsString('Portal jest skonfigurowany.', $view);
        self::assertStringNotContainsString('Dokończ konfigurację portalu', $view);
        self::assertStringContainsString('Przygotuj zasoby do tworzenia VM', $view);
    }
}
