<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProxmoxPasswordBootstrapContractTest extends TestCase
{
    public function testPasswordBootstrapUsesTicketAndCreatesInheritedToken(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/installer/Services/ProxmoxPasswordBootstrapper.php');

        self::assertStringContainsString("'/access/ticket'", $source);
        self::assertStringContainsString("'/access/users/'", $source);
        self::assertStringContainsString("['privsep' => 0, 'comment' => 'Algen Cloud Portal']", $source);
        self::assertStringContainsString('CSRFPreventionToken: ', $source);
        self::assertStringContainsString('PVEAuthCookie=', $source);
        self::assertStringContainsString('NeedTFA', $source);
    }

    public function testExistingTokenRequiresExplicitSecondSubmissionBeforeDelete(): void
    {
        $root = dirname(__DIR__, 2);
        $bootstrap = (string) file_get_contents($root . '/installer/Services/ProxmoxPasswordBootstrapper.php');
        $controller = (string) file_get_contents($root . '/installer/Controllers/ProxmoxSetupController.php');

        self::assertStringContainsString('bool $replaceExisting = false', $bootstrap);
        self::assertStringContainsString('tokenExists(', $bootstrap);
        self::assertStringContainsString('if (!$replaceExisting)', $bootstrap);
        self::assertStringContainsString("\$this->request(\$config, 'DELETE', \$path, [], \$session)", $bootstrap);
        self::assertStringContainsString("'errors'", $bootstrap);
        self::assertStringContainsString("'installer_proxmox_replace_token'", $controller);
        self::assertStringContainsString('hash_equals($pendingReplacement, $replacementKey)', $controller);
        self::assertStringContainsString('Czy usunąć istniejący token i utworzyć nowy?', $controller);
        self::assertStringContainsString('Jeśli chcesz zachować istniejący token, zmień nazwę tworzonego tokenu', $controller);
    }

    public function testDedicatedControllerNeverPersistsProxmoxPassword(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/installer/Controllers/ProxmoxSetupController.php');

        self::assertStringContainsString("unset(\$input[\$key])", $source);
        self::assertStringContainsString("'proxmox_password'", $source);
        self::assertStringContainsString('sodium_memzero($password)', $source);
        self::assertStringContainsString("'token_secret_encrypted'", $source);
        self::assertStringNotContainsString("'password_encrypted'", $source);
    }

    public function testProxmoxInstallerUsesDedicatedSaveAndTestRoutes(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/routes/web.php');
        $javascript = (string) file_get_contents($root . '/public/assets/js/installer.js');

        self::assertStringContainsString('ProxmoxSetupController', $routes);
        self::assertStringContainsString("'/install/proxmox'", $routes);
        self::assertStringContainsString("'/install/test/proxmox'", $routes);
        self::assertStringContainsString("form.action = url('/install/proxmox')", $javascript);
        self::assertStringContainsString('Login i hasło — utwórz token automatycznie', $javascript);
        self::assertStringContainsString('id="proxmox_password"', $javascript);
        self::assertStringContainsString('Test nie utworzył tokenu', $javascript);
    }
}
