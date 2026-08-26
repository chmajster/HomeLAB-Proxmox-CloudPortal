<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxException;
use CloudPortal\Services\Proxmox\ProxmoxFailureMessage;
use CloudPortal\Services\Proxmox\ProxmoxVmManager;
use PHPUnit\Framework\TestCase;

final class ProjectValidationAndVmDetailsTest extends TestCase
{
    public function testStoppedVmStillReturnsConfigurationAndSnapshots(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public function get(string $path, array $query = []): mixed
            {
                if (str_ends_with($path, '/config')) {
                    return ['name' => 'stopped-vm', 'cores' => 2, 'memory' => 2048, 'scsi0' => 'local-lvm:vm-110-disk-0'];
                }
                if (str_ends_with($path, '/status/current')) {
                    throw new ProxmoxException('VM 110 not running', 500);
                }
                if (str_ends_with($path, '/snapshot')) {
                    return [
                        ['name' => 'current'],
                        ['name' => 'before-update', 'description' => 'test', 'snaptime' => 1700000000],
                    ];
                }
                throw new \RuntimeException('Unexpected GET path: ' . $path);
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

        $details = (new ProxmoxVmManager($provider))->details(1, 'pve', 110);

        self::assertFalse($details['runtime_available']);
        self::assertSame('stopped', $details['status']['status']);
        self::assertSame(110, $details['status']['vmid']);
        self::assertSame('stopped-vm', $details['config']['name']);
        self::assertSame('local-lvm:vm-110-disk-0', $details['config']['scsi0']);
        self::assertCount(1, $details['snapshots']);
        self::assertSame('before-update', $details['snapshots'][0]['name']);
        self::assertStringContainsString('zatrzymana', (string) $details['runtime_note']);
    }

    public function testNotRunningIsNotReportedAsProxmoxServerFailure(): void
    {
        $exception = ProxmoxFailureMessage::asHttpException(
            new ProxmoxException('VM 110 not running', 500),
            '10.0.0.10',
            8006,
            'Odczyt szczegółów VM',
        );

        self::assertSame(409, $exception->status);
        self::assertStringContainsString('VM 110 jest zatrzymana', $exception->getMessage());
        self::assertStringContainsString('To nie jest awaria API Proxmox', $exception->getMessage());
        self::assertSame('stopped', $exception->details['vm_state']);
    }

    public function testProjectCreateRouteUsesDetailedLocalizedValidation(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/routes/api.php');
        $controller = (string) file_get_contents($root . '/app/Controllers/ProjectCreateController.php');
        $enhancements = (string) file_get_contents($root . '/public/assets/js/portal-enhancements.js');

        $specific = strpos($routes, "'/api/v1/admin/projects', [\$projectCreate, 'create']");
        $generic = strpos($routes, "'/api/v1/admin/{resource}', [\$admin, 'create']");
        self::assertNotFalse($specific);
        self::assertNotFalse($generic);
        self::assertLessThan($generic, $specific);

        self::assertStringContainsString('Nazwa projektu jest wymagana.', $controller);
        self::assertStringContainsString('Project name is required.', $controller);
        self::assertStringContainsString('Slug może zawierać tylko małe litery', $controller);
        self::assertStringContainsString('Slug may contain only lowercase', $controller);
        self::assertStringContainsString('Przykład: moj-projekt.', $controller);
        self::assertStringContainsString('projectSlugHelp', $enhancements);
        self::assertStringContainsString("actions: 'Akcje'", $enhancements);
        self::assertStringContainsString("details: 'Szczegóły'", $enhancements);
    }

    public function testVmDetailsUseDedicatedPagesInsteadOfModalHandler(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/routes/web.php');
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $enhancements = (string) file_get_contents($root . '/public/assets/js/portal-enhancements.js');

        self::assertStringContainsString("'/vms/{id}'", $routes);
        self::assertStringContainsString("'/infrastructure/vms/{connectionId}/{node}/{vmid}'", $routes);
        self::assertStringContainsString("!in_array(\$page, ['vm-details', 'project-details', 'admin-resource-details', 'settings', 'security'], true)", $layout);
        self::assertStringContainsString('vm-details.js', $layout);
        self::assertStringContainsString("event.stopImmediatePropagation()", $enhancements);
        self::assertStringContainsString("location.assign(appUrl(`/vms/\${id}`))", $enhancements);
        self::assertStringContainsString('/infrastructure/vms/', $enhancements);
    }
}
