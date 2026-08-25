<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DnsSettingsProvisioningTest extends TestCase
{
    public function testDedicatedDnsRoutesPrecedeGenericAdminRoutes(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/routes/api.php');
        $dnsGet = strpos($routes, "'/api/v1/admin/settings/dns', [\$dnsSettings, 'show']");
        $dnsSave = strpos($routes, "'/api/v1/admin/settings/dns', [\$dnsSettings, 'update']");
        $dnsTest = strpos($routes, "'/api/v1/admin/settings/dns/test', [\$dnsSettings, 'test']");
        $safeSettingsPost = strpos($routes, "'/api/v1/admin/settings', [\$dnsSettings, 'upsertSafe']");
        $genericGet = strpos($routes, "'/api/v1/admin/{resource}', [\$admin, 'index']");
        $genericPost = strpos($routes, "'/api/v1/admin/{resource}', [\$admin, 'create']");

        foreach ([$dnsGet, $dnsSave, $dnsTest, $safeSettingsPost, $genericGet, $genericPost] as $position) {
            self::assertNotFalse($position);
        }
        self::assertLessThan($genericGet, $dnsGet);
        self::assertLessThan($genericPost, $safeSettingsPost);
    }

    public function testSettingsPageUsesDedicatedDnsFormWithoutExposingToken(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $script = (string) file_get_contents($root . '/public/assets/js/settings.js');
        $service = (string) file_get_contents($root . '/app/Services/DNS/DnsSettingsService.php');
        $controller = (string) file_get_contents($root . '/app/Controllers/DnsSettingsController.php');

        self::assertStringContainsString("'settings'", $layout);
        self::assertStringContainsString('settings.js', $layout);
        foreach (['server_ip', 'api_token', 'forward_zone', 'hostname_pattern', '/api/v1/admin/settings/dns/test'] as $value) {
            self::assertStringContainsString($value, $script);
        }
        self::assertStringContainsString("unset(\$config['api_token_encrypted'])", $service);
        self::assertStringContainsString("\$this->crypto->encrypt(\$newToken)", $service);
        self::assertStringContainsString("preg_match('/(?:password|secret|token|encrypted)/'", $controller);
        self::assertStringContainsString("str_starts_with(\$key, 'dns.')", $controller);
        self::assertStringNotContainsString('api_token_encrypted', $script);
    }

    public function testDnsSettingsAreSavedAtomicallyAndLegacyTokenIsMigrated(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/DNS/DnsSettingsService.php');
        self::assertStringContainsString('beginTransaction()', $source);
        self::assertStringContainsString('rollBack()', $source);
        self::assertStringContainsString("\$this->upsert('dns.api_token_encrypted', \$encrypted", $source);
        self::assertStringContainsString("\$this->value('dns.server_ip', \$this->config?->get('dns.server_ip', ''))", $source);
    }

    public function testEnabledDnsIntegrationCannotBeBypassedDuringVmCreation(): void
    {
        $root = dirname(__DIR__, 2);
        $request = (string) file_get_contents($root . '/app/Services/Provisioning/ProvisioningRequestService.php');
        $worker = (string) file_get_contents($root . '/bin/worker.php');

        self::assertStringContainsString('new DnsSettingsService($pdo, null, $this->config)', $request);
        self::assertStringContainsString('if ($dnsSettings->configured())', $request);
        self::assertStringContainsString('return true;', $request);
        self::assertStringContainsString("(\$job['payload']['managed_provisioning'] ?? false) === true", $worker);
        self::assertStringContainsString('failPermanent', $worker);
        self::assertStringContainsString('VM creation was not started.', $worker);
        self::assertStringContainsString('new DnsSettingsService($database->pdo(), $app->crypto(), $app->config)', $worker);
    }

    public function testManagedProvisioningCreatesAndVerifiesDnsBeforeTemplateClone(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Provisioning/ManagedCreateProcessor.php');
        $ensure = strpos($source, 'ensureVmRecords($hostname, $ipAddress');
        $verify = strpos($source, 'verifyVmRecords($dns[\'fqdn\'], $ipAddress)');
        $provisioning = strpos($source, "'PROVISIONING', 7, 'Create VM'");
        $terraform = strpos($source, '$this->terraformCreate->create($job)');
        $local = strpos($source, '$this->localCreate->process($job)');

        foreach ([$ensure, $verify, $provisioning, $terraform, $local] as $position) {
            self::assertNotFalse($position);
        }
        self::assertLessThan($verify, $ensure);
        self::assertLessThan($provisioning, $verify);
        self::assertLessThan($terraform, $provisioning);
        self::assertLessThan($local, $provisioning);
    }

    public function testDnsConnectionTestIsReadOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/DNS/DnsApiClient.php');
        $start = strpos($source, 'public function testConnection');
        $end = strpos($source, 'public function ensureVmRecords', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $method = substr($source, $start, $end - $start);
        self::assertStringContainsString('$this->zones()', $method);
        self::assertStringNotContainsString("request('POST'", $method);
        self::assertStringNotContainsString("request('DELETE'", $method);
    }
}
