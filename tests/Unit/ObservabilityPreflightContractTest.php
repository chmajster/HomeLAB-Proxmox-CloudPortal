<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\Proxmox\ProxmoxCapabilityPreflight;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use PHPUnit\Framework\TestCase;

final class ObservabilityPreflightContractTest extends TestCase
{
    public function testPreflightDistinguishesReadApiFromProvisioningPrivileges(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public function get(string $path, array $query = []): mixed
            {
                if ($path === '/access/permissions') {
                    return [
                        '/' => ['Sys.Audit' => 1],
                        '/vms' => ['VM.Allocate' => 1, 'VM.Clone' => 1, 'VM.PowerMgmt' => 1],
                    ];
                }
                return [];
            }
            public function post(string $path, array $data = []): mixed { return null; }
            public function put(string $path, array $data = []): mixed { return null; }
            public function delete(string $path, array $data = []): mixed { return null; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return []; }
        };
        $provider = new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientInterface $client) {}
            public function forConnection(int $connectionId): ProxmoxClientInterface { return $this->client; }
        };

        $report = (new ProxmoxCapabilityPreflight($provider))->check(1);
        self::assertTrue($report['api_readiness']);
        self::assertFalse($report['permission_readiness']);
        self::assertFalse($report['ready_for_provisioning']);
        self::assertContains('VM.Config.Disk', $report['missing_privileges']);
        self::assertContains('Datastore.AllocateSpace', $report['missing_privileges']);
    }

    public function testPrometheusExporterContainsOperationalAndSecurityMetrics(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Observability/PrometheusMetricsService.php');
        self::assertStringContainsString('algen_cloudportal_ready', $source);
        self::assertStringContainsString('algen_cloudportal_worker_online', $source);
        self::assertStringContainsString('algen_cloudportal_jobs', $source);
        self::assertStringContainsString('algen_cloudportal_virtual_machines', $source);
        self::assertStringContainsString('algen_cloudportal_ipam_addresses', $source);
        self::assertStringContainsString('algen_cloudportal_mfa_enabled_users', $source);
    }

    public function testSecurityRoutesAreLoadedByFrontController(): void
    {
        $root = dirname(__DIR__, 2);
        $front = (string) file_get_contents($root . '/public/index.php');
        $routes = (string) file_get_contents($root . '/routes/security.php');

        self::assertStringContainsString("require \$root . '/routes/security.php'", $front);
        self::assertStringContainsString("'/metrics'", $routes);
        self::assertStringContainsString("'/api/v1/me/mfa/setup'", $routes);
        self::assertStringContainsString("'/api/v1/admin/proxmox/{connectionId}/preflight'", $routes);
    }
}
