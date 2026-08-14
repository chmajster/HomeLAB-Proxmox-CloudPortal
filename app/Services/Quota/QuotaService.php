<?php

declare(strict_types=1);

namespace CloudPortal\Services\Quota;

use PDO;

final class QuotaService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array{vms:int,vcpu:int,ram_mb:int,storage_gb:int,ip_addresses:int} $requested */
    public function reserve(string $key, int $projectId, int $userId, array $requested, int $ttlSeconds = 1800): void
    {
        $this->lockAndCheck('project_id', $projectId, $requested);
        $this->lockAndCheck('user_id', $userId, $requested);
        $statement = $this->pdo->prepare(
            'INSERT INTO quota_reservations
             (reservation_key, project_id, user_id, vms, vcpu, ram_mb, storage_gb, ip_addresses, expires_at)
             VALUES (:key, :project, :user, :vms, :vcpu, :ram, :storage, :ips, :expires)'
        );
        $statement->execute([
            'key' => $key,
            'project' => $projectId,
            'user' => $userId,
            'vms' => $requested['vms'],
            'vcpu' => $requested['vcpu'],
            'ram' => $requested['ram_mb'],
            'storage' => $requested['storage_gb'],
            'ips' => $requested['ip_addresses'],
            'expires' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
    }

    /** @param array{vms:int,vcpu:int,ram_mb:int,storage_gb:int,ip_addresses:int} $resources */
    public function assertAssignment(int $vmId, int $projectId, int $userId, array $resources): void
    {
        $this->lockAndCheck('project_id', $projectId, $resources, $vmId);
        $this->lockAndCheck('user_id', $userId, $resources, $vmId);
    }

    public function release(string $key): void
    {
        $this->pdo->prepare('DELETE FROM quota_reservations WHERE reservation_key = :key')->execute(['key' => $key]);
    }

    public function retainUntilReconciled(string $key): void
    {
        $this->pdo->prepare('UPDATE quota_reservations SET retain_until_reconciled = 1 WHERE reservation_key = :key')->execute(['key' => $key]);
    }

    public function cleanupExpired(): int
    {
        $statement = $this->pdo->query(
            "SELECT qr.reservation_key FROM quota_reservations qr
             WHERE qr.retain_until_reconciled = 0 AND qr.expires_at <= CURRENT_TIMESTAMP AND NOT EXISTS (
               SELECT 1 FROM jobs j WHERE j.reservation_key = qr.reservation_key AND j.status IN ('queued','running')
             ) FOR UPDATE"
        );
        $keys = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if ($keys === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $this->pdo->prepare(
            "UPDATE ip_addresses SET state = 'free', reservation_key = NULL, reserved_at = NULL
             WHERE state = 'reserved' AND reservation_key IN ({$placeholders})"
        )->execute($keys);
        $this->pdo->prepare("DELETE FROM quota_reservations WHERE reservation_key IN ({$placeholders})")->execute($keys);
        return count($keys);
    }

    /** @return array<string,int|null> */
    public function usage(int $projectId, ?int $userId = null): array
    {
        $where = $userId === null ? 'project_id = :subject' : 'owner_user_id = :subject';
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS vms, COALESCE(SUM(vcpu),0) AS vcpu, COALESCE(SUM(ram_mb),0) AS ram_mb,
                    COALESCE(SUM(disk_gb),0) AS storage_gb
             FROM virtual_machines WHERE {$where} AND status <> 'deleted'"
        );
        $statement->execute(['subject' => $userId ?? $projectId]);
        $usage = $statement->fetch() ?: [];
        $snapshots = $this->pdo->prepare(
            "SELECT COUNT(*) FROM snapshots s JOIN virtual_machines vm ON vm.id = s.virtual_machine_id
             WHERE vm.{$where} AND s.status <> 'deleting'"
        );
        $snapshots->execute(['subject' => $userId ?? $projectId]);
        return [
            'vms' => (int) ($usage['vms'] ?? 0),
            'vcpu' => (int) ($usage['vcpu'] ?? 0),
            'ram_mb' => (int) ($usage['ram_mb'] ?? 0),
            'storage_gb' => (int) ($usage['storage_gb'] ?? 0),
            'snapshots' => (int) $snapshots->fetchColumn(),
        ];
    }

    /** @param array<string,int> $requested */
    private function lockAndCheck(string $column, int $subjectId, array $requested, ?int $excludeVmId = null): void
    {
        if (!in_array($column, ['project_id', 'user_id'], true)) {
            throw new \LogicException('Invalid quota subject.');
        }
        $statement = $this->pdo->prepare("SELECT * FROM quotas WHERE {$column} = :id FOR UPDATE");
        $statement->execute(['id' => $subjectId]);
        $quota = $statement->fetch();
        if (!is_array($quota)) {
            throw new QuotaExceeded('quota_not_configured');
        }

        $vmColumn = $column === 'project_id' ? 'project_id' : 'owner_user_id';
        $exclude = $excludeVmId === null ? '' : ' AND id <> :exclude_vm';
        $usageStatement = $this->pdo->prepare(
            "SELECT COUNT(*) AS vms, COALESCE(SUM(vcpu),0) AS vcpu, COALESCE(SUM(ram_mb),0) AS ram_mb,
                    COALESCE(SUM(disk_gb),0) AS storage_gb
             FROM virtual_machines WHERE {$vmColumn} = :id AND status <> 'deleted'{$exclude}"
        );
        $usageParams = ['id' => $subjectId];
        if ($excludeVmId !== null) {
            $usageParams['exclude_vm'] = $excludeVmId;
        }
        $usageStatement->execute($usageParams);
        $usage = $usageStatement->fetch() ?: [];

        $reservationStatement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(vms),0) AS vms, COALESCE(SUM(vcpu),0) AS vcpu,
                    COALESCE(SUM(ram_mb),0) AS ram_mb, COALESCE(SUM(storage_gb),0) AS storage_gb,
                    COALESCE(SUM(ip_addresses),0) AS ip_addresses
             FROM quota_reservations qr WHERE qr.{$column} = :id AND
               (qr.retain_until_reconciled = 1 OR qr.expires_at > CURRENT_TIMESTAMP OR EXISTS (
                 SELECT 1 FROM jobs j WHERE j.reservation_key = qr.reservation_key AND j.status IN ('queued','running')
               ))"
        );
        $reservationStatement->execute(['id' => $subjectId]);
        $reserved = $reservationStatement->fetch() ?: [];

        $map = ['vms' => 'max_vms', 'vcpu' => 'max_vcpu', 'ram_mb' => 'max_ram_mb', 'storage_gb' => 'max_storage_gb'];
        foreach ($map as $resource => $limitColumn) {
            $total = (int) ($usage[$resource] ?? 0) + (int) ($reserved[$resource] ?? 0) + (int) ($requested[$resource] ?? 0);
            if ($total > (int) $quota[$limitColumn]) {
                throw new QuotaExceeded($resource);
            }
        }
        if ($quota['max_ip_addresses'] !== null) {
            $ipExclude = $excludeVmId === null ? '' : ' AND vm.id <> :exclude_vm';
            $ipStatement = $this->pdo->prepare(
                "SELECT COUNT(*) FROM ip_addresses ip JOIN virtual_machines vm ON vm.id = ip.virtual_machine_id
                 WHERE vm.{$vmColumn} = :id AND ip.state = 'allocated'{$ipExclude}"
            );
            $ipParams = ['id' => $subjectId];
            if ($excludeVmId !== null) {
                $ipParams['exclude_vm'] = $excludeVmId;
            }
            $ipStatement->execute($ipParams);
            $ips = (int) $ipStatement->fetchColumn() + (int) ($reserved['ip_addresses'] ?? 0) + (int) ($requested['ip_addresses'] ?? 0);
            if ($ips > (int) $quota['max_ip_addresses']) {
                throw new QuotaExceeded('ip_addresses');
            }
        }
    }
}
