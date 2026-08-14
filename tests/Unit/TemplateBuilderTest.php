<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxTemplateBuildDiscovery;
use CloudPortal\Services\Proxmox\ProxmoxTemplateBuilder;
use PHPUnit\Framework\TestCase;

final class TemplateBuilderTest extends TestCase
{
    public function testDiscoveryReturnsIsoTargetsImagesBridgesAndConversionCandidates(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public function get(string $path, array $query = []): mixed
            {
                return match ($path) {
                    '/nodes' => [['node' => 'pve-1', 'status' => 'online']],
                    '/storage' => [
                        ['storage' => 'local', 'type' => 'dir', 'content' => 'iso'],
                        ['storage' => 'local-lvm', 'type' => 'lvmthin', 'content' => 'images'],
                    ],
                    '/cluster/resources' => [
                        ['type' => 'qemu', 'template' => 0, 'node' => 'pve-1', 'vmid' => 101, 'name' => 'builder', 'status' => 'stopped'],
                        ['type' => 'qemu', 'template' => 1, 'node' => 'pve-1', 'vmid' => 9000, 'name' => 'ready'],
                    ],
                    '/nodes/pve-1/storage' => [
                        ['storage' => 'local', 'active' => 1, 'enabled' => 1, 'avail' => 1000],
                        ['storage' => 'local-lvm', 'active' => 1, 'enabled' => 1, 'avail' => 2000],
                    ],
                    '/nodes/pve-1/network' => [['iface' => 'vmbr0', 'type' => 'bridge', 'active' => 1]],
                    '/nodes/pve-1/storage/local/content' => [['volid' => 'local:iso/ubuntu.iso', 'size' => 123, 'ctime' => 100]],
                    default => throw new \RuntimeException('Unexpected path: ' . $path),
                };
            }
            public function post(string $path, array $data = []): mixed { return null; }
            public function put(string $path, array $data = []): mixed { return null; }
            public function delete(string $path, array $data = []): mixed { return null; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return []; }
        };
        $result = (new ProxmoxTemplateBuildDiscovery($this->provider($client)))->discover([[
            'id' => 3, 'name' => 'Cluster', 'hostname' => 'pve.test', 'port' => 8006, 'status' => 'active',
        ]]);

        self::assertSame('local:iso/ubuntu.iso', $result['iso_images'][0]['volid']);
        self::assertSame('local', $result['upload_targets'][0]['storage']);
        self::assertSame('local-lvm', $result['disk_targets'][0]['storage']);
        self::assertSame('vmbr0', $result['bridges'][0]['bridge']);
        self::assertSame(101, $result['candidates'][0]['vmid']);
        self::assertNull($result['connections'][0]['error']);
    }

    public function testBuilderCreatesValidatedInstallationVmWithCloudInit(): void
    {
        $client = new class implements ProxmoxClientInterface {
            /** @var array<string,mixed> */ public array $created = [];
            public function get(string $path, array $query = []): mixed
            {
                return match ($path) {
                    '/storage/local' => ['content' => 'iso'],
                    '/storage/local-lvm' => ['content' => 'images'],
                    '/nodes/pve-1/storage/local/status', '/nodes/pve-1/storage/local-lvm/status' => ['active' => 1, 'enabled' => 1],
                    '/nodes/pve-1/storage/local/content' => [['volid' => 'local:iso/debian.iso']],
                    '/nodes/pve-1/network' => [['iface' => 'vmbr0', 'type' => 'bridge', 'active' => 1]],
                    '/cluster/nextid' => '9010',
                    default => throw new \RuntimeException('Unexpected path: ' . $path),
                };
            }
            public function post(string $path, array $data = []): mixed { \PHPUnit\Framework\Assert::assertSame('/nodes/pve-1/qemu', $path); $this->created = $data; return 'UPID:pve-1:create:9010'; }
            public function put(string $path, array $data = []): mixed { return null; }
            public function delete(string $path, array $data = []): mixed { return null; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return []; }
        };
        $result = (new ProxmoxTemplateBuilder($this->provider($client)))->createInstallationVm(3, 'pve-1', [
            'name' => 'debian-build', 'cores' => 2, 'memory_mb' => 2048, 'disk_gb' => 20,
            'disk_storage' => 'local-lvm', 'iso_storage' => 'local', 'iso_volume' => 'local:iso/debian.iso',
            'bridge' => 'vmbr0', 'ostype' => 'l26',
        ]);

        self::assertSame(9010, $result['vmid']);
        self::assertSame('local-lvm:20,discard=on,iothread=1', $client->created['scsi0']);
        self::assertSame('local-lvm:cloudinit', $client->created['ide0']);
        self::assertSame('local:iso/debian.iso,media=cdrom', $client->created['ide2']);
        self::assertSame('order=ide2;scsi0', $client->created['boot']);
    }

    public function testConversionRequiresStoppedVmAndDetachesInstallationMedia(): void
    {
        $client = new class implements ProxmoxClientInterface {
            /** @var array<string,mixed> */ public array $updated = [];
            public function get(string $path, array $query = []): mixed
            {
                return str_ends_with($path, '/status/current')
                    ? ['status' => 'stopped']
                    : ['scsi0' => 'local-lvm:vm-101-disk-0,size=20G', 'ide0' => 'local-lvm:vm-101-cloudinit,media=cdrom', 'ide2' => 'local:iso/debian.iso,media=cdrom'];
            }
            public function post(string $path, array $data = []): mixed { \PHPUnit\Framework\Assert::assertStringEndsWith('/template', $path); return 'UPID:pve-1:template:101'; }
            public function put(string $path, array $data = []): mixed { $this->updated = $data; return null; }
            public function delete(string $path, array $data = []): mixed { return null; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return []; }
        };
        $result = (new ProxmoxTemplateBuilder($this->provider($client)))->convert(3, 'pve-1', 101);

        self::assertSame('ide2', $client->updated['delete']);
        self::assertSame('order=scsi0', $client->updated['boot']);
        self::assertSame(101, $result['vmid']);
    }

    private function provider(ProxmoxClientInterface $client): ProxmoxClientProviderInterface
    {
        return new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientInterface $client) {}
            public function forConnection(int $connectionId): ProxmoxClientInterface { return $this->client; }
        };
    }
}
