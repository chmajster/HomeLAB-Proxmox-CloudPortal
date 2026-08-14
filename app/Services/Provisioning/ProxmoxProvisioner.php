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

final class ProxmoxProvisioner implements ProvisionerInterface
{
    public function __construct(
        private readonly Database $database,
        private readonly ProxmoxClientProviderInterface $clients,
        private readonly JobRepository $jobs,
        private readonly AuditLogger $audit,
    ) {
    }

    public function process(array $job): void
    {
        try {
            $result = match ((string) $job['type']) {
                'vm.create' => $this->create($job),
                'vm.start', 'vm.shutdown', 'vm.stop', 'vm.reboot', 'vm.suspend', 'vm.resume' => $this->power($job),
                'vm.delete' => $this->deleteVm($job),
                'vm.snapshot.create' => $this->createSnapshot($job),
                'vm.snapshot.delete' => $this->deleteSnapshot($job),
                'vm.resize' => $this->resize($job),
                default => throw new \RuntimeException('Unsupported job type: ' . (string) $job['type']),
            };
            $this->jobs->complete((int) $job['id'], $result);
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                (string) $job['type'],
                'success',
                'job',
                (string) $job['public_id'],
                $result,
            );
        } catch (Throwable $exception) {
            if ((string) $job['type'] === 'vm.resize' && !empty($job['reservation_key'])) {
                (new QuotaService($this->database->pdo()))->release((string) $job['reservation_key']);
            }
            if ((string) $job['type'] === 'vm.delete' && !empty($job['virtual_machine_id'])) {
                $this->database->pdo()->prepare("UPDATE virtual_machines SET status = 'error', last_error = :error WHERE id = :id")
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'id' => $job['virtual_machine_id']]);
            }
            if (str_starts_with((string) $job['type'], 'vm.') && !empty($job['virtual_machine_id']) && (string) $job['type'] !== 'vm.delete') {
                $this->database->pdo()->prepare('UPDATE virtual_machines SET last_error = :error WHERE id = :id')
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'id' => $job['virtual_machine_id']]);
            }
            if (str_starts_with((string) $job['type'], 'vm.snapshot.') && isset($job['payload']['snapshot_id'])) {
                $this->database->pdo()->prepare("UPDATE snapshots SET status = 'error' WHERE id = :id")
                    ->execute(['id' => $job['payload']['snapshot_id']]);
            }
            $this->jobs->fail((int) $job['id'], $exception->getMessage());
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                (string) $job['type'],
                'failure',
                'job',
                (string) $job['public_id'],
                ['error' => $exception->getMessage()],
            );
        }
    }

    /** @param array<string,mixed> $job */
    public function recoverStale(array $job): void
    {
        try {
            if ((string) $job['type'] === 'vm.create') {
                if (!empty($job['virtual_machine_id'])) {
                    $vm = $this->vm((int) $job['virtual_machine_id']);
                    $this->jobs->complete((int) $job['id'], ['virtual_machine_id' => (int) $vm['id'], 'vmid' => (int) $vm['vmid'], 'status' => (string) $vm['status'], 'recovered' => true]);
                    return;
                }
                $payload = $job['payload'];
                if (!empty($payload['allocated_vmid']) && !empty($payload['node_name']) && !empty($job['connection_id'])) {
                    $client = $this->clients->forConnection((int) $job['connection_id']);
                    if (!empty($job['proxmox_upid'])) {
                        try {
                            $client->waitForTask((string) $payload['node_name'], (string) $job['proxmox_upid'], 900);
                        } catch (Throwable) {
                        }
                    }
                    if (!$this->cleanupProxmoxVm($client, (string) $payload['node_name'], (int) $payload['allocated_vmid'])) {
                        (new QuotaService($this->database->pdo()))->retainUntilReconciled((string) $job['reservation_key']);
                        $this->jobs->fail((int) $job['id'], 'Stale create job could not be cleaned up; quota and IP reservation retained for administrator reconciliation.');
                        return;
                    }
                }
                $this->rollbackCreate($job);
            } elseif ((string) $job['type'] === 'vm.resize' && !empty($job['reservation_key'])) {
                (new QuotaService($this->database->pdo()))->release((string) $job['reservation_key']);
            } elseif ((string) $job['type'] === 'vm.delete' && !empty($job['virtual_machine_id'])) {
                $statement = $this->database->pdo()->prepare('SELECT * FROM virtual_machines WHERE id = :id');
                $statement->execute(['id' => $job['virtual_machine_id']]);
                $vm = $statement->fetch();
                if (!is_array($vm)) {
                    $this->jobs->complete((int) $job['id'], ['status' => 'deleted', 'recovered' => true]);
                    return;
                }
                if ((string) $vm['status'] === 'deleted') {
                    $this->jobs->complete((int) $job['id'], ['virtual_machine_id' => (int) $vm['id'], 'status' => 'deleted', 'recovered' => true]);
                    return;
                }
                $client = $this->clients->forConnection((int) $vm['connection_id']);
                try {
                    $client->get($this->vmPath($vm) . '/status/current');
                    $result = $this->deleteVm($job);
                    $this->jobs->complete((int) $job['id'], [...$result, 'recovered' => true]);
                    return;
                } catch (ProxmoxException $exception) {
                    if ($exception->httpStatus !== 404) {
                        throw $exception;
                    }
                    $this->database->transaction(function (PDO $pdo) use ($vm): void {
                        (new IPAMService($pdo))->releaseVm((int) $vm['id']);
                        $pdo->prepare("UPDATE virtual_machines SET status='deleted', deleted_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['id' => $vm['id']]);
                    });
                    $this->jobs->complete((int) $job['id'], ['virtual_machine_id' => (int) $vm['id'], 'status' => 'deleted', 'recovered' => true]);
                    return;
                }
            } elseif (str_starts_with((string) $job['type'], 'vm.snapshot.') && !empty($job['virtual_machine_id']) && isset($job['payload']['snapshot_id'], $job['payload']['name'])) {
                $vm = $this->vm((int) $job['virtual_machine_id']);
                $client = $this->clients->forConnection((int) $vm['connection_id']);
                $remoteSnapshots = $client->get($this->vmPath($vm) . '/snapshot');
                $exists = false;
                foreach (is_array($remoteSnapshots) ? $remoteSnapshots : [] as $snapshot) {
                    if (is_array($snapshot) && ($snapshot['name'] ?? null) === $job['payload']['name']) {
                        $exists = true;
                        break;
                    }
                }
                if ((string) $job['type'] === 'vm.snapshot.create' && $exists) {
                    $this->database->pdo()->prepare("UPDATE snapshots SET status='ready' WHERE id=:id")->execute(['id' => $job['payload']['snapshot_id']]);
                    $this->jobs->complete((int) $job['id'], ['snapshot_id' => (int) $job['payload']['snapshot_id'], 'status' => 'ready', 'recovered' => true]);
                    return;
                }
                if ((string) $job['type'] === 'vm.snapshot.delete' && !$exists) {
                    $this->database->pdo()->prepare('DELETE FROM snapshots WHERE id=:id')->execute(['id' => $job['payload']['snapshot_id']]);
                    $this->jobs->complete((int) $job['id'], ['snapshot_id' => (int) $job['payload']['snapshot_id'], 'status' => 'deleted', 'recovered' => true]);
                    return;
                }
                $this->database->pdo()->prepare("UPDATE snapshots SET status='error' WHERE id=:id")->execute(['id' => $job['payload']['snapshot_id']]);
            } elseif (in_array((string) $job['type'], ['vm.start', 'vm.shutdown', 'vm.stop', 'vm.reboot', 'vm.suspend', 'vm.resume'], true) && !empty($job['virtual_machine_id'])) {
                $vm = $this->vm((int) $job['virtual_machine_id']);
                $current = $this->clients->forConnection((int) $vm['connection_id'])->get($this->vmPath($vm) . '/status/current');
                $status = is_array($current) && ($current['status'] ?? null) === 'running' ? 'running' : 'stopped';
                $this->database->pdo()->prepare('UPDATE virtual_machines SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $vm['id']]);
            }
            $this->jobs->fail((int) $job['id'], 'Worker interrupted; stale operation was reconciled and requires review.');
            $this->audit->log($job['user_id'] === null ? null : (int) $job['user_id'], '127.0.0.1', 'job.recover', 'failure', 'job', (string) $job['public_id'], ['type' => $job['type']]);
        } catch (Throwable $exception) {
            $this->jobs->fail((int) $job['id'], 'Stale job recovery failed: ' . $exception->getMessage());
        }
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function create(array $job): array
    {
        $payload = $job['payload'];
        $node = (string) $payload['node_name'];
        $client = null;
        $vmid = null;
        try {
            $client = $this->clients->forConnection((int) $job['connection_id']);
            $vmid = (int) $client->get('/cluster/nextid');
            if ($vmid <= 0) {
                throw new \RuntimeException('Proxmox did not return a valid VMID.');
            }
            $payload['allocated_vmid'] = $vmid;
            $job['payload'] = $payload;
            $this->jobs->payload((int) $job['id'], $payload);

        $cloneUpid = $this->requireUpid($client->post(
            '/nodes/' . rawurlencode($node) . '/qemu/' . (int) $payload['template_vmid'] . '/clone',
            [
                'newid' => $vmid,
                'name' => (string) $payload['name'],
                'full' => 1,
                'storage' => (string) $payload['storage_name'],
            ],
        ));
        $this->jobs->upid((int) $job['id'], $cloneUpid);
        $client->waitForTask($node, $cloneUpid);

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
        $vmPath = '/nodes/' . rawurlencode($node) . '/qemu/' . $vmid;
        $client->put($vmPath . '/config', $config);
        $currentConfig = $client->get($vmPath . '/config');
        $currentDiskGb = is_array($currentConfig) ? $this->diskSizeGb($currentConfig['scsi0'] ?? null) : null;
        if ($currentDiskGb === null) {
            throw new \RuntimeException('The cloned VM does not expose a readable scsi0 disk size.');
        }
        if ($currentDiskGb > (int) $payload['disk_gb']) {
            throw new \RuntimeException('The template scsi0 disk is larger than the selected resource plan.');
        }
        if ($currentDiskGb < (int) $payload['disk_gb']) {
            $resizeUpid = $this->requireUpid($client->put($vmPath . '/resize', [
                'disk' => 'scsi0',
                'size' => (int) $payload['disk_gb'] . 'G',
            ]));
            $this->jobs->upid((int) $job['id'], $resizeUpid);
            $client->waitForTask($node, $resizeUpid);
        }

        $status = 'stopped';
        if ((bool) $payload['start_after_create']) {
            $startUpid = $this->requireUpid($client->post('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/status/start'));
            $this->jobs->upid((int) $job['id'], $startUpid);
            $client->waitForTask($node, $startUpid);
            $status = 'running';
        }

        $vmId = $this->database->transaction(function (PDO $pdo) use ($job, $payload, $vmid, $status): int {
            $statement = $pdo->prepare(
                'INSERT INTO virtual_machines
                 (connection_id, project_id, owner_user_id, template_id, resource_plan_id, network_id, storage_id,
                  vmid, node_name, name, status, vcpu, ram_mb, disk_gb)
                 VALUES (:connection, :project, :owner, :template, :plan, :network, :storage,
                         :vmid, :node, :name, :status, :vcpu, :ram, :disk)'
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
                'node' => $payload['node_name'],
                'name' => $payload['name'],
                'status' => $status,
                'vcpu' => $payload['vcpu'],
                'ram' => $payload['ram_mb'],
                'disk' => $payload['disk_gb'],
            ]);
            $vmId = (int) $pdo->lastInsertId();
            (new IPAMService($pdo))->allocate((string) $job['reservation_key'], $vmId);
            (new QuotaService($pdo))->release((string) $job['reservation_key']);
            $pdo->prepare('UPDATE jobs SET virtual_machine_id = :vm WHERE id = :id')->execute(['vm' => $vmId, 'id' => $job['id']]);
            return $vmId;
        });

            return ['virtual_machine_id' => $vmId, 'vmid' => $vmid, 'status' => $status];
        } catch (Throwable $exception) {
            if ($vmid === null || ($client instanceof ProxmoxClientInterface && $this->cleanupProxmoxVm($client, $node, $vmid))) {
                $this->rollbackCreate($job);
                throw $exception;
            }
            (new QuotaService($this->database->pdo()))->retainUntilReconciled((string) $job['reservation_key']);
            throw new \RuntimeException(
                $exception->getMessage() . ' Automatic Proxmox cleanup could not be confirmed; quota and IP reservation were retained for reconciliation.',
                0,
                $exception,
            );
        }
    }

    /** @param array<string,mixed> $job */
    public function reconcileFailedCreate(array $job): bool
    {
        if ((string) ($job['type'] ?? '') !== 'vm.create' || empty($job['reservation_key'])) {
            return false;
        }
        $payload = $job['payload'];
        if (!empty($payload['allocated_vmid']) && !empty($payload['node_name']) && !empty($job['connection_id'])) {
            $client = $this->clients->forConnection((int) $job['connection_id']);
            if (!$this->cleanupProxmoxVm($client, (string) $payload['node_name'], (int) $payload['allocated_vmid'])) {
                return false;
            }
        }
        $this->rollbackCreate($job);
        $this->jobs->markReconciled((int) $job['id']);
        $this->audit->log($job['user_id'] === null ? null : (int) $job['user_id'], '127.0.0.1', 'job.reconcile', 'success', 'job', (string) $job['public_id']);
        return true;
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function power(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $action = substr((string) $job['type'], 3);
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $upid = $this->requireUpid($client->post($this->vmPath($vm) . '/status/' . $action));
        $this->jobs->upid((int) $job['id'], $upid);
        $client->waitForTask((string) $vm['node_name'], $upid);
        $status = in_array($action, ['start', 'reboot', 'resume'], true) ? 'running' : 'stopped';
        $this->database->pdo()->prepare('UPDATE virtual_machines SET status = :status, last_error = NULL WHERE id = :id')
            ->execute(['status' => $status, 'id' => $vm['id']]);
        return ['virtual_machine_id' => (int) $vm['id'], 'status' => $status];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function deleteVm(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $current = $client->get($this->vmPath($vm) . '/status/current');
        if (is_array($current) && ($current['status'] ?? null) === 'running') {
            $stop = $this->requireUpid($client->post($this->vmPath($vm) . '/status/stop'));
            $this->jobs->upid((int) $job['id'], $stop);
            $client->waitForTask((string) $vm['node_name'], $stop);
        }
        $upid = $this->requireUpid($client->delete($this->vmPath($vm), ['purge' => 1, 'destroy-unreferenced-disks' => 1]));
        $this->jobs->upid((int) $job['id'], $upid);
        $client->waitForTask((string) $vm['node_name'], $upid);
        $this->database->transaction(function (PDO $pdo) use ($vm): void {
            (new IPAMService($pdo))->releaseVm((int) $vm['id']);
            $pdo->prepare("UPDATE virtual_machines SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute(['id' => $vm['id']]);
        });
        return ['virtual_machine_id' => (int) $vm['id'], 'status' => 'deleted'];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function createSnapshot(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $payload = $job['payload'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $upid = $this->requireUpid($client->post($this->vmPath($vm) . '/snapshot', [
            'snapname' => (string) $payload['name'],
            'description' => (string) ($payload['description'] ?? ''),
        ]));
        $this->jobs->upid((int) $job['id'], $upid);
        $client->waitForTask((string) $vm['node_name'], $upid);
        $this->database->pdo()->prepare("UPDATE snapshots SET status = 'ready' WHERE id = :id")
            ->execute(['id' => $payload['snapshot_id']]);
        return ['snapshot_id' => (int) $payload['snapshot_id'], 'status' => 'ready'];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function deleteSnapshot(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $payload = $job['payload'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $upid = $this->requireUpid($client->delete($this->vmPath($vm) . '/snapshot/' . rawurlencode((string) $payload['name'])));
        $this->jobs->upid((int) $job['id'], $upid);
        $client->waitForTask((string) $vm['node_name'], $upid);
        $this->database->pdo()->prepare('DELETE FROM snapshots WHERE id = :id')->execute(['id' => $payload['snapshot_id']]);
        return ['snapshot_id' => (int) $payload['snapshot_id'], 'status' => 'deleted'];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function resize(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $payload = $job['payload'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $client->put($this->vmPath($vm) . '/config', ['cores' => $payload['vcpu'], 'memory' => $payload['ram_mb']]);
        $this->database->pdo()->prepare('UPDATE virtual_machines SET vcpu = :vcpu, ram_mb = :ram WHERE id = :id')
            ->execute(['vcpu' => $payload['vcpu'], 'ram' => $payload['ram_mb'], 'id' => $vm['id']]);
        if ((int) $payload['disk_gb'] > (int) $vm['disk_gb']) {
            $resizeUpid = $this->requireUpid($client->put($this->vmPath($vm) . '/resize', [
                'disk' => 'scsi0',
                'size' => (int) $payload['disk_gb'] . 'G',
            ]));
            $this->jobs->upid((int) $job['id'], $resizeUpid);
            $client->waitForTask((string) $vm['node_name'], $resizeUpid);
            $this->database->pdo()->prepare('UPDATE virtual_machines SET disk_gb = :disk WHERE id = :id')
                ->execute(['disk' => $payload['disk_gb'], 'id' => $vm['id']]);
        }
        $this->database->pdo()->prepare('UPDATE virtual_machines SET resource_plan_id = :plan, last_error = NULL WHERE id = :id')
            ->execute(['plan' => $payload['plan_id'], 'id' => $vm['id']]);
        if (!empty($job['reservation_key'])) {
            (new QuotaService($this->database->pdo()))->release((string) $job['reservation_key']);
        }
        return ['virtual_machine_id' => (int) $vm['id'], 'status' => (string) $vm['status']];
    }

    /** @param array<string,mixed> $job */
    private function rollbackCreate(array $job): void
    {
        $key = (string) ($job['reservation_key'] ?? '');
        if ($key === '') {
            return;
        }
        $this->database->transaction(function (PDO $pdo) use ($key): void {
            (new IPAMService($pdo))->releaseReservation($key);
            (new QuotaService($pdo))->release($key);
        });
    }

    private function cleanupProxmoxVm(ProxmoxClientInterface $client, string $node, int $vmid): bool
    {
        try {
            $path = '/nodes/' . rawurlencode($node) . '/qemu/' . $vmid;
            $current = $client->get($path . '/status/current');
            if (is_array($current) && ($current['status'] ?? null) === 'running') {
                $stop = $client->post($path . '/status/stop');
                if (is_string($stop) && str_starts_with($stop, 'UPID:')) {
                    $client->waitForTask($node, $stop, 120);
                }
            }
            $delete = $client->delete($path, ['purge' => 1, 'destroy-unreferenced-disks' => 1]);
            if (is_string($delete) && str_starts_with($delete, 'UPID:')) {
                $client->waitForTask($node, $delete, 300);
            }
            try {
                $client->get($path . '/status/current');
                return false;
            } catch (ProxmoxException $exception) {
                return $exception->httpStatus === 404;
            }
        } catch (ProxmoxException $exception) {
            return $exception->httpStatus === 404;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function vm(int $id): array
    {
        $statement = $this->database->pdo()->prepare("SELECT * FROM virtual_machines WHERE id = :id AND status <> 'deleted'");
        $statement->execute(['id' => $id]);
        return $statement->fetch() ?: throw new \RuntimeException('Virtual machine no longer exists.');
    }

    /** @param array<string,mixed> $vm */
    private function vmPath(array $vm): string
    {
        return '/nodes/' . rawurlencode((string) $vm['node_name']) . '/qemu/' . (int) $vm['vmid'];
    }

    private function requireUpid(mixed $value): string
    {
        if (!is_string($value) || !str_starts_with($value, 'UPID:')) {
            throw new \RuntimeException('Proxmox did not return a valid task UPID.');
        }
        return $value;
    }

    private function diskSizeGb(mixed $disk): ?int
    {
        if (!is_string($disk) || preg_match('/(?:^|,)size=(\d+(?:\.\d+)?)([KMGT])(?:,|$)/i', $disk, $match) !== 1) {
            return null;
        }
        $value = (float) $match[1];
        $gb = match (strtoupper($match[2])) {
            'K' => $value / 1024 / 1024,
            'M' => $value / 1024,
            'G' => $value,
            'T' => $value * 1024,
        };
        return (int) ceil($gb);
    }
}
