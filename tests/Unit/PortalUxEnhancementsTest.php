<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PortalUxEnhancementsTest extends TestCase
{
    public function testVmListAndDetailsLoadDedicatedManagementUx(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $list = (string) file_get_contents($root . '/public/assets/js/vm-list-enhancements.js');
        $details = (string) file_get_contents($root . '/public/assets/js/vm-management.js');

        self::assertStringContainsString('vm-experience.css', $layout);
        self::assertStringContainsString('vm-list-enhancements.js', $layout);
        self::assertStringContainsString('vm-management.js', $layout);
        self::assertStringContainsString('vmFriendlySummary', $list);
        self::assertStringContainsString('friendlyVmSearch', $list);
        self::assertStringContainsString('Utwórz snapshot', $details);
        self::assertStringContainsString('/snapshots', $details);
        self::assertStringContainsString("['shutdown'", $details);
        self::assertStringContainsString("['resume'", $details);
        self::assertStringContainsString('console-live', $details);
    }

    public function testTemplatePageOffersExplicitExistingProxmoxTemplateSelection(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $script = (string) file_get_contents($root . '/public/assets/js/template-selector.js');

        self::assertStringContainsString('template-selector.js', $layout);
        self::assertStringContainsString('/api/v1/admin/templates/discovery', $script);
        self::assertStringContainsString('Wybierz istniejący template z Proxmox', $script);
        self::assertStringContainsString('data-template-configure', $script);
        self::assertStringContainsString('portal_managed', (string) file_get_contents($root . '/app/Controllers/TemplateAdminController.php'));
    }

    public function testHostnamePrefixIsConfigurableAndAppliedBeforeGeneratedPattern(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $settings = (string) file_get_contents($root . '/public/assets/js/portal-settings-enhancements.js');
        $generator = (string) file_get_contents($root . '/app/Services/Provisioning/HostnameGenerator.php');

        self::assertStringContainsString('portal-settings-enhancements.js', $layout);
        self::assertStringContainsString('hostname_generator.prefix', $settings);
        self::assertStringContainsString('Prefiks hostname', $settings);
        self::assertStringContainsString("setting_key='hostname_generator.prefix'", $generator);
        self::assertStringContainsString('$this->hostnamePrefix() . $this->expandPattern', $generator);
        self::assertStringContainsString("preg_replace('/[^a-z0-9-]+/'", $generator);
    }
}
