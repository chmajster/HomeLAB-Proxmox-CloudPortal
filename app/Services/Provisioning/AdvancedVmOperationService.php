<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use CloudPortal\Services\IPAM\IPAMService;
use CloudPortal\Services\Placement\PlacementService;
use CloudPortal\Services\Quota\QuotaExceeded;
use CloudPortal\Services\Quota\QuotaService;
use CloudPortal\Support\Uuid;
use PDO;

final class AdvancedVmOperationService
{
    private VmOperationService $base;

    public function __construct(private readonly Database $database)
    {
        $this->base = new VmOperationService($database);
    }

    public function rollbackSnapshot(int $vmId, string $snapshotName, int $userId, bool $isAdmin): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/', $snapshotName) !== 1) {
            throw new HttpException(422, 'Invalid snapshot name.');
        }
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $snapshotName, $userId, $isAdmin): string {
            $vm = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            $snapshot = $pdo->prepare("SELECT 1 FROM snapshots WHERE virtual_machine_id=:vm AND name=:name AND status='ready'");
            $snapshot->execute(['vm' => $vmId, 'name' => $snapshotName]);
            if (!$snapshot->fetchColumn()) {
                throw new HttpException(404, 'Snapshot not found or not ready.');
            }
            return (new JobRepository($pdo))->enqueue('vm.snapshot.rollback', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], ['name' => $snapshotName], null, $vmId);
        });
    }

    /** @param array<string,mixed> $input */
    public function cloneVm(int $vmId, int $userId, bool $isAdmin, array $input): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $input): string {
            $source = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            $name = trim((string) ($input['name'] ?? ''));
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{1,62}$/', $name) !== 1) {
                throw new HttpException(422, 'Clone name must contain 2-63 letters, digits or hyphens.');
            }
            $exists = $pdo->prepare("SELECT 1 FROM virtual_machines WHERE project_id=:project AND name=:name AND status<>'deleted'");
            $exists->execute(['project' => $source['project_id'], 'name' => $name]);
            if ($exists->fetchColumn()) {
                throw new HttpException(409, 'A VM with this name already exists in the project.');
            }
            $resources = $this->networkAndStorage($pdo, $source);
            $requiredNode = $this->requiredNode($resources);
            $target = trim((string) ($input['target_node'] ?? ''));
            if ($target === '') {
                $target = (new PlacementService($pdo))->recommend((int) $source['connection_id'], $requiredNode);
            } elseif ($requiredNode !== null && $target !== $requiredNode) {
                throw new HttpException(422, 'Selected network or storage requires node ' . $requiredNode . '.');
            }
            $reservationKey = Uuid::v4();
            $quota = new QuotaService($pdo);
            $this->assertParallelQuota($pdo, $source);
            $this->assertTemplateLimit($pdo, $source);
            try {
                $quota->reserve($reservationKey, (int) $source['project_id'], (int) $source['owner_user_id'], [
                    'vms' => 1,
                    'vcpu' => (int) $source['vcpu'],
                    'ram_mb' => (int) $source['ram_mb'],
                    'storage_gb' => (int) $source['disk_gb'] + $this->extraDiskGb($pdo, $vmId),
                    'ip_addresses' => 1,
                ]);
            } catch (QuotaExceeded $exception) {
                throw new HttpException(409, $exception->getMessage(), ['resource' => $exception->resource]);
            }
            $ip = (new IPAMService($pdo))->reserve((int) $source['network_id'], $reservationKey);
            $payload = [
                'source_vm_id' => $vmId,
                'source_node' => (string) $source['node_name'],
                'source_vmid' => (int) $source['vmid'],
                'node_name' => $target,
                'name' => $name,
                'project_id' => (int) $source['project_id'],
                'owner_user_id' => (int) $source['owner_user_id'],
                'template_id' => $source['template_id'] === null ? null : (int) $source['template_id'],
                'plan_id' => $source['resource_plan_id'] === null ? null : (int) $source['resource_plan_id'],
                'network_id' => (int) $source['network_id'],
                'storage_id' => (int) $source['storage_id'],
                'storage_name' => (string) $resources['storage_name'],
                'vcpu' => (int) $source['vcpu'],
                'ram_mb' => (int) $source['ram_mb'],
                'disk_gb' => (int) $source['disk_gb'],
                'bridge' => (string) $resources['bridge'],
                'vlan_id' => $resources['vlan_id'] === null ? null : (int) $resources['vlan_id'],
                'ip_address' => (string) $ip['address'],
                'ip_cidr' => (string) $ip['address'] . '/' . $this->prefixFromSubnet((string) $resources['subnet']),
                'gateway' => $resources['gateway'],
                'dns_servers' => $resources['dns_servers'],
            ];
            return (new JobRepository($pdo))->enqueue('vm.clone', $userId, (int) $source['project_id'], (int) $source['connection_id'], $payload, $reservationKey, null, 4);
        });
    }

    /** @param array<string,mixed> $input */
    public function reconfigure(int $vmId, int $userId, bool $isAdmin, array $input): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $input): string {
            $vm = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            $cores = isset($input['vcpu']) ? (int) $input['vcpu'] : (int) $vm['vcpu'];
            $ram = isset($input['ram_mb']) ? (int) $input['ram_mb'] : (int) $vm['ram_mb'];
            $disk = isset($input['disk_gb']) ? (int) $input['disk_gb'] : (int) $vm['disk_gb'];
            if ($cores < 1 || $cores > 768 || $ram < 128 || $disk < (int) $vm['disk_gb']) {
                throw new HttpException(422, 'Invalid CPU/RAM/disk values; primary disk shrinking is not supported.');
            }
            try {
                (new QuotaService($pdo))->assertAssignment($vmId, (int) $vm['project_id'], (int) $vm['owner_user_id'], [
                    'vms' => 1,
                    'vcpu' => $cores,
                    'ram_mb' => $ram,
                    'storage_gb' => $disk + $this->extraDiskGb($pdo, $vmId),
                    'ip_addresses' => $vm['ip_address'] === null ? 0 : 1,
                ]);
            } catch (QuotaExceeded $exception) {
                throw new HttpException(409, $exception->getMessage(), ['resource' => $exception->resource]);
            }
            return (new JobRepository($pdo))->enqueue('vm.reconfigure', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], [
                'vcpu' => $cores, 'ram_mb' => $ram, 'disk_gb' => $disk,
            ], null, $vmId);
        });
    }

    public function attachDisk(int $vmId, int $userId, bool $isAdmin, string $device, string $storage, int $sizeGb): string
    {
        if (preg_match('/^(?:scsi|sata|virtio)[1-9][0-9]?$/', $device) !== 1 || preg_match('/^[A-Za-z0-9._-]{1,100}$/', $storage) !== 1 || $sizeGb < 1) {
            throw new HttpException(422, 'Invalid disk device, storage or size.');
        }
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $device, $storage, $sizeGb): string {
            $vm = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            $access = $pdo->prepare("SELECT 1 FROM storages s JOIN project_storages ps ON ps.storage_id=s.id WHERE ps.project_id=:project AND s.connection_id=:connection AND s.storage_name=:storage AND s.enabled=1");
            $access->execute(['project' => $vm['project_id'], 'connection' => $vm['connection_id'], 'storage' => $storage]);
            if (!$access->fetchColumn()) {
                throw new HttpException(422, 'Storage is not available to this project.');
            }
            try {
                (new QuotaService($pdo))->assertAssignment($vmId, (int) $vm['project_id'], (int) $vm['owner_user_id'], [
                    'vms' => 1, 'vcpu' => (int) $vm['vcpu'], 'ram_mb' => (int) $vm['ram_mb'],
                    'storage_gb' => (int) $vm['disk_gb'] + $this->extraDiskGb($pdo, $vmId) + $sizeGb,
                    'ip_addresses' => $vm['ip_address'] === null ? 0 : 1,
                ]);
            } catch (QuotaExceeded $exception) {
                throw new HttpException(409, $exception->getMessage(), ['resource' => $exception->resource]);
            }
            return (new JobRepository($pdo))->enqueue('vm.disk.attach', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], ['device' => $device, 'storage' => $storage, 'size_gb' => $sizeGb], null, $vmId);
        });
    }

    public function detachDisk(int $vmId, int $userId, bool $isAdmin, string $device): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $device): string {
            $vm = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            $disk = $pdo->prepare('SELECT 1 FROM vm_disks WHERE virtual_machine_id=:vm AND device=:device');
            $disk->execute(['vm' => $vmId, 'device' => $device]);
            if (!$disk->fetchColumn()) {
                throw new HttpException(404, 'Additional disk not found.');
            }
            return (new JobRepository($pdo))->enqueue('vm.disk.detach', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], ['device' => $device], null, $vmId);
        });
    }

    public function upsertNic(int $vmId, int $userId, bool $isAdmin, string $device, string $bridge, ?int $vlanId): string
    {
        if (preg_match('/^net[0-9]{1,2}$/', $device) !== 1 || preg_match('/^[A-Za-z0-9._-]{1,32}$/', $bridge) !== 1 || ($vlanId !== null && ($vlanId < 1 || $vlanId > 4094))) {
            throw new HttpException(422, 'Invalid NIC device, bridge or VLAN.');
        }
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $device, $bridge, $vlanId): string {
            $vm = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            return (new JobRepository($pdo))->enqueue('vm.nic.upsert', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], ['device' => $device, 'bridge' => $bridge, 'vlan_id' => $vlanId], null, $vmId);
        });
    }

    public function deleteNic(int $vmId, int $userId, bool $isAdmin, string $device): string
    {
        if ($device === 'net0') {
            throw new HttpException(422, 'Primary NIC net0 cannot be removed through this operation.');
        }
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $device): string {
            $vm = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            return (new JobRepository($pdo))->enqueue('vm.nic.delete', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], ['device' => $device], null, $vmId);
        });
    }

    public function migrate(int $vmId, int $userId, bool $isAdmin, ?string $targetNode): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $targetNode): string {
            $vm = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            $target = trim((string) $targetNode);
            if ($target === '') {
                $target = (new PlacementService($pdo))->recommend((int) $vm['connection_id'], null, [(string) $vm['node_name']]);
            }
            if ($target === (string) $vm['node_name']) {
                throw new HttpException(422, 'Target node must be different from the current node.');
            }
            return (new JobRepository($pdo))->enqueue('vm.migrate', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], ['target_node' => $target], null, $vmId, 4);
        });
    }

    public function createBackup(int $vmId, int $userId, bool $isAdmin, string $storage, string $mode = 'snapshot', string $compression = 'zstd'): string
    {
        if (!in_array($mode, ['snapshot','suspend','stop'], true) || !in_array($compression, ['zstd','gzip','lzo','none'], true)) {
            throw new HttpException(422, 'Unsupported backup mode or compression.');
        }
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $storage, $mode, $compression): string {
            $vm = $this->base->accessibleVm($vmId, $userId, $isAdmin, true);
            $this->assertIdle($pdo, $vmId);
            $this->assertBackupQuota($pdo, $vm);
            $statement = $pdo->prepare('INSERT INTO backups (virtual_machine_id, connection_id, node_name, storage_name, mode, compression, created_by) VALUES (:vm,:connection,:node,:storage,:mode,:compression,:user)');
            $statement->execute(['vm' => $vmId, 'connection' => $vm['connection_id'], 'node' => $vm['node_name'], 'storage' => $storage, 'mode' => $mode, 'compression' => $compression, 'user' => $userId]);
            $backupId = (int) $pdo->lastInsertId();
            return (new JobRepository($pdo))->enqueue('vm.backup', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], ['backup_id' => $backupId, 'storage' => $storage, 'mode' => $mode, 'compression' => $compression], null, $vmId, 4);
        });
    }

    /** @param array<string,mixed> $input */
    public function restoreBackup(int $backupId, int $userId, bool $isAdmin, array $input): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($backupId, $userId, $isAdmin, $input): string {
            $statement = $pdo->prepare('SELECT b.*, vm.project_id, vm.owner_user_id, vm.template_id, vm.resource_plan_id, vm.network_id, vm.storage_id, vm.vcpu, vm.ram_mb, vm.disk_gb, vm.name AS source_name, vm.vmid AS source_vmid, vm.node_name AS source_node FROM backups b JOIN virtual_machines vm ON vm.id=b.virtual_machine_id WHERE b.id=:id AND b.status=\'ready\' FOR UPDATE');
            $statement->execute(['id' => $backupId]);
            $backup = $statement->fetch();
            if (!is_array($backup)) {
                throw new HttpException(404, 'Backup not found or not ready.');
            }
            $this->base->accessibleVm((int) $backup['virtual_machine_id'], $userId, $isAdmin, true);
            $mode = (string) ($input['restore_mode'] ?? 'new');
            if (!in_array($mode, ['new','replace'], true)) {
                throw new HttpException(422, 'restore_mode must be new or replace.');
            }
            $payload = ['backup_id' => $backupId, 'restore_mode' => $mode, 'archive' => (string) $backup['volume_id']];
            $reservationKey = null;
            $jobVmId = (int) $backup['virtual_machine_id'];
            if ($mode === 'replace') {
                if (!$isAdmin) {
                    throw new HttpException(403, 'In-place restore requires administrator permission.');
                }
                $payload['node_name'] = (string) $backup['source_node'];
                $payload['vmid'] = (int) $backup['source_vmid'];
            } else {
                $name = trim((string) ($input['name'] ?? ($backup['source_name'] . '-restore')));
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{1,62}$/', $name) !== 1) {
                    throw new HttpException(422, 'Invalid restored VM name.');
                }
                $resources = $this->networkAndStorage($pdo, $backup);
                $requiredNode = $this->requiredNode($resources);
                $target = trim((string) ($input['target_node'] ?? ''));
                if ($target === '') {
                    $target = (new PlacementService($pdo))->recommend((int) $backup['connection_id'], $requiredNode);
                }
                $reservationKey = Uuid::v4();
                try {
                    (new QuotaService($pdo))->reserve($reservationKey, (int) $backup['project_id'], (int) $backup['owner_user_id'], [
                        'vms' => 1, 'vcpu' => (int) $backup['vcpu'], 'ram_mb' => (int) $backup['ram_mb'], 'storage_gb' => (int) $backup['disk_gb'], 'ip_addresses' => 1,
                    ]);
                } catch (QuotaExceeded $exception) {
                    throw new HttpException(409, $exception->getMessage(), ['resource' => $exception->resource]);
                }
                $ip = (new IPAMService($pdo))->reserve((int) $backup['network_id'], $reservationKey);
                $payload += [
                    'node_name' => $target,
                    'name' => $name,
                    'project_id' => (int) $backup['project_id'], 'owner_user_id' => (int) $backup['owner_user_id'],
                    'template_id' => $backup['template_id'], 'plan_id' => $backup['resource_plan_id'], 'network_id' => (int) $backup['network_id'], 'storage_id' => (int) $backup['storage_id'],
                    'storage_name' => (string) $resources['storage_name'], 'vcpu' => (int) $backup['vcpu'], 'ram_mb' => (int) $backup['ram_mb'], 'disk_gb' => (int) $backup['disk_gb'],
                    'bridge' => (string) $resources['bridge'], 'vlan_id' => $resources['vlan_id'],
                    'ip_address' => (string) $ip['address'], 'ip_cidr' => (string) $ip['address'] . '/' . $this->prefixFromSubnet((string) $resources['subnet']),
                    'gateway' => $resources['gateway'], 'dns_servers' => $resources['dns_servers'],
                ];
                $jobVmId = 0;
            }
            $pdo->prepare("UPDATE backups SET status='restoring' WHERE id=:id")->execute(['id' => $backupId]);
            return (new JobRepository($pdo))->enqueue('vm.restore', $userId, (int) $backup['project_id'], (int) $backup['connection_id'], $payload, $reservationKey, $jobVmId > 0 ? $jobVmId : null, 4);
        });
    }

    /** @return array<string,mixed> */
    private function networkAndStorage(PDO $pdo, array $vm): array
    {
        if (empty($vm['network_id']) || empty($vm['storage_id'])) {
            throw new HttpException(422, 'VM has no managed network or storage assignment.');
        }
        $statement = $pdo->prepare('SELECT n.bridge,n.vlan_id,n.subnet,n.gateway,n.dns_servers,n.node_name AS network_node,s.storage_name,s.node_name AS storage_node FROM networks n JOIN storages s ON s.id=:storage WHERE n.id=:network AND n.connection_id=s.connection_id');
        $statement->execute(['network' => $vm['network_id'], 'storage' => $vm['storage_id']]);
        $result = $statement->fetch();
        if (!is_array($result)) {
            throw new HttpException(422, 'Managed network or storage no longer exists.');
        }
        return $result;
    }

    private function requiredNode(array $resources): ?string
    {
        $network = trim((string) ($resources['network_node'] ?? ''));
        $storage = trim((string) ($resources['storage_node'] ?? ''));
        if ($network !== '' && $storage !== '' && $network !== $storage) {
            throw new HttpException(422, 'Network and storage node scopes are incompatible.');
        }
        return $network !== '' ? $network : ($storage !== '' ? $storage : null);
    }

    private function assertIdle(PDO $pdo, int $vmId): void
    {
        $statement = $pdo->prepare("SELECT 1 FROM jobs WHERE virtual_machine_id=:vm AND status IN ('queued','running') LIMIT 1");
        $statement->execute(['vm' => $vmId]);
        if ($statement->fetchColumn()) {
            throw new HttpException(409, 'Another operation is already queued or running for this VM.');
        }
    }

    private function assertParallelQuota(PDO $pdo, array $vm): void
    {
        foreach ([['project_id', (int) $vm['project_id']], ['user_id', (int) $vm['owner_user_id']]] as [$column, $id]) {
            $quota = $pdo->prepare("SELECT max_parallel_jobs FROM quotas WHERE {$column}=:id FOR UPDATE");
            $quota->execute(['id' => $id]);
            $max = $quota->fetchColumn();
            if ($max === false || (int) $max === 0) {
                continue;
            }
            $jobColumn = $column === 'project_id' ? 'project_id' : 'user_id';
            $count = $pdo->prepare("SELECT COUNT(*) FROM jobs WHERE {$jobColumn}=:id AND status IN ('queued','running')");
            $count->execute(['id' => $id]);
            if ((int) $count->fetchColumn() >= (int) $max) {
                throw new HttpException(409, 'Parallel job quota exceeded.');
            }
        }
    }

    private function assertTemplateLimit(PDO $pdo, array $vm): void
    {
        if (empty($vm['template_id'])) {
            return;
        }
        foreach ([['project_id', (int) $vm['project_id'], 'project_id'], ['user_id', (int) $vm['owner_user_id'], 'owner_user_id']] as [$column, $id, $vmColumn]) {
            $limit = $pdo->prepare("SELECT max_vms FROM quota_template_limits WHERE {$column}=:id AND template_id=:template FOR UPDATE");
            $limit->execute(['id' => $id, 'template' => $vm['template_id']]);
            $max = $limit->fetchColumn();
            if ($max === false) {
                continue;
            }
            $count = $pdo->prepare("SELECT COUNT(*) FROM virtual_machines WHERE {$vmColumn}=:id AND template_id=:template AND status<>'deleted'");
            $count->execute(['id' => $id, 'template' => $vm['template_id']]);
            if ((int) $count->fetchColumn() >= (int) $max) {
                throw new HttpException(409, 'Per-template VM quota exceeded.');
            }
        }
    }

    private function assertBackupQuota(PDO $pdo, array $vm): void
    {
        foreach ([['project_id', (int) $vm['project_id'], 'project_id'], ['user_id', (int) $vm['owner_user_id'], 'owner_user_id']] as [$column, $id, $vmColumn]) {
            $quota = $pdo->prepare("SELECT max_backups,max_backup_storage_gb FROM quotas WHERE {$column}=:id FOR UPDATE");
            $quota->execute(['id' => $id]);
            $limits = $quota->fetch();
            if (!is_array($limits)) {
                continue;
            }
            $usage = $pdo->prepare("SELECT COUNT(*) AS backups,COALESCE(SUM(b.size_bytes),0) AS bytes FROM backups b JOIN virtual_machines vm ON vm.id=b.virtual_machine_id WHERE vm.{$vmColumn}=:id AND b.status IN ('queued','creating','ready','restoring')");
            $usage->execute(['id' => $id]);
            $used = $usage->fetch() ?: [];
            if ((int) $limits['max_backups'] > 0 && (int) ($used['backups'] ?? 0) >= (int) $limits['max_backups']) {
                throw new HttpException(409, 'Backup count quota exceeded.');
            }
            if ((int) $limits['max_backup_storage_gb'] > 0 && ((int) ($used['bytes'] ?? 0) / 1073741824) >= (int) $limits['max_backup_storage_gb']) {
                throw new HttpException(409, 'Backup storage quota exceeded.');
            }
        }
    }

    private function extraDiskGb(PDO $pdo, int $vmId): int
    {
        $statement = $pdo->prepare('SELECT COALESCE(SUM(size_gb),0) FROM vm_disks WHERE virtual_machine_id=:vm');
        $statement->execute(['vm' => $vmId]);
        return (int) $statement->fetchColumn();
    }

    private function prefixFromSubnet(string $subnet): int
    {
        $parts = explode('/', $subnet, 2);
        $max = isset($parts[0]) && str_contains($parts[0], ':') ? 128 : 32;
        if (count($parts) !== 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false || !ctype_digit($parts[1]) || (int) $parts[1] > $max) {
            throw new HttpException(500, 'Selected network has an invalid subnet.');
        }
        return (int) $parts[1];
    }
}
