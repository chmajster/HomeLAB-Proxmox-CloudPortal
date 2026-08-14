<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Http\HttpException;
use CloudPortal\Services\Proxmox\ProxmoxClient;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxCatalogDiscovery;
use CloudPortal\Services\Proxmox\ProxmoxConnectionInput;
use CloudPortal\Services\Proxmox\ProxmoxException;
use CloudPortal\Services\Proxmox\ProxmoxFailureMessage;
use CloudPortal\Services\Proxmox\ProxmoxNetworkDiscovery;
use CloudPortal\Services\Proxmox\ProxmoxVmDiscovery;
use CloudPortal\Services\Proxmox\ProxmoxVmManager;
use PHPUnit\Framework\TestCase;

final class ProxmoxContractTest extends TestCase
{
    public function testProductionClientRejectsPathInjectionInHostname(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProxmoxClient('pve.example.com/attacker', 8006, 'pve', 'portal!cloud', 'secret');
    }

    public function testProductionClientRejectsHeaderInjectionInSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ProxmoxClient('pve.example.com', 8006, 'pve', 'portal!cloud', "secret\r\nInjected: yes");
    }

    public function testBareRootLoginProducesAHelpfulValidationErrorInsteadOfA500(): void
    {
        try {
            ProxmoxConnectionInput::validate([
                'name' => 'S1', 'hostname' => '10.0.0.1', 'port' => '8006', 'realm' => 'pve',
                'api_token_id' => 'root', 'api_token_secret' => '1', 'verify_ssl' => false,
            ]);
            self::fail('A username was accepted as an API token ID.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertStringContainsString('root@pam!cloudportal', $exception->getMessage());
            self::assertArrayHasKey('api_token_id', $exception->details['fields']);
        }
    }

    public function testQualifiedTokenNormalizesItsRealmAndBooleanOptions(): void
    {
        $config = ProxmoxConnectionInput::validate([
            'name' => 'S1', 'hostname' => 'https://10.0.0.1/', 'port' => '8006', 'realm' => 'pve',
            'api_token_id' => 'root@pam!cloudportal', 'api_token_secret' => '1', 'verify_ssl' => false,
        ]);

        self::assertSame('10.0.0.1', $config['hostname']);
        self::assertSame(8006, $config['port']);
        self::assertSame('pam', $config['realm']);
        self::assertFalse($config['verify_ssl']);
    }

    public function testSyncFailureIsConvertedToDetailedHttpStatusInsteadOfGeneric500(): void
    {
        $http = ProxmoxFailureMessage::asHttpException(
            new ProxmoxException('authentication failure', 401, ['errors' => ['token' => 'invalid']]),
            '10.0.0.1', 8006, 'Synchronizacja Proxmox',
        );

        self::assertSame(422, $http->status);
        self::assertSame(401, $http->details['proxmox_status']);
        self::assertStringContainsString('HTTP 401 (Unauthorized)', $http->getMessage());
        self::assertStringNotContainsString('An unexpected error occurred', $http->getMessage());
    }

    public function testSyncTransportFailureExposesCurlCodeWithoutCredentialLeak(): void
    {
        $http = ProxmoxFailureMessage::asHttpException(
            new ProxmoxException('PVEAPIToken=root@pam!cloud=top-secret failed', 0, null, 7),
            '10.0.0.1', 8006, 'Synchronizacja Proxmox',
        );

        self::assertSame(422, $http->status);
        self::assertSame(7, $http->details['curl_code']);
        self::assertStringContainsString('cURL 7', $http->getMessage());
        self::assertStringNotContainsString('top-secret', $http->getMessage());
    }

    public function testUnexpectedSyncFailureReturnsDiagnosticTypeWithoutRawDetails(): void
    {
        $http = ProxmoxFailureMessage::asHttpException(
            new \RuntimeException('api_token_secret=must-not-leak'), '10.0.0.1', 8006, 'Synchronizacja Proxmox',
        );

        self::assertSame(500, $http->status);
        self::assertStringContainsString('RuntimeException', $http->getMessage());
        self::assertStringNotContainsString('must-not-leak', $http->getMessage());
        self::assertStringNotContainsString('An unexpected error occurred', $http->getMessage());
    }

    public function testUnreadableStoredTokenSecretReturnsAnActionableStatus(): void
    {
        $http = ProxmoxFailureMessage::asHttpException(
            new \RuntimeException('Encrypted value cannot be decrypted.'), '10.0.0.1', 8006, 'Synchronizacja Proxmox',
        );

        self::assertSame(422, $http->status);
        self::assertStringContainsString('Rotuj sekret', $http->getMessage());
        self::assertStringNotContainsString('An unexpected error occurred', $http->getMessage());
    }

    public function testMockBoundaryExistsOnlyAsTestDouble(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public function get(string $path, array $query = []): mixed { return ['status' => 'stopped', 'exitstatus' => 'OK']; }
            public function post(string $path, array $data = []): mixed { return 'UPID:test'; }
            public function put(string $path, array $data = []): mixed { return null; }
            public function delete(string $path, array $data = []): mixed { return 'UPID:test'; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return ['status' => 'stopped', 'exitstatus' => 'OK']; }
        };
        self::assertSame('UPID:test', $client->post('/nodes/pve/qemu/100/status/start'));
        self::assertSame('OK', $client->waitForTask('pve', 'UPID:test')['exitstatus']);
    }

    public function testNetworkDiscoveryMirrorsProxmoxInterfacesAndOnlyMarksBridgesConfigurable(): void
    {
        $client = new class implements ProxmoxClientInterface {
            /** @var list<string> */
            public array $calls = [];
            public function get(string $path, array $query = []): mixed
            {
                $this->calls[] = $path;
                return match ($path) {
                    '/nodes' => [['node' => 'pve-1', 'status' => 'online']],
                    '/nodes/pve-1/network' => [
                        ['iface' => 'eno1', 'type' => 'eth', 'active' => 1],
                        ['iface' => 'vmbr0', 'type' => 'bridge', 'active' => 1, 'autostart' => 1, 'cidr' => '10.0.0.2/24', 'gateway' => '10.0.0.1', 'bridge_ports' => 'eno1', 'bridge_vlan_aware' => 1],
                    ],
                    default => throw new \RuntimeException('Unexpected path: ' . $path),
                };
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

        $result = (new ProxmoxNetworkDiscovery($provider))->discover([[
            'id' => 7, 'name' => 'Cluster A', 'hostname' => 'pve.test', 'port' => 8006, 'status' => 'active',
        ]]);

        self::assertSame(['/nodes', '/nodes/pve-1/network'], $client->calls);
        self::assertCount(2, $result['networks']);
        self::assertFalse($result['networks'][0]['configurable']);
        self::assertSame('vmbr0', $result['networks'][1]['iface']);
        self::assertTrue($result['networks'][1]['configurable']);
        self::assertTrue($result['networks'][1]['vlan_aware']);
        self::assertSame('10.0.0.2/24', $result['networks'][1]['cidr']);
        self::assertSame(2, $result['connections'][0]['network_count']);
        self::assertNull($result['connections'][0]['error']);
    }

    public function testNetworkDiscoveryReturnsPerConnectionDiagnosticWithoutLeakingToken(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public function get(string $path, array $query = []): mixed { throw new ProxmoxException('PVEAPIToken=root@pam!portal=top-secret denied', 403); }
            public function post(string $path, array $data = []): mixed { return null; }
            public function put(string $path, array $data = []): mixed { return null; }
            public function delete(string $path, array $data = []): mixed { return null; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return []; }
        };
        $provider = new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientInterface $client) {}
            public function forConnection(int $connectionId): ProxmoxClientInterface { return $this->client; }
        };

        $result = (new ProxmoxNetworkDiscovery($provider))->discover([[
            'id' => 8, 'name' => 'Rejected cluster', 'hostname' => '10.0.0.1', 'port' => 8006, 'status' => 'active',
        ]]);

        self::assertSame([], $result['networks']);
        self::assertSame('error', $result['connections'][0]['status']);
        self::assertStringContainsString('HTTP 403 (Forbidden)', $result['connections'][0]['error']);
        self::assertStringNotContainsString('top-secret', $result['connections'][0]['error']);
        self::assertSame(403, $result['connections'][0]['details']['proxmox_status']);
    }

    public function testStorageDiscoveryMirrorsDefinitionsAndPerNodeAvailability(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public function get(string $path, array $query = []): mixed
            {
                return match ($path) {
                    '/storage' => [
                        ['storage' => 'local', 'type' => 'dir', 'content' => 'iso,vztmpl'],
                        ['storage' => 'local-lvm', 'type' => 'lvmthin', 'content' => 'images,rootdir', 'shared' => 0],
                    ],
                    '/nodes' => [['node' => 'pve-1', 'status' => 'online']],
                    '/nodes/pve-1/storage' => [
                        ['storage' => 'local', 'active' => 1, 'enabled' => 1, 'total' => 1000, 'used' => 100, 'avail' => 900],
                        ['storage' => 'local-lvm', 'active' => 1, 'enabled' => 1, 'total' => 2000, 'used' => 500, 'avail' => 1500],
                    ],
                    default => throw new \RuntimeException('Unexpected path: ' . $path),
                };
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

        $result = (new ProxmoxCatalogDiscovery($provider))->storages([[
            'id' => 9, 'name' => 'Cluster storage', 'hostname' => 'pve.test', 'port' => 8006, 'status' => 'active',
        ]]);

        self::assertCount(2, $result['storages']);
        self::assertFalse($result['storages'][0]['supports_images']);
        self::assertTrue($result['storages'][1]['supports_images']);
        self::assertSame(['pve-1'], $result['storages'][1]['available_nodes']);
        self::assertSame(1500, $result['storages'][1]['nodes'][0]['available_bytes']);
        self::assertSame(2, $result['connections'][0]['resource_count']);
    }

    public function testTemplateDiscoveryReturnsOnlyQemuTemplatesFromProxmox(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public function get(string $path, array $query = []): mixed
            {
                \PHPUnit\Framework\Assert::assertSame('/cluster/resources', $path);
                \PHPUnit\Framework\Assert::assertSame(['type' => 'vm'], $query);
                return [
                    ['type' => 'qemu', 'template' => 1, 'node' => 'pve-1', 'vmid' => 9000, 'name' => 'Ubuntu 24.04', 'maxcpu' => 2, 'maxmem' => 2147483648, 'maxdisk' => 10737418240],
                    ['type' => 'qemu', 'template' => 0, 'node' => 'pve-1', 'vmid' => 101, 'name' => 'Production VM'],
                    ['type' => 'lxc', 'template' => 1, 'node' => 'pve-1', 'vmid' => 8000, 'name' => 'Container template'],
                ];
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

        $result = (new ProxmoxCatalogDiscovery($provider))->templates([[
            'id' => 10, 'name' => 'Cluster templates', 'hostname' => 'pve.test', 'port' => 8006, 'status' => 'active',
        ]]);

        self::assertCount(1, $result['templates']);
        self::assertSame(9000, $result['templates'][0]['vmid']);
        self::assertSame('Ubuntu 24.04', $result['templates'][0]['name']);
        self::assertSame(2147483648, $result['templates'][0]['memory_bytes']);
        self::assertSame(1, $result['connections'][0]['resource_count']);
    }

    public function testVmDiscoveryReturnsAllNonTemplateQemuMachinesWithLiveUsage(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public function get(string $path, array $query = []): mixed
            {
                \PHPUnit\Framework\Assert::assertSame('/cluster/resources', $path);
                \PHPUnit\Framework\Assert::assertSame(['type' => 'vm'], $query);
                return [
                    ['type' => 'qemu', 'template' => 0, 'node' => 'pve-2', 'vmid' => 120, 'name' => 'database', 'status' => 'running', 'cpu' => 0.25, 'maxcpu' => 4, 'mem' => 1073741824, 'maxmem' => 4294967296, 'disk' => 2147483648, 'maxdisk' => 21474836480, 'uptime' => 3600],
                    ['type' => 'qemu', 'template' => 1, 'node' => 'pve-2', 'vmid' => 9000, 'name' => 'template'],
                    ['type' => 'lxc', 'template' => 0, 'node' => 'pve-2', 'vmid' => 220, 'name' => 'container'],
                ];
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

        $result = (new ProxmoxVmDiscovery($provider))->discover([[
            'id' => 11, 'name' => 'VM cluster', 'hostname' => 'pve.test', 'port' => 8006, 'status' => 'active',
        ]]);

        self::assertCount(1, $result['vms']);
        self::assertSame(120, $result['vms'][0]['vmid']);
        self::assertSame('pve-2', $result['vms'][0]['node_name']);
        self::assertSame(0.25, $result['vms'][0]['cpu_usage']);
        self::assertSame(4294967296, $result['vms'][0]['memory_total']);
        self::assertSame(1, $result['connections'][0]['vm_count']);
    }

    public function testLiveVmManagerUsesAllowlistedDetailsAndValidatedProxmoxPaths(): void
    {
        $client = new class implements ProxmoxClientInterface {
            /** @var list<string> */
            public array $calls = [];
            public function get(string $path, array $query = []): mixed
            {
                $this->calls[] = 'GET ' . $path;
                return match (true) {
                    str_ends_with($path, '/status/current') => ['name' => 'live-vm', 'status' => 'running', 'cpu' => 0.2, 'unknown_secret' => 'hidden'],
                    str_ends_with($path, '/config') => ['name' => 'live-vm', 'cores' => 4, 'scsi0' => 'local-lvm:vm-120-disk-0,size=20G', 'net0' => 'virtio=AA:BB:CC:DD:EE:FF,bridge=vmbr0', 'cipassword' => 'must-not-leak', 'sshkeys' => 'must-not-leak'],
                    str_ends_with($path, '/snapshot') => [['name' => 'before-upgrade', 'snaptime' => 123, 'description' => 'safe'], ['name' => 'current']],
                    default => throw new \RuntimeException('Unexpected GET path: ' . $path),
                };
            }
            public function post(string $path, array $data = []): mixed { $this->calls[] = 'POST ' . $path; return str_ends_with($path, '/spiceproxy') ? ['type' => 'spice', 'host' => 'pve.test', 'password' => 'temporary'] : 'UPID:test:live'; }
            public function put(string $path, array $data = []): mixed { return null; }
            public function delete(string $path, array $data = []): mixed { $this->calls[] = 'DELETE ' . $path; return 'UPID:test:delete'; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return []; }
        };
        $provider = new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientInterface $client) {}
            public function forConnection(int $connectionId): ProxmoxClientInterface { return $this->client; }
        };
        $manager = new ProxmoxVmManager($provider);

        $details = $manager->details(11, 'pve-2', 120);
        self::assertSame('running', $details['status']['status']);
        self::assertSame('local-lvm:vm-120-disk-0,size=20G', $details['config']['scsi0']);
        self::assertArrayNotHasKey('cipassword', $details['config']);
        self::assertArrayNotHasKey('sshkeys', $details['config']);
        self::assertArrayNotHasKey('unknown_secret', $details['status']);
        self::assertCount(1, $details['snapshots']);
        self::assertSame('UPID:test:live', $manager->power(11, 'pve-2', 120, 'reboot'));
        self::assertSame('UPID:test:live', $manager->snapshot(11, 'pve-2', 120, 'before-change'));
        self::assertSame('UPID:test:delete', $manager->deleteSnapshot(11, 'pve-2', 120, 'before-change'));
        self::assertStringContainsString('[virt-viewer]', $manager->console(11, 'pve-2', 120, 'pve.test'));
        self::assertContains('POST /nodes/pve-2/qemu/120/status/reboot', $client->calls);
        self::assertContains('DELETE /nodes/pve-2/qemu/120/snapshot/before-change', $client->calls);

        try {
            $manager->power(11, '../pve-2', 120, 'start');
            self::fail('A Proxmox node path traversal target was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
        }
    }
}
