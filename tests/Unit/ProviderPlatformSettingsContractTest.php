<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProviderPlatformSettingsContractTest extends TestCase
{
    public function testPortalExposesPlatformSettingsAndFiltersDisabledProviders(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root . '/app/Controllers/Backend/PortalController.php');
        $view = (string) file_get_contents($root . '/resources/views/backend/portal.php');
        $script = (string) file_get_contents($root . '/public/assets/backend.js');
        $client = (string) file_get_contents($root . '/app/Services/Infrastructure/InfrastructureBackendClient.php');

        self::assertStringContainsString("'/settings/platforms'=>'platforms'", $controller);
        self::assertStringContainsString("'platforms'=>'settings.read'", $controller);
        self::assertStringContainsString('settings/platforms/(?:proxmox|vmware|aws|azure|openstack)', $controller);

        self::assertStringContainsString("['platforms','Platformy','/settings/platforms','settings.read']", $view);

        self::assertStringContainsString("list('/settings/platforms')", $script);
        self::assertStringContainsString("p.enabled!==false&&p.type==='proxmox'", $script);
        self::assertStringContainsString("value('type')", $script);
        self::assertStringContainsString("api('/settings/platforms/'+row.name,'PUT',{enabled:!row.enabled})", $script);
        self::assertStringContainsString("['enabled','Włączona']", $script);
        self::assertStringContainsString("['connection_status','Stan połączenia']", $script);

        self::assertStringContainsString('function getPlatformSettings()', $client);
        self::assertStringContainsString('function updatePlatformSetting(', $client);
    }
}
