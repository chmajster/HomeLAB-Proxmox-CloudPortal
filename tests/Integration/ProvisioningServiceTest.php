<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Database\Database;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\Provisioning\JobRepository;
use CloudPortal\Services\Provisioning\ProxmoxProvisioner;
use CloudPortal\Services\Provisioning\ProvisioningRequestService;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxException;
use CloudPortal\Support\Config;

final class ProvisioningServiceTest extends MariaDbTestCase
{
    public function testSuccessfulProvisioningCommitsVmIpAndQuota(): void
    {
        [$database, $job] = $this->queuedCreate();
        $client = new RecordingProxmoxClient(false);
        $this->provisioner($database, $client)->process($job);

        $storedJob = self::$pdo->prepare('SELECT status,virtual_machine_id FROM jobs WHERE id=:id');
        $storedJob->execute(['id' => $job['id']]);
        $result = $storedJob->fetch();
        self::assertSame('completed', $result['status']);
        self::assertGreaterThan(0, (int) $result['virtual_machine_id']);
        $ip = self::$pdo->prepare('SELECT state,virtual_machine_id FROM ip_addresses WHERE reservation_key IS NULL AND state=\'allocated\'');
        $ip->execute();
        self::assertSame((int) $result['virtual_machine_id'], (int) $ip->fetch()['virtual_machine_id']);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM quota_reservations')->fetchColumn());
        self::assertContains('POST /nodes/pve/qemu/9000/clone', $client->calls);
        self::assertContains('PUT /nodes/pve/qemu/501/config', $client->calls);
        $resize = array_search('PUT /nodes/pve/qemu/501/resize', $client->calls, true);
        self::assertIsInt($resize);
        self::assertStringStartsWith('WAIT UPID:', $client->calls[$resize + 1]);
        self::assertContains('POST /nodes/pve/qemu/501/status/start', $client->calls);
    }

    public function testProxmoxFailureDeletesCloneThenReleasesReservations(): void
    {
        [$database, $job] = $this->queuedCreate();
        $client = new RecordingProxmoxClient(true);
        $this->provisioner($database, $client)->process($job);

        $storedJob = self::$pdo->prepare('SELECT status,error_message FROM jobs WHERE id=:id');
        $storedJob->execute(['id' => $job['id']]);
        $result = $storedJob->fetch();
        self::assertSame('failed', $result['status']);
        self::assertStringContainsString('simulated configuration failure', $result['error_message']);
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM virtual_machines')->fetchColumn());
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM quota_reservations')->fetchColumn());
        self::assertSame('free', self::$pdo->query('SELECT state FROM ip_addresses LIMIT 1')->fetchColumn());
        self::assertContains('DELETE /nodes/pve/qemu/501', $client->calls);
    }

    public function testUnconfirmedCleanupRetainsQuotaAndIpForReconciliation(): void
    {
        [$database, $job] = $this->queuedCreate();
        $client = new RecordingProxmoxClient(true, false);
        $this->provisioner($database, $client)->process($job);

        $reservation = self::$pdo->query('SELECT retain_until_reconciled FROM quota_reservations')->fetchColumn();
        self::assertSame(1, (int) $reservation);
        self::assertSame('reserved', self::$pdo->query('SELECT state FROM ip_addresses LIMIT 1')->fetchColumn());
        self::assertStringContainsString('reservation were retained', (string) self::$pdo->query('SELECT error_message FROM jobs LIMIT 1')->fetchColumn());

        $repository = new JobRepository(self::$pdo);
        $failed = $repository->retainedFailedCreates();
        self::assertCount(1, $failed);
        self::assertTrue($this->provisioner($database, new RecordingProxmoxClient(false))->reconcileFailedCreate($failed[0]));
        self::assertSame(0, (int) self::$pdo->query('SELECT COUNT(*) FROM quota_reservations')->fetchColumn());
        self::assertSame('free', self::$pdo->query('SELECT state FROM ip_addresses LIMIT 1')->fetchColumn());
        $result = json_decode((string) self::$pdo->query('SELECT result FROM jobs LIMIT 1')->fetchColumn(), true, 8, JSON_THROW_ON_ERROR);
        self::assertTrue($result['cleanup_reconciled']);
    }

