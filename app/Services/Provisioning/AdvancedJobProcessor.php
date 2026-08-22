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

final class AdvancedJobProcessor
{
    private const TYPES = [
        'vm.snapshot.rollback','vm.clone','vm.reconfigure','vm.disk.attach','vm.disk.detach',
        'vm.nic.upsert','vm.nic.delete','vm.migrate','vm.backup','vm.restore',
    ];

    public function __construct(
        private readonly Database $database,
        private readonly ProxmoxClientProviderInterface $clients,
        private readonly JobRepository $jobs,
        private readonly AuditLogger $audit,
    ) {
    }

    public function supports(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** @param array<string,mixed> $job */
    public function process(array $job): void
    {
        try {
            $result = match ((string) $job['type']) {
                'vm.snapshot.rollback' => $this->rollback($job),
                'vm.clone' => $this->cloneVm($job),
                'vm.reconfigure' => $this->reconfigure($job),
                'vm.disk.attach' => $this->attachDisk($job),
                'vm.disk.detach' => $this->detachDisk($job),
                'vm.nic.upsert' => $this->upsertNic($job),
                'vm.nic.delete' => $this->deleteNic($job),
                'vm.migrate' => $this->migrate($job),
                'vm.backup' => $this->backup($job),
                'vm.restore' => $this->restore($job),
                default => throw new \RuntimeException('Unsupported advanced job type.'),
            };
            $this->jobs->complete((int) $job['id'], $result);
            $this->audit->log($job['user_id'] === null ? null : (int) $job['user_id'], '127.0.0.1', (string) $job['type'], 'success', 'job', (string) $job['public_id'], $result);
        } catch (Throwable $exception) {
            if (in_array((string) $job['type'], ['vm.backup','vm.restore'], true) && isset($job['payload']['backup_id'])) {
                $this->database->pdo()->prepare("UPDATE backups SET status='error',last_error=:error WHERE id=:id")
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'id' => $job['payload']['backup_id']]);
            }
            $this->jobs->fail((int) $job['id'], $exception->getMessage());
            $this->audit->log($job['user_id'] === null ? null : (int) $job['user_id'], '127.0.0.1', (string) $job['type'], 'failure', 'job', (string) $job['public_id'], ['error' => $exception->getMessage()]);
        }
    }

    private function rollback(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $upid = $this->requireUpid($client->post($this->path($vm) . '/snapshot/' . rawurlencode((string) $job['payload']['name']) . '/rollback'));
        $this->wait($job, $client, (string) $vm['node_name'], $upid);
        return ['virtual_machine_id' => (int) $vm['id'], 'snapshot' => (string) $job['payload']['name'], 'rolled_back' => true];
    }

    private function cloneVm(array $job): array
    {
        $payload = $job['payload'];
        $client = $this->clients->forConnection((int) $job['connection_id']);
        $vmid = (int) ($payload['allocated_vmid'] ?? 0);
        if ($vmid <= 0) {
            $vmid = (int) $client->get('/cluster/nextid');
            if ($vmid <= 0) throw new \RuntimeException('Proxmox did not return a valid VMID for clone.');
            $payload['allocated_vmid'] = $vmid;
            $this->jobs->payload((int) $job['id'], $payload);
        }
        $sourceNode = (string) $payload['source_node'];
        $targetNode = (string) $payload['node_name'];
        if (!$this->remoteVmExists($client, $targetNode, $vmid)) {
            $data = ['newid' => $vmid, 'name' => (string) $payload['name'], 'full' => 1, 'storage' => (string) $payload['storage_name']];
            if ($targetNode !== $sourceNode) $data['target'] = $targetNode;
            $upid = $this->requireUpid($client->post('/nodes/' . rawurlencode($sourceNode) . '/qemu/' . (int) $payload['source_vmid'] . '/clone', $data));
            $this->wait($job, $client, $sourceNode, $upid);
        }
        $this->configureNetwork($client, $targetNode, $vmid, $payload);
        $vmId = $this->persistNewVm($job, $payload, $vmid, $targetNode);
        return ['virtual_machine_id' => $vmId, 'vmid' => $vmid, 'node_name' => $targetNode, 'status' => 'stopped'];
    }

    private function reconfigure(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $payload = $job['payload'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $client->put($this->path($vm) . '/config', ['cores' => (int) $payload['vcpu'], 'memory' => (int) $payload['ram_mb']]);
        if ((int) $payload['disk_gb'] > (int) $vm['disk_gb']) {
            $upid = $this->requireUpid($client->put($this->path($vm) . '/resize', ['disk' => 'scsi0', 'size' => (int) $payload['disk_gb'] . 'G']));
            $this->wait($job, $client, (string) $vm['node_name'], $upid);
        }
        $this->database->pdo()->prepare('UPDATE virtual_machines SET vcpu=:vcpu,ram_mb=:ram,disk_gb=:disk,last_error=NULL WHERE id=:id')
            ->execute(['vcpu' => $payload['vcpu'], 'ram' => $payload['ram_mb'], 'disk' => $payload['disk_gb'], 'id' => $vm['id']]);
        return ['virtual_machine_id' => (int) $vm['id'], 'vcpu' => (int) $payload['vcpu'], 'ram_mb' => (int) $payload['ram_mb'], 'disk_gb' => (int) $payload['disk_gb']];
    }

    private function attachDisk(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $p = $job['payload'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $client->put($this->path($vm) . '/config', [(string) $p['device'] => (string) $p['storage'] . ':' . (int) $p['size_gb']]);
        $this->database->pdo()->prepare('INSERT INTO vm_disks (virtual_machine_id,device,storage_name,size_gb) VALUES (:vm,:device,:storage,:size) ON DUPLICATE KEY UPDATE storage_name=VALUES(storage_name),size_gb=VALUES(size_gb)')
            ->execute(['vm' => $vm['id'], 'device' => $p['device'], 'storage' => $p['storage'], 'size' => $p['size_gb']]);
        return ['virtual_machine_id' => (int) $vm['id'], 'device' => (string) $p['device'], 'attached' => true];
    }

    private function detachDisk(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $device = (string) $job['payload']['device'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $client->put($this->path($vm) . '/config', ['delete' => $device]);
        $this->database->pdo()->prepare('DELETE FROM vm_disks WHERE virtual_machine_id=:vm AND device=:device')->execute(['vm' => $vm['id'], 'device' => $device]);
        return ['virtual_machine_id' => (int) $vm['id'], 'device' => $device, 'detached' => true];
    }

    private function upsertNic(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $p = $job['payload'];
        $value = 'virtio,bridge=' . (string) $p['bridge'];
        if ($p['vlan_id'] !== null) $value .= ',tag=' . (int) $p['vlan_id'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $client->put($this->path($vm) . '/config', [(string) $p['device'] => $value]);
        $this->database->pdo()->prepare('INSERT INTO vm_nics (virtual_machine_id,device,bridge,vlan_id,model) VALUES (:vm,:device,:bridge,:vlan,\'virtio\') ON DUPLICATE KEY UPDATE bridge=VALUES(bridge),vlan_id=VALUES(vlan_id),model=VALUES(model)')
            ->execute(['vm' => $vm['id'], 'device' => $p['device'], 'bridge' => $p['bridge'], 'vlan' => $p['vlan_id']]);
        return ['virtual_machine_id' => (int) $vm['id'], 'device' => (string) $p['device'], 'updated' => true];
    }

    private function deleteNic(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $device = (string) $job['payload']['device'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $client->put($this->path($vm) . '/config', ['delete' => $device]);
        $this->database->pdo()->prepare('DELETE FROM vm_nics WHERE virtual_machine_id=:vm AND device=:device')->execute(['vm' => $vm['id'], 'device' => $device]);
        return ['virtual_machine_id' => (int) $vm['id'], 'device' => $device, 'deleted' => true];
    }

    private function migrate(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $target = (string) $job['payload']['target_node'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $data = ['target' => $target];
        if ((string) $vm['status'] === 'running') $data['online'] = 1;
        $upid = $this->requireUpid($client->post($this->path($vm) . '/migrate', $data));
        $this->wait($job, $client, (string) $vm['node_name'], $upid, 1800);
        $this->database->pdo()->prepare('UPDATE virtual_machines SET node_name=:node,last_error=NULL WHERE id=:id')->execute(['node' => $target, 'id' => $vm['id']]);
        return ['virtual_machine_id' => (int) $vm['id'], 'node_name' => $target, 'migrated' => true];
    }

    private function backup(array $job): array
    {
        $vm = $this->vm((int) $job['virtual_machine_id']);
        $p = $job['payload'];
        $backupId = (int) $p['backup_id'];
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $this->database->pdo()->prepare("UPDATE backups SET status='creating',last_error=NULL WHERE id=:id")->execute(['id' => $backupId]);
        $upid = $this->requireUpid($client->post('/nodes/' . rawurlencode((string) $vm['node_name']) . '/vzdump', [
            'vmid' => (int) $vm['vmid'], 'storage' => (string) $p['storage'], 'mode' => (string) $p['mode'], 'compress' => (string) $p['compression'],
        ]));
        $this->wait($job, $client, (string) $vm['node_name'], $upid, 7200);
        $content = $client->get('/nodes/' . rawurlencode((string) $vm['node_name']) . '/storage/' . rawurlencode((string) $p['storage']) . '/content', ['content' => 'backup', 'vmid' => (int) $vm['vmid']]);
        $latest = null;
        foreach (is_array($content) ? $content : [] as $item) {
            if (!is_array($item) || empty($item['volid'])) continue;
            if ($latest === null || (int) ($item['ctime'] ?? 0) > (int) ($latest['ctime'] ?? 0)) $latest = $item;
        }
        if (!is_array($latest)) throw new \RuntimeException('Backup task completed but no backup volume was discovered.');
        $this->database->pdo()->prepare("UPDATE backups SET status='ready',volume_id=:volume,size_bytes=:size,completed_at=CURRENT_TIMESTAMP,last_error=NULL WHERE id=:id")
            ->execute(['volume' => (string) $latest['volid'], 'size' => isset($latest['size']) ? (int) $latest['size'] : null, 'id' => $backupId]);
        return ['backup_id' => $backupId, 'volume_id' => (string) $latest['volid'], 'ready' => true];
    }

    private function restore(array $job): array
    {
        $p = $job['payload'];
        $backupId = (int) $p['backup_id'];
        $client = $this->clients->forConnection((int) $job['connection_id']);
        if ((string) $p['restore_mode'] === 'replace') {
            $vm = $this->vm((int) $job['virtual_machine_id']);
            $upid = $this->requireUpid($client->post('/nodes/' . rawurlencode((string) $p['node_name']) . '/qemu', [
                'archive' => (string) $p['archive'], 'vmid' => (int) $p['vmid'], 'force' => 1,
            ]));
            $this->wait($job, $client, (string) $p['node_name'], $upid, 7200);
            $this->database->pdo()->prepare("UPDATE virtual_machines SET status='stopped',last_error=NULL WHERE id=:id")->execute(['id' => $vm['id']]);
            $this->database->pdo()->prepare("UPDATE backups SET status='ready',last_error=NULL WHERE id=:id")->execute(['id' => $backupId]);
            return ['backup_id' => $backupId, 'virtual_machine_id' => (int) $vm['id'], 'replaced' => true];
        }
        $vmid = (int) ($p['allocated_vmid'] ?? 0);
        if ($vmid <= 0) {
            $vmid = (int) $client->get('/cluster/nextid');
            if ($vmid <= 0) throw new \RuntimeException('Proxmox did not return a VMID for restore.');
            $p['allocated_vmid'] = $vmid;
            $this->jobs->payload((int) $job['id'], $p);
        }
        $node = (string) $p['node_name'];
        if (!$this->remoteVmExists($client, $node, $vmid)) {
            $upid = $this->requireUpid($client->post('/nodes/' . rawurlencode($node) . '/qemu', [
                'archive' => (string) $p['archive'], 'vmid' => $vmid, 'storage' => (string) $p['storage_name'],
            ]));
            $this->wait($job, $client, $node, $upid, 7200);
        }
        $client->put('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/config', ['name' => (string) $p['name']]);
        $this->configureNetwork($client, $node, $vmid, $p);
        $vmId = $this->persistNewVm($job, $p, $vmid, $node);
        $this->database->pdo()->prepare("UPDATE backups SET status='ready',last_error=NULL WHERE id=:id")->execute(['id' => $backupId]);
        return ['backup_id' => $backupId, 'virtual_machine_id' => $vmId, 'vmid' => $vmid, 'restored' => true];
    }

    private function configureNetwork(ProxmoxClientInterface $client, string $node, int $vmid, array $p): void
    {
        $net = 'virtio,bridge=' . (string) $p['bridge'];
        if ($p['vlan_id'] !== null) $net .= ',tag=' . (int) $p['vlan_id'];
        $ip = 'ip=' . (string) $p['ip_cidr'];
        if (!empty($p['gateway'])) $ip .= ',gw=' . (string) $p['gateway'];
        $config = ['net0' => $net, 'ipconfig0' => $ip];
        if (!empty($p['dns_servers'])) $config['nameserver'] = str_replace(',', ' ', (string) $p['dns_servers']);
        $client->put('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/config', $config);
    }

    private function persistNewVm(array $job, array $p, int $vmid, string $node): int
    {
        if (!empty($job['virtual_machine_id'])) return (int) $job['virtual_machine_id'];
        return $this->database->transaction(function (PDO $pdo) use ($job, $p, $vmid, $node): int {
            $existing = $pdo->prepare('SELECT id FROM virtual_machines WHERE connection_id=:connection AND vmid=:vmid AND status<>\'deleted\' LIMIT 1');
            $existing->execute(['connection' => $job['connection_id'], 'vmid' => $vmid]);
            $id = $existing->fetchColumn();
            if ($id !== false) return (int) $id;
            $insert = $pdo->prepare('INSERT INTO virtual_machines (connection_id,project_id,owner_user_id,template_id,resource_plan_id,network_id,storage_id,vmid,node_name,name,status,vcpu,ram_mb,disk_gb) VALUES (:connection,:project,:owner,:template,:plan,:network,:storage,:vmid,:node,:name,\'stopped\',:vcpu,:ram,:disk)');
            $insert->execute([
                'connection' => $job['connection_id'], 'project' => $p['project_id'], 'owner' => $p['owner_user_id'], 'template' => $p['template_id'] ?: null,
                'plan' => $p['plan_id'] ?: null, 'network' => $p['network_id'], 'storage' => $p['storage_id'], 'vmid' => $vmid, 'node' => $node, 'name' => $p['name'],
                'vcpu' => $p['vcpu'], 'ram' => $p['ram_mb'], 'disk' => $p['disk_gb'],
            ]);
            $vmId = (int) $pdo->lastInsertId();
            if (!empty($job['reservation_key'])) {
                (new IPAMService($pdo))->allocate((string) $job['reservation_key'], $vmId);
                (new QuotaService($pdo))->release((string) $job['reservation_key']);
            }
            $pdo->prepare('UPDATE jobs SET virtual_machine_id=:vm WHERE id=:id')->execute(['vm' => $vmId, 'id' => $job['id']]);
            return $vmId;
        });
    }

    private function remoteVmExists(ProxmoxClientInterface $client, string $node, int $vmid): bool
    {
        try {
            $client->get('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/status/current');
            return true;
        } catch (ProxmoxException $exception) {
            if ($exception->httpStatus === 404) return false;
            throw $exception;
        }
    }

    private function vm(int $id): array
    {
        $statement = $this->database->pdo()->prepare("SELECT * FROM virtual_machines WHERE id=:id AND status<>'deleted'");
        $statement->execute(['id' => $id]);
        $vm = $statement->fetch();
        if (!is_array($vm)) throw new \RuntimeException('Virtual machine no longer exists.');
        return $vm;
    }

    private function path(array $vm): string
    {
        return '/nodes/' . rawurlencode((string) $vm['node_name']) . '/qemu/' . (int) $vm['vmid'];
    }

    private function wait(array $job, ProxmoxClientInterface $client, string $node, string $upid, int $timeout = 900): void
    {
        $this->jobs->upid((int) $job['id'], $upid);
        $client->waitForTask($node, $upid, $timeout);
    }

    private function requireUpid(mixed $value): string
    {
        if (!is_string($value) || !str_starts_with($value, 'UPID:')) throw new \RuntimeException('Proxmox did not return a valid task UPID.');
        return $value;
    }
}
