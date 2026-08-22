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
    public function reserve(string $key, int $projectId, int $userId, array $requested, int $ttlSeconds = 1800, ?int $templateId = null): void
    {
        $this->assertParallelJobs($projectId, $userId);
        if ($templateId !== null) {
            $this->assertTemplateLimit('project_id', $projectId, 'project_id', $templateId);
            $this->assertTemplateLimit('user_id', $userId, 'owner_user_id', $templateId);
        }
        $this->lockAndCheck('project_id', $projectId, $requested);
        $this->lockAndCheck('user_id', $userId, $requested);
        $statement = $this->pdo->prepare(
            'INSERT INTO quota_reservations
             (reservation_key, project_id, user_id, vms, vcpu, ram_mb, storage_gb, ip_addresses, expires_at)
             VALUES (:key, :project, :user, :vms, :vcpu, :ram, :storage, :ips, :expires)'
        );
        $statement->execute([
            'key' => $key, 'project' => $projectId, 'user' => $userId, 'vms' => $requested['vms'],
            'vcpu' => $requested['vcpu'], 'ram' => $requested['ram_mb'], 'storage' => $requested['storage_gb'],
            'ips' => $requested['ip_addresses'], 'expires' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
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
        $this->pdo->prepare('DELETE FROM quota_reservations WHERE reservation_key=:key')->execute(['key' => $key]);
    }

    public function retainUntilReconciled(string $key): void
    {
        $this->pdo->prepare('UPDATE quota_reservations SET retain_until_reconciled=1 WHERE reservation_key=:key')->execute(['key' => $key]);
    }

    public function cleanupExpired(): int
    {
        $statement = $this->pdo->query(
            "SELECT qr.reservation_key FROM quota_reservations qr
             WHERE qr.retain_until_reconciled=0 AND qr.expires_at<=CURRENT_TIMESTAMP AND NOT EXISTS (
               SELECT 1 FROM jobs j WHERE j.reservation_key=qr.reservation_key AND j.status IN ('queued','running')
             ) FOR UPDATE"
        );
        $keys = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        if ($keys === []) return 0;
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $this->pdo->prepare("UPDATE ip_addresses SET state='free',reservation_key=NULL,reserved_at=NULL WHERE state='reserved' AND reservation_key IN ({$placeholders})")->execute($keys);
        $this->pdo->prepare("DELETE FROM quota_reservations WHERE reservation_key IN ({$placeholders})")->execute($keys);
        return count($keys);
    }

    /** @return array<string,int|null> */
    public function usage(int $projectId, ?int $userId = null): array
    {
        $vmColumn = $userId === null ? 'project_id' : 'owner_user_id';
        $subject = $userId ?? $projectId;
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) AS vms,COALESCE(SUM(vm.vcpu),0) AS vcpu,COALESCE(SUM(vm.ram_mb),0) AS ram_mb,
                    COALESCE(SUM(vm.disk_gb + COALESCE((SELECT SUM(d.size_gb) FROM vm_disks d WHERE d.virtual_machine_id=vm.id),0)),0) AS storage_gb
             FROM virtual_machines vm WHERE vm.{$vmColumn}=:subject AND vm.status<>'deleted'"
        );
        $statement->execute(['subject' => $subject]);
        $usage = $statement->fetch() ?: [];
        $snapshots = $this->pdo->prepare("SELECT COUNT(*) FROM snapshots s JOIN virtual_machines vm ON vm.id=s.virtual_machine_id WHERE vm.{$vmColumn}=:subject AND s.status<>'deleting'");
        $snapshots->execute(['subject' => $subject]);
        $ips = $this->pdo->prepare("SELECT COUNT(*) FROM ip_addresses ip JOIN virtual_machines vm ON vm.id=ip.virtual_machine_id WHERE vm.{$vmColumn}=:subject AND ip.state='allocated'");
        $ips->execute(['subject' => $subject]);
        $backups = $this->pdo->prepare("SELECT COUNT(*) AS backups,COALESCE(SUM(b.size_bytes),0) AS bytes FROM backups b JOIN virtual_machines vm ON vm.id=b.virtual_machine_id WHERE vm.{$vmColumn}=:subject AND b.status IN ('queued','creating','ready','restoring')");
        $backups->execute(['subject' => $subject]);
        $backupUsage = $backups->fetch() ?: [];
        $jobColumn = $userId === null ? 'project_id' : 'user_id';
        $jobs = $this->pdo->prepare("SELECT COUNT(*) FROM jobs WHERE {$jobColumn}=:subject AND status IN ('queued','running')");
        $jobs->execute(['subject' => $subject]);
        return [
            'vms' => (int) ($usage['vms'] ?? 0), 'vcpu' => (int) ($usage['vcpu'] ?? 0), 'ram_mb' => (int) ($usage['ram_mb'] ?? 0),
            'storage_gb' => (int) ($usage['storage_gb'] ?? 0), 'snapshots' => (int) $snapshots->fetchColumn(), 'ip_addresses' => (int) $ips->fetchColumn(),
            'backups' => (int) ($backupUsage['backups'] ?? 0), 'backup_storage_gb' => (int) ceil(((int) ($backupUsage['bytes'] ?? 0)) / 1073741824),
            'active_jobs' => (int) $jobs->fetchColumn(),
        ];
    }

    /** @param array<string,int> $requested */
    private function lockAndCheck(string $column, int $subjectId, array $requested, ?int $excludeVmId = null): void
    {
        if (!in_array($column, ['project_id','user_id'], true)) throw new \LogicException('Invalid quota subject.');
        $statement = $this->pdo->prepare("SELECT * FROM quotas WHERE {$column}=:id FOR UPDATE");
        $statement->execute(['id' => $subjectId]);
        $quota = $statement->fetch();
        if (!is_array($quota)) throw new QuotaExceeded('quota_not_configured');

        $vmColumn = $column === 'project_id' ? 'project_id' : 'owner_user_id';
        $exclude = $excludeVmId === null ? '' : ' AND vm.id<>:exclude_vm';
        $usageStatement = $this->pdo->prepare(
            "SELECT COUNT(*) AS vms,COALESCE(SUM(vm.vcpu),0) AS vcpu,COALESCE(SUM(vm.ram_mb),0) AS ram_mb,
                    COALESCE(SUM(vm.disk_gb + COALESCE((SELECT SUM(d.size_gb) FROM vm_disks d WHERE d.virtual_machine_id=vm.id),0)),0) AS storage_gb
             FROM virtual_machines vm WHERE vm.{$vmColumn}=:id AND vm.status<>'deleted'{$exclude}"
        );
        $usageParams = ['id' => $subjectId];
        if ($excludeVmId !== null) $usageParams['exclude_vm'] = $excludeVmId;
        $usageStatement->execute($usageParams);
        $usage = $usageStatement->fetch() ?: [];

        $reservationStatement = $this->pdo->prepare(
            "SELECT COALESCE(SUM(vms),0) AS vms,COALESCE(SUM(vcpu),0) AS vcpu,COALESCE(SUM(ram_mb),0) AS ram_mb,
                    COALESCE(SUM(storage_gb),0) AS storage_gb,COALESCE(SUM(ip_addresses),0) AS ip_addresses
             FROM quota_reservations qr WHERE qr.{$column}=:id AND
               (qr.retain_until_reconciled=1 OR qr.expires_at>CURRENT_TIMESTAMP OR EXISTS (
                 SELECT 1 FROM jobs j WHERE j.reservation_key=qr.reservation_key AND j.status IN ('queued','running')
               ))"
        );
        $reservationStatement->execute(['id' => $subjectId]);
        $reserved = $reservationStatement->fetch() ?: [];

        $map = ['vms' => 'max_vms', 'vcpu' => 'max_vcpu', 'ram_mb' => 'max_ram_mb', 'storage_gb' => 'max_storage_gb'];
        foreach ($map as $resource => $limitColumn) {
            $total = (int) ($usage[$resource] ?? 0) + (int) ($reserved[$resource] ?? 0) + (int) ($requested[$resource] ?? 0);
            if ($total > (int) $quota[$limitColumn]) throw new QuotaExceeded($resource);
        }
        if ($quota['max_ip_addresses'] !== null) {
            $ipExclude = $excludeVmId === null ? '' : ' AND vm.id<>:exclude_vm';
            $ipStatement = $this->pdo->prepare("SELECT COUNT(*) FROM ip_addresses ip JOIN virtual_machines vm ON vm.id=ip.virtual_machine_id WHERE vm.{$vmColumn}=:id AND ip.state='allocated'{$ipExclude}");
            $ipParams = ['id' => $subjectId];
            if ($excludeVmId !== null) $ipParams['exclude_vm'] = $excludeVmId;
            $ipStatement->execute($ipParams);
            $ips = (int) $ipStatement->fetchColumn() + (int) ($reserved['ip_addresses'] ?? 0) + (int) ($requested['ip_addresses'] ?? 0);
            if ($ips > (int) $quota['max_ip_addresses']) throw new QuotaExceeded('ip_addresses');
        }
    }

    private function assertParallelJobs(int $projectId, int $userId): void
    {
        foreach ([['project_id',$projectId,'project_id'],['user_id',$userId,'user_id']] as [$quotaColumn,$id,$jobColumn]) {
            $statement = $this->pdo->prepare("SELECT max_parallel_jobs FROM quotas WHERE {$quotaColumn}=:id FOR UPDATE");
            $statement->execute(['id' => $id]);
            $max = $statement->fetchColumn();
            if ($max === false || (int) $max === 0) continue;
            $count = $this->pdo->prepare("SELECT COUNT(*) FROM jobs WHERE {$jobColumn}=:id AND status IN ('queued','running')");
            $count->execute(['id' => $id]);
            if ((int) $count->fetchColumn() >= (int) $max) throw new QuotaExceeded('parallel_jobs');
        }
    }

    private function assertTemplateLimit(string $quotaColumn, int $subjectId, string $vmColumn, int $templateId): void
    {
        $statement = $this->pdo->prepare("SELECT max_vms FROM quota_template_limits WHERE {$quotaColumn}=:id AND template_id=:template FOR UPDATE");
        $statement->execute(['id' => $subjectId, 'template' => $templateId]);
        $max = $statement->fetchColumn();
        if ($max === false) return;
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM virtual_machines WHERE {$vmColumn}=:id AND template_id=:template AND status<>'deleted'");
        $count->execute(['id' => $subjectId, 'template' => $templateId]);
        if ((int) $count->fetchColumn() >= (int) $max) throw new QuotaExceeded('template_vms');
    }
}
