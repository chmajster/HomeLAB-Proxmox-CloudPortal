<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\IPAM\IPAMService;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxException;
use CloudPortal\Services\Quota\QuotaService;
use PDO;
use Throwable;

final class PlacedCreateProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly ProxmoxClientProviderInterface $clients,
        private readonly JobRepository $jobs,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @param array<string,mixed> $job */
    public function process(array $job): void
    {
        try {
            $result = $this->create($job);
            $this->jobs->complete((int) $job['id'], $result);
            $this->audit->log($job['user_id'] === null ? null : (int) $job['user_id'], '127.0.0.1', 'vm.create.placed', 'success', 'job', (string) $job['public_id'], $result);
        } catch (Throwable $exception) {
            if (!empty($job['reservation_key'])) {
                (new QuotaService($this->database->pdo()))->retainUntilReconciled((string) $job['reservation_key']);
            }
            $this->jobs->fail((int) $job['id'], $exception->getMessage());
            $this->audit->log($job['user_id'] === null ? null : (int) $job['user_id'], '127.0.0.1', 'vm.create.placed', 'failure', 'job', (string) $job['public_id'], ['error' => $exception->getMessage()]);
        }
    }

    /** @param array<string,mixed> $job */
    public function reconcileFailedCreate(array $job): bool
    {
        if ((string) ($job['type'] ?? '') !== 'vm.create.placed' || empty($job['reservation_key'])) {
            return false;
        }
        $payload = $job['payload'];
        if (!empty($payload['allocated_vmid']) && !empty($payload['node_name']) && !empty($job['connection_id'])) {
            $client = $this->clients->forConnection((int) $job['connection_id']);
            if (!$this->cleanupRemote($client, (string) $payload['node_name'], (int) $payload['allocated_vmid'])) {
                return false;
            }
        }
        $this->database->transaction(function (PDO $pdo) use ($job): void {
            (new IPAMService($pdo))->releaseReservation((string) $job['reservation_key']);
            (new QuotaService($pdo))->release((string) $job['reservation_key']);
        });
        $this->jobs->markReconciled((int) $job['id']);
        return true;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function create(array $job): array
    {
        $payload = $job['payload'];
        $client = $this->clients->forConnection((int) $job['connection_id']);
        $sourceNode = (string) $payload['source_node'];
        $targetNode = (string) $payload['node_name'];
        $vmid = (int) ($payload['allocated_vmid'] ?? 0);
        if ($vmid <= 0) {
            $vmid = (int) $client->get('/cluster/nextid');
            if ($vmid <= 0) {
                throw new \RuntimeException('Proxmox did not return a valid VMID.');
            }
            $payload['allocated_vmid'] = $vmid;
            $job['payload'] = $payload;
            $this->jobs->payload((int) $job['id'], $payload);
        }

        if (!$this->remoteExists($client, $targetNode, $vmid)) {
            $clone = $client->post('/nodes/' . rawurlencode($sourceNode) . '/qemu/' . (int) $payload['source_vmid'] . '/clone', [
                'newid' => $vmid,
                'name' => (string) $payload['name'],
                'full' => 1,
                'storage' => (string) $payload['storage_name'],
                'target' => $targetNode,
            ]);
            $this->wait($job, $client, $sourceNode, $this->requireUpid($clone), 1800);
        }

        $path = '/nodes/' . rawurlencode($targetNode) . '/qemu/' . $vmid;
        $net0 = 'virtio,bridge=' . (string) $payload['bridge'];
        if ($payload['vlan_id'] !== null) {
            $net0 .= ',tag=' . (int) $payload['vlan_id'];
        }
        $ipConfig = 'ip=' . (string) $payload['ip_cidr'];
        if (!empty($payload['gateway'])) {
            $ipConfig .= ',gw=' . (string) $payload['gateway'];
        }
        $config = [
            'cores' => (int) $payload['vcpu'],
            'memory' => (int) $payload['ram_mb'],
            'ciuser' => (string) $payload['cloud_init_user'],
            'ipconfig0' => $ipConfig,
            'net0' => $net0,
            'agent' => 'enabled=1',
        ];
        if ((string) $payload['ssh_public_key'] !== '') {
            $config['sshkeys'] = (string) $payload['ssh_public_key'];
        }
        if (!empty($payload['dns_servers'])) {
            $config['nameserver'] = str_replace(',', ' ', (string) $payload['dns_servers']);
        }
        $client->put($path . '/config', $config);
        $current = $client->get($path . '/config');
        $currentDisk = is_array($current) ? $this->diskSizeGb($current['scsi0'] ?? null) : null;
        if ($currentDisk === null) {
            throw new \RuntimeException('The cloned VM does not expose a readable scsi0 disk size.');
        }
        if ($currentDisk > (int) $payload['disk_gb']) {
            throw new \RuntimeException('The template scsi0 disk is larger than the selected resource plan.');
        }
        if ($currentDisk < (int) $payload['disk_gb']) {
            $this->wait($job, $client, $targetNode, $this->requireUpid($client->put($path . '/resize', [
                'disk' => 'scsi0',
                'size' => (int) $payload['disk_gb'] . 'G',
            ])));
        }

        $status = 'stopped';
        if ((bool) $payload['start_after_create']) {
            $this->wait($job, $client, $targetNode, $this->requireUpid($client->post($path . '/status/start')));
            $status = 'running';
        }

        $vmId = $this->persist($job, $payload, $vmid, $targetNode, $status);
        return ['virtual_machine_id' => $vmId, 'vmid' => $vmid, 'node_name' => $targetNode, 'status' => $status];
    }

    /** @param array<string,mixed> $payload */
    private function persist(array $job, array $payload, int $vmid, string $node, string $status): int
    {
        return $this->database->transaction(function (PDO $pdo) use ($job, $payload, $vmid, $node, $status): int {
            $existing = $pdo->prepare("SELECT id FROM virtual_machines WHERE connection_id=:connection AND vmid=:vmid AND status<>'deleted' LIMIT 1 FOR UPDATE");
            $existing->execute(['connection' => $job['connection_id'], 'vmid' => $vmid]);
            $existingId = $existing->fetchColumn();
            if ($existingId !== false) {
                if (!empty($job['reservation_key'])) {
                    (new IPAMService($pdo))->releaseReservation((string) $job['reservation_key']);
                    (new QuotaService($pdo))->release((string) $job['reservation_key']);
                }
                return (int) $existingId;
            }
            $statement = $pdo->prepare(
                "INSERT INTO virtual_machines
                 (connection_id,project_id,owner_user_id,template_id,resource_plan_id,network_id,storage_id,vmid,node_name,name,status,vcpu,ram_mb,disk_gb)
                 VALUES (:connection,:project,:owner,:template,:plan,:network,:storage,:vmid,:node,:name,:status,:vcpu,:ram,:disk)"
            );
            $statement->execute([
                'connection' => $job['connection_id'],
                'project' => $payload['project_id'],
                'owner' => $payload['owner_user_id'],
                'template' => $payload['template_id'],
                'plan' => $payload['plan_id'],
                'network' => $payload['network_id'],
                'storage' => $payload['storage_id'],
                'vmid' => $vmid,
                'node' => $node,
                'name' => $payload['name'],
                'status' => $status,
                'vcpu' => $payload['vcpu'],
                'ram' => $payload['ram_mb'],
                'disk' => $payload['disk_gb'],
            ]);
            $vmId = (int) $pdo->lastInsertId();
            (new IPAMService($pdo))->allocate((string) $job['reservation_key'], $vmId);
            (new QuotaService($pdo))->release((string) $job['reservation_key']);
            $pdo->prepare('UPDATE jobs SET virtual_machine_id=:vm WHERE id=:id')->execute(['vm' => $vmId, 'id' => $job['id']]);
            return $vmId;
        });
    }

    private function cleanupRemote(ProxmoxClientInterface $client, string $node, int $vmid): bool
    {
        try {
            $status = $client->get('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/status/current');
            if (is_array($status) && ($status['status'] ?? null) === 'running') {
                $this->wait(['id' => 0], $client, $node, $this->requireUpid($client->post('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/status/stop')));
            }
            $upid = $this->requireUpid($client->delete('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid, ['purge' => 1, 'destroy-unreferenced-disks' => 1]));
            $client->waitForTask($node, $upid, 1800);
            return true;
        } catch (ProxmoxException $exception) {
            return $exception->httpStatus === 404;
        } catch (Throwable) {
            return false;
        }
    }

    private function remoteExists(ProxmoxClientInterface $client, string $node, int $vmid): bool
    {
        try {
            $client->get('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/status/current');
            return true;
        } catch (ProxmoxException $exception) {
            if ($exception->httpStatus === 404) {
                return false;
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $job */
    private function wait(array $job, ProxmoxClientInterface $client, string $node, string $upid, int $timeout = 900): void
    {
        if ((int) ($job['id'] ?? 0) > 0) {
            $this->jobs->upid((int) $job['id'], $upid);
        }
        $client->waitForTask($node, $upid, $timeout);
    }

    private function diskSizeGb(mixed $disk): ?int
    {
        if (!is_string($disk) || preg_match('/(?:^|,)size=([0-9]+(?:\.[0-9]+)?)([KMGT])(?:,|$)/i', $disk, $match) !== 1) {
            return null;
        }
        $value = (float) $match[1];
        $multiplier = match (strtoupper($match[2])) {
            'K' => 1 / 1024 / 1024,
            'M' => 1 / 1024,
            'G' => 1,
            'T' => 1024,
            default => 1,
        };
        return (int) ceil($value * $multiplier);
    }

    private function requireUpid(mixed $value): string
    {
        if (!is_string($value) || !str_starts_with($value, 'UPID:')) {
            throw new \RuntimeException('Proxmox did not return a valid task UPID.');
        }
        return $value;
    }
}