    /** @return array{Database,array<string,mixed>} */
    private function queuedCreate(): array
    {
        $f = $this->fixture();
        self::$pdo->exec("INSERT INTO proxmox_connections(name,hostname,api_token_id,api_token_secret_encrypted) VALUES('provision-cluster','pve.test','portal!test','encrypted')");
        $connection = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO vm_templates(connection_id,node_name,vmid,name) VALUES(:connection,'pve',9000,'Ubuntu')")->execute(['connection' => $connection]);
        $template = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO networks(connection_id,name,bridge,subnet,gateway,dns_servers) VALUES(:connection,'public','vmbr0','192.0.2.0/24','192.0.2.1','1.1.1.1')")->execute(['connection' => $connection]);
        $network = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare("INSERT INTO ip_addresses(network_id,address) VALUES(:network,'192.0.2.10')")->execute(['network' => $network]);
        self::$pdo->prepare("INSERT INTO storages(connection_id,storage_name) VALUES(:connection,'local-lvm')")->execute(['connection' => $connection]);
        $storage = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO project_networks(project_id,network_id) VALUES(:project,:network)')->execute(['project' => $f['project'], 'network' => $network]);
        self::$pdo->prepare('INSERT INTO project_storages(project_id,storage_id) VALUES(:project,:storage)')->execute(['project' => $f['project'], 'storage' => $storage]);
        foreach (['project_id' => $f['project'], 'user_id' => $f['user']] as $column => $id) {
            self::$pdo->prepare("INSERT INTO quotas({$column},max_vms,max_vcpu,max_ram_mb,max_storage_gb,max_snapshots,max_ip_addresses) VALUES(:id,5,16,32768,500,5,5)")->execute(['id' => $id]);
        }
        $plan = (int) self::$pdo->query("SELECT id FROM resource_plans WHERE slug='small'")->fetchColumn();
        $database = new Database(new Config([]), self::$pdo);
        $publicId = (new ProvisioningRequestService($database))->createVm($f['user'], false, [
            'name' => 'test-vm', 'project_id' => $f['project'], 'template_id' => $template,
            'plan_id' => $plan, 'network_id' => $network, 'storage_id' => $storage,
            'cloud_init_user' => 'clouduser', 'ssh_public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJnqMiSXAEkQnV6G2xMuNQ test',
            'start_after_create' => true,
        ]);
        $statement = self::$pdo->prepare('SELECT * FROM jobs WHERE public_id=:id');
        $statement->execute(['id' => $publicId]);
        $job = $statement->fetch();
        $job['payload'] = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
        return [$database, $job];
    }

    private function provisioner(Database $database, RecordingProxmoxClient $client): ProxmoxProvisioner
    {
        $provider = new class($client) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientInterface $client) {}
            public function forConnection(int $connectionId): ProxmoxClientInterface { return $this->client; }
        };
        return new ProxmoxProvisioner($database, $provider, new JobRepository(self::$pdo), new AuditLogger(self::$pdo));
    }
}

final class RecordingProxmoxClient implements ProxmoxClientInterface
{
    /** @var list<string> */
    public array $calls = [];
    private bool $deleted = false;

    public function __construct(private readonly bool $failConfiguration, private readonly bool $confirmCleanup = true) {}

    public function get(string $path, array $query = []): mixed
    {
        $this->calls[] = 'GET ' . $path;
        if ($path === '/cluster/nextid') return 501;
        if ($this->deleted && $this->confirmCleanup && str_ends_with($path, '/status/current')) throw new ProxmoxException('not found', 404);
        if (str_ends_with($path, '/status/current')) return ['status' => 'stopped'];
        if (str_ends_with($path, '/config')) return ['scsi0' => 'local-lvm:vm-501-disk-0,size=10G'];
        return [];
    }

    public function post(string $path, array $data = []): mixed
    {
        $this->calls[] = 'POST ' . $path;
        return 'UPID:test:' . count($this->calls);
    }

    public function put(string $path, array $data = []): mixed
    {
        $this->calls[] = 'PUT ' . $path;
        if ($this->failConfiguration && str_ends_with($path, '/config')) throw new \RuntimeException('simulated configuration failure');
        return str_ends_with($path, '/resize') ? 'UPID:test:' . count($this->calls) : null;
    }

    public function delete(string $path, array $data = []): mixed
    {
        $this->calls[] = 'DELETE ' . $path;
        $this->deleted = true;
        return 'UPID:test:delete';
    }

    public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array
    {
        $this->calls[] = 'WAIT ' . $upid;
        return ['status' => 'stopped', 'exitstatus' => 'OK'];
    }
}
