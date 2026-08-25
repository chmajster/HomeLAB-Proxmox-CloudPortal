<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxVmManager;
use PHPUnit\Framework\TestCase;

final class ProxmoxConsoleFallbackTest extends TestCase
{
    public function testSerialDisplayUsesXtermJsHandoffWithoutSpiceProxy(): void
    {
        $client = $this->client(['vga' => 'serial0', 'name' => 'ubuntu01']);
        $manager = new ProxmoxVmManager($this->provider($client));

        $result = $manager->console(1, 'pve', 110, '10.0.0.10', 8006);

        self::assertStringContainsString('CLOUDPORTAL_CONSOLE_MODE=xtermjs', $result);
        self::assertStringContainsString('console=kvm', $result);
        self::assertStringContainsString('xtermjs=1', $result);
        self::assertStringContainsString('vmid=110', $result);
        self::assertSame([], $client->posts);
    }

    public function testStandardDisplayUsesNoVncHandoffWithoutSpiceProxy(): void
    {
        $client = $this->client(['vga' => 'virtio', 'name' => 'linux01']);
        $manager = new ProxmoxVmManager($this->provider($client));

        $result = $manager->console(1, 'pve', 120, 'pve.lab.local', 8443);

        self::assertStringContainsString('CLOUDPORTAL_CONSOLE_MODE=novnc', $result);
        self::assertStringContainsString('https://pve.lab.local:8443/', $result);
        self::assertStringContainsString('novnc=1', $result);
        self::assertStringContainsString('resize=off', $result);
        self::assertSame([], $client->posts);
    }

    public function testQxlDisplayKeepsSpiceDownload(): void
    {
        $client = $this->client([
            'vga' => 'qxl',
            'name' => 'desktop01',
        ], [
            'type' => 'spice',
            'host' => '10.0.0.10',
            'tls-port' => 61000,
            'password' => 'ticket',
        ]);
        $manager = new ProxmoxVmManager($this->provider($client));

        $result = $manager->console(1, 'pve', 130, '10.0.0.10', 8006);

        self::assertStringStartsWith("[virt-viewer]\n", $result);
        self::assertStringContainsString('type=spice', $result);
        self::assertStringContainsString('delete-this-file=1', $result);
        self::assertCount(1, $client->posts);
        self::assertSame('/nodes/pve/qemu/130/spiceproxy', $client->posts[0][0]);
    }

    private function client(array $config, ?array $spice = null): ProxmoxClientInterface
    {
        return new class($config, $spice) implements ProxmoxClientInterface {
            /** @var list<array{0:string,1:array<string,mixed>}> */
            public array $posts = [];

            public function __construct(private array $config, private ?array $spice)
            {
            }

            public function get(string $path, array $query = []): mixed
            {
                if (str_ends_with($path, '/config')) return $this->config;
                return [];
            }

            public function post(string $path, array $data = []): mixed
            {
                $this->posts[] = [$path, $data];
                if (str_ends_with($path, '/spiceproxy')) return $this->spice;
                return null;
            }

            public function put(string $path, array $data = []): mixed
            {
                return null;
            }

            public function delete(string $path, array $data = []): mixed
            {
                return null;
            }

            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array
            {
                return [];
            }
        };
    }

    private function provider(ProxmoxClientInterface $client): ProxmoxClientProviderInterface
    {
        return new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private ProxmoxClientInterface $client)
            {
            }

            public function forConnection(int $connectionId): ProxmoxClientInterface
            {
                return $this->client;
            }
        };
    }
}
