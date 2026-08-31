<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Http\HttpException;
use CloudPortal\Services\Proxmox\ConsoleToken;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxConsoleSessionService;
use CloudPortal\Services\Proxmox\ProxmoxFirewallManager;
use PHPUnit\Framework\TestCase;

final class FirewallConsoleTest extends TestCase
{
    public function testVmFirewallStateUsesProxmoxQemuFirewallEndpoints(): void
    {
        $client = $this->client([
            '/nodes/pve1/qemu/101/firewall/options' => ['enable' => 1, 'policy_in' => 'DROP', 'secret' => 'ignore'],
            '/nodes/pve1/qemu/101/firewall/rules' => [
                ['pos' => 0, 'type' => 'in', 'action' => 'ACCEPT', 'proto' => 'tcp', 'dport' => '22', 'enable' => 1, 'unknown' => 'ignore'],
            ],
        ]);

        $state = (new ProxmoxFirewallManager($this->provider($client)))->vmState(7, 'pve1', 101);

        self::assertSame(['enable' => 1, 'policy_in' => 'DROP'], $state['options']);
        self::assertSame('ACCEPT', $state['rules'][0]['action']);
        self::assertArrayNotHasKey('secret', $state['options']);
        self::assertArrayNotHasKey('unknown', $state['rules'][0]);
        self::assertSame([
            ['GET', '/nodes/pve1/qemu/101/firewall/options', []],
            ['GET', '/nodes/pve1/qemu/101/firewall/rules', []],
        ], $client->calls);
    }

    public function testVmFirewallRuleIsValidatedAndWrittenDirectlyToProxmox(): void
    {
        $client = $this->client([
            '/nodes/pve1/qemu/222/firewall/options' => ['enable' => 1],
            '/nodes/pve1/qemu/222/firewall/rules' => [],
        ]);
        $manager = new ProxmoxFirewallManager($this->provider($client));

        $manager->createVmRule(4, 'pve1', 222, [
            'type' => 'in',
            'action' => 'accept',
            'source' => '10.20.0.0/16',
            'proto' => 'tcp',
            'dport' => '22,443',
            'enable' => true,
            'log' => 'info',
            'comment' => 'admin access',
        ]);

        self::assertSame('POST', $client->calls[0][0]);
        self::assertSame('/nodes/pve1/qemu/222/firewall/rules', $client->calls[0][1]);
        self::assertSame('ACCEPT', $client->calls[0][2]['action']);
        self::assertSame(1, $client->calls[0][2]['enable']);
        self::assertSame('22,443', $client->calls[0][2]['dport']);
    }

