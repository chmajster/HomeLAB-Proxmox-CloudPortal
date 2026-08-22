<?php

declare(strict_types=1);

namespace CloudPortal\Services\Backup;

use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use CloudPortal\Services\Provisioning\VmOperationService;
use PDO;

final class BackupPolicyService
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string,mixed> */
    public function assertCreateAllowed(int $vmId, int $userId, bool $isAdmin, string $storage): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $storage): array {
            $vm = (new VmOperationService($this->database))->accessibleVm($vmId, $userId, $isAdmin, true);
            $storageStatement = $pdo->prepare("SELECT s.id,s.storage_name,s.content_types,s.node_name FROM storages s JOIN project_storages ps ON ps.storage_id=s.id WHERE ps.project_id=:project AND s.connection_id=:connection AND s.storage_name=:storage AND s.enabled=1 AND (s.node_name IS NULL OR s.node_name=:node) LIMIT 1");
            $storageStatement->execute(['project' => $vm['project_id'], 'connection' => $vm['connection_id'], 'storage' => $storage, 'node' => $vm['node_name']]);
            $target = $storageStatement->fetch();
            if (!is_array($target)) {
                throw new HttpException(422, 'Backup storage is unavailable to this VM project/node.');
            }
            $types = array_filter(array_map('trim', explode(',', strtolower((string) $target['content_types']))));
            if (!in_array('backup', $types, true)) {
                throw new HttpException(422, 'Selected storage is not configured for Proxmox backup content.');
            }
            $extra = $pdo->prepare('SELECT COALESCE(SUM(size_gb),0) FROM vm_disks WHERE virtual_machine_id=:vm');
            $extra->execute(['vm' => $vmId]);
            $estimateGb = (int) $vm['disk_gb'] + (int) $extra->fetchColumn();
            $this->assertSubjectQuota($pdo, 'project_id', (int) $vm['project_id'], 'project_id', $estimateGb);
            $this->assertSubjectQuota($pdo, 'user_id', (int) $vm['owner_user_id'], 'owner_user_id', $estimateGb);
            return $vm;
        });
    }

    private function assertSubjectQuota(PDO $pdo, string $quotaColumn, int $subjectId, string $vmColumn, int $estimateGb): void
    {
        $quota = $pdo->prepare("SELECT max_backups,max_backup_storage_gb FROM quotas WHERE {$quotaColumn}=:id FOR UPDATE");
        $quota->execute(['id' => $subjectId]);
        $limits = $quota->fetch();
        if (!is_array($limits)) return;
        $usage = $pdo->prepare("SELECT COUNT(*) AS backup_count,COALESCE(SUM(b.size_bytes),0) AS bytes FROM backups b JOIN virtual_machines vm ON vm.id=b.virtual_machine_id WHERE vm.{$vmColumn}=:id AND b.status IN ('queued','creating','ready','restoring')");
        $usage->execute(['id' => $subjectId]);
        $used = $usage->fetch() ?: [];
        if ((int) $limits['max_backups'] > 0 && (int) ($used['backup_count'] ?? 0) >= (int) $limits['max_backups']) {
            throw new HttpException(409, 'Backup count quota exceeded.');
        }
        if ((int) $limits['max_backup_storage_gb'] > 0) {
            $usedGb = (int) ceil(((int) ($used['bytes'] ?? 0)) / 1073741824);
            if ($usedGb + $estimateGb > (int) $limits['max_backup_storage_gb']) {
                throw new HttpException(409, 'Backup storage quota would be exceeded.');
            }
        }
    }
}
