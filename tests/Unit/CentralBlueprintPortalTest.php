<?php

declare(strict_types=1);
namespace CloudPortal\Tests\Unit;
use PHPUnit\Framework\TestCase;

final class CentralBlueprintPortalTest extends TestCase
{
    public function testSelfServiceCatalogUsesOnlyBackendProxy(): void
    {
        $root=dirname(__DIR__,2);
        $controller=(string)file_get_contents($root.'/app/Controllers/Backend/PortalController.php');
        $view=(string)file_get_contents($root.'/resources/views/backend/portal.php');
        $script=(string)file_get_contents($root.'/public/assets/backend.js');

        self::assertStringContainsString("'/create-server'=>'catalog'",$controller);
        self::assertStringContainsString("'/blueprints?available=true'",$script);
        self::assertStringContainsString("'/blueprints/'+row.id+'/execute'",$script);
        self::assertStringContainsString('Utwórz serwer',$view);
        self::assertStringNotContainsString('ProxmoxClient',$script);
        self::assertStringNotContainsString('TerraformExecutor',$script);
    }

    public function testProxyAllowsOnlyExplicitBlueprintAndHostnameRoutes(): void
    {
        $controller=(string)file_get_contents(dirname(__DIR__,2).'/app/Controllers/Backend/PortalController.php');
        self::assertStringContainsString('blueprints/\\d+/execute',$controller);
        self::assertStringContainsString('hostnames/generate',$controller);
        self::assertStringContainsString('hostname-schemes',$controller);
    }

    public function testCentralPortalForcesBootstrapPasswordChange(): void
    {
        $controller=(string)file_get_contents(dirname(__DIR__,2).'/app/Controllers/Backend/PortalController.php');
        self::assertStringContainsString('must_change_password',$controller);
        self::assertStringContainsString("['/account','/backend-api/auth/change-password']",$controller);
        self::assertStringContainsString("Response::redirect(\$this->app->url('/account'))",$controller);
    }

    public function testNativeInstallerIsNotCapturedByUnconfiguredBackendMode(): void
    {
        $controller=(string)file_get_contents(dirname(__DIR__,2).'/app/Controllers/Backend/PortalController.php');
        self::assertStringNotContainsString("|| !\$this->app->installed()",$controller);
        self::assertStringContainsString("\$request->path==='/settings/infrastructure/backend'",$controller);
    }
}
