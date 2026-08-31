<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxLxcFirewallManager;
use PHPUnit\Framework\TestCase;

final class LxcFirewallTest extends TestCase
{
    public function testLxcFirewallUsesLxcEndpointsAndSharedValidation(): void
    {
        $client = new class implements ProxmoxClientInterface {
            public array $calls = [];

            public function get(string $path, array $query = []): mixed
            {
                $this->calls[] = ['GET', $path, $query];
                return str_ends_with($path, '/options') ? ['enable' => 1] : [];
            }

            public function post(string $path, array $data = []): mixed
            {
                $this->calls[] = ['POST', $path, $data];
                return null;
            }

            public function put(string $path, array $data = []): mixed
            {
                $this->calls[] = ['PUT', $path, $data];
                return null;
            }

            public function delete(string $path, array $data = []): mixed
            {
                $this->calls[] = ['DELETE', $path, $data];
                return null;
            }

            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array
            {
                return ['status' => 'stopped', 'exitstatus' => 'OK'];
            }
        };
        $provider = new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientInterface $client) {}
            public function forConnection(int $connectionId): ProxmoxClientInterface { return $this->client; }
        };

        $manager = new ProxmoxLxcFirewallManager($provider);
        $manager->createRule(2, 'pve1', 123, [
            'type' => 'in', 'action' => 'accept', 'proto' => 'tcp', 'dport' => '22', 'enable' => true,
        ]);

        self::assertSame('POST', $client->calls[0][0]);
        self::assertSame('/nodes/pve1/lxc/123/firewall/rules', $client->calls[0][1]);
        self::assertSame('ACCEPT', $client->calls[0][2]['action']);
        self::assertSame('/nodes/pve1/lxc/123/firewall/options', $client->calls[1][1]);
        self::assertSame('/nodes/pve1/lxc/123/firewall/rules', $client->calls[2][1]);
    }
}
