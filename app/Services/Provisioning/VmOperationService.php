<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use CloudPortal\Services\Quota\QuotaExceeded;
use CloudPortal\Services\Quota\QuotaService;
use PDO;

final class VmOperationService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string,mixed> */
    public function accessibleVm(int $vmId, int $userId, bool $isAdmin, bool $forUpdate = false): array
    {
        $sql = "SELECT vm.*, ip.address AS ip_address, p.name AS project_name, u.username AS owner_name
                FROM virtual_machines vm
                JOIN projects p ON p.id = vm.project_id
                JOIN users u ON u.id = vm.owner_user_id
                LEFT JOIN ip_addresses ip ON ip.virtual_machine_id = vm.id
                WHERE vm.id = :id AND vm.status <> 'deleted'";
        if (!$isAdmin) {
            $sql .= ' AND vm.owner_user_id = :user AND EXISTS (
                SELECT 1 FROM project_users pu WHERE pu.project_id = vm.project_id AND pu.user_id = :member
            )';
        }
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->database->pdo()->prepare($sql);
        $params = ['id' => $vmId];
        if (!$isAdmin) {
            $params['user'] = $userId;
            $params['member'] = $userId;
        }
        $statement->execute($params);
        $vm = $statement->fetch();
        if (!is_array($vm)) {
            throw new HttpException(404, 'Virtual machine not found.');
        }
        return $vm;
    }

    public function power(int $vmId, int $userId, bool $isAdmin, string $action): string
    {
        if (!in_array($action, ['start', 'shutdown', 'stop', 'reboot', 'suspend', 'resume'], true)) {
            throw new HttpException(422, 'Unsupported power action.');
        }
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $action): string {
            $vm = $this->accessibleVm($vmId, $userId, $isAdmin, true);
            if ($this->hasActiveJob($pdo, $vmId)) {
                throw new HttpException(409, 'Another operation is already running for this VM.');
            }
            return (new JobRepository($pdo))->enqueue('vm.' . $action, $userId, (int) $vm['project_id'], (int) $vm['connection_id'], [], null, $vmId);
        });
    }

    public function delete(int $vmId, int $userId, bool $isAdmin): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin): string {
            $vm = $this->accessibleVm($vmId, $userId, $isAdmin, true);
            if ($this->hasActiveJob($pdo, $vmId)) {
                throw new HttpException(409, 'Another operation is already running for this VM.');
            }
            $pdo->prepare("UPDATE virtual_machines SET status = 'deleting' WHERE id = :id")->execute(['id' => $vmId]);
            return (new JobRepository($pdo))->enqueue('vm.delete', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], [], null, $vmId);
        });
    }

    public function snapshot(int $vmId, int $userId, bool $isAdmin, string $name, string $description = ''): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,39}$/', $name) !== 1) {
            throw new HttpException(422, 'Invalid snapshot name.');
        }
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $name, $description): string {
            $vm = $this->accessibleVm($vmId, $userId, $isAdmin, true);
            if ($this->hasActiveJob($pdo, $vmId)) {
                throw new HttpException(409, 'Another operation is already running for this VM.');
            }
            $this->assertSnapshotQuota($pdo, 'project_id', (int) $vm['project_id']);
            $this->assertSnapshotQuota($pdo, 'user_id', (int) $vm['owner_user_id']);
            $insert = $pdo->prepare('INSERT INTO snapshots (virtual_machine_id, name, description, created_by) VALUES (:vm, :name, :description, :user)');
            $insert->execute(['vm' => $vmId, 'name' => $name, 'description' => mb_substr($description, 0, 255), 'user' => $userId]);
            $snapshotId = (int) $pdo->lastInsertId();
            return (new JobRepository($pdo))->enqueue('vm.snapshot.create', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], [
                'snapshot_id' => $snapshotId, 'name' => $name, 'description' => mb_substr($description, 0, 255),
            ], null, $vmId);
        });
    }

    public function deleteSnapshot(int $vmId, int $snapshotId, int $userId, bool $isAdmin): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $snapshotId, $userId, $isAdmin): string {
            $vm = $this->accessibleVm($vmId, $userId, $isAdmin, true);
            if ($this->hasActiveJob($pdo, $vmId)) {
                throw new HttpException(409, 'Another operation is already running for this VM.');
            }
            $statement = $pdo->prepare("SELECT id, name FROM snapshots WHERE id = :id AND virtual_machine_id = :vm AND status IN ('ready','error') FOR UPDATE");
            $statement->execute(['id' => $snapshotId, 'vm' => $vmId]);
            $snapshot = $statement->fetch();
            if (!is_array($snapshot)) {
                throw new HttpException(404, 'Snapshot not found.');
            }
            $pdo->prepare("UPDATE snapshots SET status = 'deleting' WHERE id = :id")->execute(['id' => $snapshotId]);
            return (new JobRepository($pdo))->enqueue('vm.snapshot.delete', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], [
                'snapshot_id' => $snapshotId, 'name' => (string) $snapshot['name'],
            ], null, $vmId);
        });
    }

    public function resize(int $vmId, int $userId, bool $isAdmin, int $planId): string
    {
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $planId): string {
            $vm = $this->accessibleVm($vmId, $userId, $isAdmin, true);
            if ($this->hasActiveJob($pdo, $vmId)) {
                throw new HttpException(409, 'Another operation is already running for this VM.');
            }
            $planStatement = $pdo->prepare('SELECT * FROM resource_plans WHERE id = :id AND enabled = 1 AND allow_resize = 1');
            $planStatement->execute(['id' => $planId]);
            $plan = $planStatement->fetch();
            if (!is_array($plan) || (int) $plan['vcpu'] < (int) $vm['vcpu'] || (int) $plan['ram_mb'] < (int) $vm['ram_mb'] || (int) $plan['disk_gb'] < (int) $vm['disk_gb']) {
                throw new HttpException(422, 'Resize requires an enabled plan that does not shrink resources.');
            }
            $delta = [
                'vms' => 0,
                'vcpu' => (int) $plan['vcpu'] - (int) $vm['vcpu'],
                'ram_mb' => (int) $plan['ram_mb'] - (int) $vm['ram_mb'],
                'storage_gb' => (int) $plan['disk_gb'] - (int) $vm['disk_gb'],
                'ip_addresses' => 0,
            ];
            $key = \CloudPortal\Support\Uuid::v4();
            try {
                (new QuotaService($pdo))->reserve($key, (int) $vm['project_id'], (int) $vm['owner_user_id'], $delta);
            } catch (QuotaExceeded $exception) {
                throw new HttpException(409, $exception->getMessage());
            }
            return (new JobRepository($pdo))->enqueue('vm.resize', $userId, (int) $vm['project_id'], (int) $vm['connection_id'], [
                'plan_id' => (int) $plan['id'], 'vcpu' => (int) $plan['vcpu'], 'ram_mb' => (int) $plan['ram_mb'], 'disk_gb' => (int) $plan['disk_gb'],
            ], $key, $vmId);
        });
    }

    public function assign(int $vmId, int $projectId, int $ownerUserId): void
    {
        $this->database->transaction(function (PDO $pdo) use ($vmId, $projectId, $ownerUserId): void {
            $vm = $this->accessibleVm($vmId, 0, true, true);
            if ($this->hasActiveJob($pdo, $vmId)) {
                throw new HttpException(409, 'A VM with an active job cannot be reassigned.');
            }
            $access = $pdo->prepare(
                "SELECT 1 FROM project_users pu JOIN projects p ON p.id = pu.project_id
                 JOIN users u ON u.id = pu.user_id
                 WHERE pu.project_id = :project AND pu.user_id = :user AND p.status = 'active' AND u.status = 'active'
                 AND EXISTS (SELECT 1 FROM project_networks pn WHERE pn.project_id = pu.project_id AND pn.network_id = :network)
                 AND EXISTS (SELECT 1 FROM project_storages ps WHERE ps.project_id = pu.project_id AND ps.storage_id = :storage)"
            );
            $access->execute(['project' => $projectId, 'user' => $ownerUserId, 'network' => $vm['network_id'], 'storage' => $vm['storage_id']]);
            if (!$access->fetchColumn()) {
                throw new HttpException(422, 'Target user/project lacks membership, network or storage access.');
            }
            try {
                (new QuotaService($pdo))->assertAssignment($vmId, $projectId, $ownerUserId, [
                    'vms' => 1, 'vcpu' => (int) $vm['vcpu'], 'ram_mb' => (int) $vm['ram_mb'],
                    'storage_gb' => (int) $vm['disk_gb'], 'ip_addresses' => $vm['ip_address'] === null ? 0 : 1,
                ]);
            } catch (QuotaExceeded $exception) {
                throw new HttpException(409, $exception->getMessage(), ['resource' => $exception->resource]);
            }
            $snapshotStatement = $pdo->prepare("SELECT COUNT(*) FROM snapshots WHERE virtual_machine_id = :vm AND status <> 'deleting'");
            $snapshotStatement->execute(['vm' => $vmId]);
            $vmSnapshots = (int) $snapshotStatement->fetchColumn();
            foreach ([['project_id', $projectId, 'project_id'], ['user_id', $ownerUserId, 'owner_user_id']] as [$quotaColumn, $subjectId, $vmColumn]) {
                $quotaStatement = $pdo->prepare("SELECT max_snapshots FROM quotas WHERE {$quotaColumn} = :id FOR UPDATE");
                $quotaStatement->execute(['id' => $subjectId]);
                $max = $quotaStatement->fetchColumn();
                $usedStatement = $pdo->prepare("SELECT COUNT(*) FROM snapshots s JOIN virtual_machines v ON v.id=s.virtual_machine_id WHERE v.{$vmColumn}=:id AND v.id<>:vm AND s.status<>'deleting'");
                $usedStatement->execute(['id' => $subjectId, 'vm' => $vmId]);
                if ($max === false || (int) $usedStatement->fetchColumn() + $vmSnapshots > (int) $max) {
                    throw new HttpException(409, 'Target snapshot quota would be exceeded.');
                }
            }
            $pdo->prepare('UPDATE virtual_machines SET project_id = :project, owner_user_id = :owner WHERE id = :id')
                ->execute(['project' => $projectId, 'owner' => $ownerUserId, 'id' => $vmId]);
        });
    }

    private function hasActiveJob(PDO $pdo, int $vmId): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM jobs WHERE virtual_machine_id = :vm AND status IN ('queued','running') LIMIT 1");
        $statement->execute(['vm' => $vmId]);
        return (bool) $statement->fetchColumn();
    }

    private function assertSnapshotQuota(PDO $pdo, string $column, int $subjectId): void
    {
        if (!in_array($column, ['project_id', 'user_id'], true)) {
            throw new \LogicException('Invalid quota subject.');
        }
        $quota = $pdo->prepare("SELECT max_snapshots FROM quotas WHERE {$column} = :id FOR UPDATE");
        $quota->execute(['id' => $subjectId]);
        $max = $quota->fetchColumn();
        $vmColumn = $column === 'project_id' ? 'project_id' : 'owner_user_id';
        $count = $pdo->prepare("SELECT COUNT(*) FROM snapshots s JOIN virtual_machines v ON v.id = s.virtual_machine_id WHERE v.{$vmColumn} = :id AND s.status <> 'deleting'");
        $count->execute(['id' => $subjectId]);
        if ($max === false || (int) $count->fetchColumn() >= (int) $max) {
            throw new HttpException(409, ucfirst(str_replace('_', ' ', $column)) . ' snapshot quota exceeded.');
        }
    }
}