    public function testFirewallRejectsUnsupportedActionBeforeCallingProxmox(): void
    {
        $client = $this->client();
        $manager = new ProxmoxFirewallManager($this->provider($client));

        try {
            $manager->createVmRule(1, 'pve', 100, ['type' => 'in', 'action' => 'EXEC', 'enable' => true]);
            self::fail('An unsupported firewall action was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame([], $client->calls);
        }
    }

    public function testClusterFirewallStateIncludesAliasesIpSetsAndSecurityGroups(): void
    {
        $client = $this->client([
            '/cluster/firewall/aliases' => [['name' => 'dns', 'cidr' => '10.0.0.53/32', 'comment' => 'resolver']],
            '/cluster/firewall/ipset' => [['name' => 'admins', 'comment' => 'admin networks']],
            '/cluster/firewall/ipset/admins' => [['cidr' => '10.1.0.0/16', 'nomatch' => 0]],
            '/cluster/firewall/groups' => [['group' => 'web', 'comment' => 'web policy']],
            '/cluster/firewall/groups/web' => [['pos' => 0, 'type' => 'in', 'action' => 'ACCEPT', 'proto' => 'tcp', 'dport' => '443']],
        ]);

        $state = (new ProxmoxFirewallManager($this->provider($client)))->clusterState(1);

        self::assertSame('dns', $state['aliases'][0]['name']);
        self::assertSame('10.1.0.0/16', $state['ipsets'][0]['entries'][0]['cidr']);
        self::assertSame('web', $state['groups'][0]['group']);
        self::assertSame('443', $state['groups'][0]['rules'][0]['dport']);
    }

    public function testConsoleTokenIsEncryptedAndShortLived(): void
    {
        $tokens = new ConsoleToken('test-secret-not-for-production');
        $token = $tokens->issue(['connection_id' => 3, 'vmid' => 120, 'ticket' => 'PVEVNC:secret'], 20);

        self::assertStringNotContainsString('PVEVNC', $token);
        $claims = $tokens->verify($token);
        self::assertSame(3, $claims['connection_id']);
        self::assertSame(120, $claims['vmid']);
        self::assertSame('PVEVNC:secret', $claims['ticket']);
        self::assertGreaterThan(time(), $claims['exp']);
    }

    public function testConsoleTokenRejectsTampering(): void
    {
        $tokens = new ConsoleToken('test-secret-not-for-production');
        $token = $tokens->issue(['connection_id' => 1], 20);
        $last = substr($token, -1);
        $tampered = substr($token, 0, -1) . ($last === 'A' ? 'B' : 'A');

        $this->expectException(\RuntimeException::class);
        $tokens->verify($tampered);
    }

    public function testNoVncSessionRequestsVncProxyAndHidesTicketFromBrowserPayload(): void
    {
        $client = $this->client(
            ['/nodes/pve/qemu/300/config' => ['name' => 'demo', 'vga' => 'std']],
            ['/nodes/pve/qemu/300/vncproxy' => ['ticket' => 'PVEVNC:ticket', 'password' => 'temporary-password', 'port' => 5901]],
        );
        $tokens = new ConsoleToken('test-secret-not-for-production');
        $service = new ProxmoxConsoleSessionService($this->provider($client), $tokens);

        $session = $service->create(9, 'pve', 300, 12);
        $claims = $tokens->verify($session['token']);

        self::assertSame('novnc', $session['mode']);
        self::assertSame('temporary-password', $session['password']);
        self::assertSame('PVEVNC:ticket', $claims['ticket']);
        self::assertSame(5901, $claims['port']);
        self::assertSame(12, $claims['user_id']);
        self::assertSame([
            ['GET', '/nodes/pve/qemu/300/config', []],
            ['POST', '/nodes/pve/qemu/300/vncproxy', ['websocket' => 1, 'generate-password' => 1]],
        ], $client->calls);
    }

    public function testSerialOnlyVmUsesExistingExternalConsoleFallback(): void
    {
        $client = $this->client(['/nodes/pve/qemu/301/config' => ['vga' => 'serial0']]);
        $service = new ProxmoxConsoleSessionService($this->provider($client), new ConsoleToken('test-secret-not-for-production'));

        try {
            $service->create(1, 'pve', 301, 1);
            self::fail('Serial-only display incorrectly started noVNC.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
            self::assertCount(1, $client->calls);
        }
    }

    /** @param array<string,mixed> $getResponses @param array<string,mixed> $postResponses */
    private function client(array $getResponses = [], array $postResponses = []): ProxmoxClientInterface
    {
        return new class($getResponses, $postResponses) implements ProxmoxClientInterface {
            /** @var list<array{string,string,array<string,mixed>}> */
            public array $calls = [];

            /** @param array<string,mixed> $getResponses @param array<string,mixed> $postResponses */
            public function __construct(private array $getResponses, private array $postResponses)
            {
            }

            public function get(string $path, array $query = []): mixed
            {
                $this->calls[] = ['GET', $path, $query];
                return $this->getResponses[$path] ?? [];
            }

            public function post(string $path, array $data = []): mixed
            {
                $this->calls[] = ['POST', $path, $data];
                return $this->postResponses[$path] ?? null;
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
    }

    private function provider(ProxmoxClientInterface $client): ProxmoxClientProviderInterface
    {
        return new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientInterface $client)
            {
            }

            public function forConnection(int $connectionId): ProxmoxClientInterface
            {
                return $this->client;
            }
        };
    }
}
