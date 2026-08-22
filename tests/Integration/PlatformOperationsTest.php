<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Database\Database;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\Observability\HealthService;
use CloudPortal\Services\Placement\PlacementService;
use CloudPortal\Services\Provisioning\JobRepository;
use CloudPortal\Services\Provisioning\VmIdentityJobProcessor;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Support\Config;

final class PlatformOperationsTest extends MariaDbTestCase
{
    public function testRetryBackoffDeadLetterAndManualRetry(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        $jobs = new JobRepository(self::$pdo);
        $publicId = $jobs->enqueue('vm.reconfigure', null, null, null, [], null, null, 2);
        try {
            $first = $jobs->claimNext();
            self::assertIsArray($first);
            self::assertSame(1, $first['attempts']);
            $jobs->fail((int) $first['id'], 'first failure');
            self::$pdo->prepare('UPDATE jobs SET available_at=CURRENT_TIMESTAMP WHERE public_id=:id')->execute(['id' => $publicId]);
            $second = $jobs->claimNext();
            self::assertIsArray($second);
            self::assertSame(2, $second['attempts']);
            $jobs->fail((int) $second['id'], 'second failure');
            self::assertSame('dead_letter', $jobs->find($publicId)['status']);
            self::assertTrue($jobs->manualRetry($publicId));
            $retried = $jobs->find($publicId);
            self::assertSame('queued', $retried['status']);
            self::assertSame(0, (int) $retried['attempts']);
        } finally {
            self::$pdo->prepare('DELETE FROM jobs WHERE public_id=:id')->execute(['id' => $publicId]);
        }
    }

    public function testNonRetryableCreateFailsWithoutDuplicateRisk(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        $jobs = new JobRepository(self::$pdo);
        $publicId = $jobs->enqueue('vm.create', null, null, null, [], null, null, 3);
        try {
            $job = $jobs->claimNext();
            self::assertIsArray($job);
            $jobs->fail((int) $job['id'], 'unsafe to retry automatically');
            self::assertSame('failed', $jobs->find($publicId)['status']);
        } finally {
            self::$pdo->prepare('DELETE FROM jobs WHERE public_id=:id')->execute(['id' => $publicId]);
        }
    }

    public function testRenameJobUpdatesProxmoxAndLocalIdentity(): void
    {
        $fixture = $this->fixture();
        self::$pdo->exec("INSERT INTO proxmox_connections(name,hostname,api_token_id,api_token_secret_encrypted) VALUES('rename-test','pve-rename.example.test','test@pve!portal','encrypted')");
        $connection = (int) self::$pdo->lastInsertId();
        $insert = self::$pdo->prepare("INSERT INTO virtual_machines(connection_id,project_id,owner_user_id,vmid,node_name,name,status,vcpu,ram_mb,disk_gb) VALUES(:connection,:project,:owner,901,'pve1','old-name','stopped',2,2048,20)");
        $insert->execute(['connection' => $connection, 'project' => $fixture['project'], 'owner' => $fixture['user']]);
        $vmId = (int) self::$pdo->lastInsertId();

        $client = new class implements ProxmoxClientInterface {
            /** @var list<array{path:string,data:array<string,mixed>}> */
            public array $puts = [];
            public function get(string $path, array $query = []): mixed { return []; }
            public function post(string $path, array $data = []): mixed { return null; }
            public function put(string $path, array $data = []): mixed { $this->puts[] = ['path' => $path, 'data' => $data]; return null; }
            public function delete(string $path, array $data = []): mixed { return null; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return []; }
        };
        $provider = new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientInterface $client) {}
            public function forConnection(int $connectionId): ProxmoxClientInterface { return $this->client; }
        };

        $database = new Database(new Config([]), self::$pdo);
        $jobs = new JobRepository(self::$pdo);
        $publicId = $jobs->enqueue('vm.rename', $fixture['user'], $fixture['project'], $connection, ['name' => 'new-name', 'previous_name' => 'old-name'], null, $vmId);
        $job = $jobs->find($publicId);
        self::assertIsArray($job);
        (new VmIdentityJobProcessor($database, $provider, $jobs, new AuditLogger(self::$pdo)))->process($job);

        self::assertSame('new-name', self::$pdo->query('SELECT name FROM virtual_machines WHERE id=' . $vmId)->fetchColumn());
        self::assertSame('completed', $jobs->find($publicId)['status']);
        self::assertSame('/nodes/pve1/qemu/901/config', $client->puts[0]['path']);
        self::assertSame(['name' => 'new-name'], $client->puts[0]['data']);
    }

    public function testPlacementSkipsMaintenanceAndPrefersHealthyCapacity(): void
    {
        self::$pdo->exec("INSERT INTO proxmox_connections(name,hostname,api_token_id,api_token_secret_encrypted) VALUES('placement-test','pve.example.test','test@pve!portal','encrypted')");
        $connection = (int) self::$pdo->lastInsertId();
        $insert = self::$pdo->prepare('INSERT INTO proxmox_nodes(connection_id,node_name,status,maintenance_mode,placement_weight,cpu_usage,memory_total,memory_used) VALUES(:connection,:node,\'online\',:maintenance,:weight,:cpu,1000,:memory)');
        $insert->execute(['connection'=>$connection,'node'=>'busy','maintenance'=>0,'weight'=>100,'cpu'=>0.90,'memory'=>900]);
        $insert->execute(['connection'=>$connection,'node'=>'healthy','maintenance'=>0,'weight'=>100,'cpu'=>0.10,'memory'=>200]);
        $insert->execute(['connection'=>$connection,'node'=>'maintenance','maintenance'=>1,'weight'=>1000,'cpu'=>0.00,'memory'=>0]);
        self::assertSame('healthy', (new PlacementService(self::$pdo))->recommend($connection));
    }

    public function testHealthReportsCurrentSchemaAndQueueMetrics(): void
    {
        $report = (new HealthService(self::$pdo))->report();
        self::assertTrue($report['database']);
        self::assertTrue($report['schema_current']);
        self::assertArrayHasKey('dead_letter', $report['jobs']);
        self::assertArrayHasKey('worker_healthy', $report);
    }
}
