<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Cloud\PrivateCloudArchitecture;
use PHPUnit\Framework\TestCase;

final class PrivateCloudArchitectureTest extends TestCase
{
    public function testCanonicalPrivateCloudDomainsAreRegistered(): void
    {
        $architecture = PrivateCloudArchitecture::describe();

        self::assertSame('Algen Private Cloud Management Portal', $architecture['name']);
        self::assertSame('modular-monolith', $architecture['style']);

        $ids = array_column($architecture['domains'], 'id');
        self::assertSame($ids, array_values(array_unique($ids)));

        foreach (['identity', 'tenancy', 'compute', 'network', 'storage', 'images', 'automation', 'observability', 'governance', 'integrations'] as $domain) {
            self::assertContains($domain, $ids);
            self::assertTrue(PrivateCloudArchitecture::hasDomain($domain));
        }
    }

    public function testDomainDependenciesReferenceKnownDomains(): void
    {
        $domains = PrivateCloudArchitecture::domains();
        $known = array_flip(array_column($domains, 'id'));

        foreach ($domains as $domain) {
            foreach ($domain['depends_on'] as $dependency) {
                self::assertArrayHasKey($dependency, $known, $domain['id'] . ' references unknown dependency ' . $dependency);
            }
        }
    }

    public function testArchitectureEndpointAndDashboardBoundaryAreWired(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../routes/api.php');
        $dashboardController = file_get_contents(__DIR__ . '/../../app/Controllers/DashboardController.php');

        self::assertIsString($routes);
        self::assertIsString($dashboardController);
        self::assertStringContainsString('/api/v1/cloud/capabilities', $routes);
        self::assertStringContainsString('CloudArchitectureController', $routes);
        self::assertStringContainsString('CloudDashboardService', $dashboardController);
        self::assertStringNotContainsString('SELECT COUNT(*) AS vms', $dashboardController);
    }
}
