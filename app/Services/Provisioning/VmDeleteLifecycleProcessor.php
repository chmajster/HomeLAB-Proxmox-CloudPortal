<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\DNS\DnsApiClientInterface;
use CloudPortal\Services\IPAM\IPAMService;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxException;
use PDO;
use Throwable;

/**
 * Idempotent delete workflow for portal-managed VMs.
 *
 * Ordering is deliberate and fail-closed:
 *   1. prove that the remote Proxmox VM is absent,
 *   2. remove only DNS records that were created by managed provisioning,
 *   3. release IPAM and mark the local VM deleted in one DB transaction,
 *   4. complete the durable job.
 *
 * If any step cannot be proven successful, IPAM is retained and the job is
 * retried. This prevents address reuse while a VM may still exist remotely.
 */
final class VmDeleteLifecycleProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly ProxmoxClientProviderInterface $clients,
        private readonly JobRepository $jobs,
        private readonly AuditLogger $audit,
        private readonly ?DnsApiClientInterface $dns = null,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'vm.delete';
    }

    /** @param array<string,mixed> $job */
    public function process(array $job): void
    {
        if (!$this->supports((string) ($job['type'] ?? ''))) {
            throw new \InvalidArgumentException('VmDeleteLifecycleProcessor only supports vm.delete jobs.');
        }

        $jobId = (int) ($job['id'] ?? 0);
        $vmId = (int) ($job['virtual_machine_id'] ?? 0);
        if ($jobId <= 0 || $vmId <= 0) {
            if ($jobId > 0) {
                $this->jobs->failPermanent($jobId, 'Delete job does not reference a valid virtual machine.');
            }
            return;
        }

        try {
            $vm = $this->findVm($vmId);
            if ($vm === null) {
                $this->jobs->complete($jobId, [
                    'virtual_machine_id' => $vmId,
                    'status' => 'deleted',
                    'idempotent' => true,
                ]);
                return;
            }

            $this->database->pdo()->prepare(
                'UPDATE virtual_machines SET delete_requested_at=COALESCE(delete_requested_at,CURRENT_TIMESTAMP),deleted_by=COALESCE(deleted_by,:user) WHERE id=:id'
            )->execute([
                'user' => $job['user_id'] === null ? null : (int) $job['user_id'],
                'id' => $vmId,
            ]);

            if ((string) $vm['status'] !== 'deleted') {
                $this->ensureRemoteVmAbsent($jobId, $vm);
            } else {
                $this->markRemoteDeleted($vmId);
            }

            $this->cleanupManagedDns($vmId);
            $this->database->pdo()->prepare('UPDATE virtual_machines SET dns_released_at=COALESCE(dns_released_at,CURRENT_TIMESTAMP) WHERE id=:id')
                ->execute(['id' => $vmId]);

            $this->database->transaction(function (PDO $pdo) use ($vmId): void {
                (new IPAMService($pdo))->releaseVm($vmId);
                $pdo->prepare(
                    "UPDATE virtual_machines
                     SET status='deleted', ip_released_at=COALESCE(ip_released_at,CURRENT_TIMESTAMP),
                         deleted_at=COALESCE(deleted_at,CURRENT_TIMESTAMP), last_error=NULL
                     WHERE id=:id"
                )->execute(['id' => $vmId]);
            });

            $result = [
                'virtual_machine_id' => $vmId,
                'status' => 'deleted',
                'remote_absence_verified' => true,
                'dns_cleanup_verified' => true,
                'ipam_release_verified' => true,
            ];
            $this->jobs->complete($jobId, $result);
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.delete',
                'success',
                'virtual_machine',
                (string) $vmId,
                [...$result, 'job_id' => $jobId],
            );
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            try {
                $this->database->pdo()->prepare(
                    "UPDATE virtual_machines SET status='error', last_error=:error
                     WHERE id=:id AND status<>'deleted'"
                )->execute(['error' => $message, 'id' => $vmId]);
            } catch (Throwable) {
            }
            $this->jobs->fail($jobId, $message);
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.delete',
                'failure',
                'virtual_machine',
                (string) $vmId,
                ['error' => $message, 'resources_retained' => true, 'job_id' => $jobId],
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function findVm(int $vmId): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT * FROM virtual_machines WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $vmId]);
        $vm = $statement->fetch();
        return is_array($vm) ? $vm : null;
    }

    /** @param array<string,mixed> $vm */
    private function ensureRemoteVmAbsent(int $jobId, array $vm): void
    {
        $client = $this->clients->forConnection((int) $vm['connection_id']);
        $path = '/nodes/' . rawurlencode((string) $vm['node_name']) . '/qemu/' . (int) $vm['vmid'];

        try {
            $current = $client->get($path . '/status/current');
        } catch (ProxmoxException $exception) {
            if ($exception->httpStatus === 404) {
                $this->markRemoteDeleted((int) $vm['id']);
                return;
            }
            throw $exception;
        }

        if (is_array($current) && (string) ($current['status'] ?? '') === 'running') {
            $stopUpid = $this->requireUpid($client->post($path . '/status/stop'));
            $this->jobs->upid($jobId, $stopUpid);
            $client->waitForTask((string) $vm['node_name'], $stopUpid, 300);
        }

        $deleteUpid = $this->requireUpid($client->delete($path, [
            'purge' => 1,
            'destroy-unreferenced-disks' => 1,
        ]));
        $this->jobs->upid($jobId, $deleteUpid);
        $client->waitForTask((string) $vm['node_name'], $deleteUpid, 900);

        if (!$this->remoteVmIsAbsent($client, $path)) {
            throw new \RuntimeException(
                'Proxmox delete task completed but VM absence could not be verified; DNS and IPAM were retained.'
            );
        }
        $this->markRemoteDeleted((int) $vm['id']);
    }

    private function markRemoteDeleted(int $vmId): void
    {
        $this->database->pdo()->prepare('UPDATE virtual_machines SET proxmox_deleted_at=COALESCE(proxmox_deleted_at,CURRENT_TIMESTAMP) WHERE id=:id')
            ->execute(['id' => $vmId]);
    }

    private function remoteVmIsAbsent(ProxmoxClientInterface $client, string $path): bool
    {
        try {
            $client->get($path . '/status/current');
            return false;
        } catch (ProxmoxException $exception) {
            if ($exception->httpStatus === 404) {
                return true;
            }
            throw $exception;
        }
    }

    private function cleanupManagedDns(int $vmId): void
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT vp.id, vp.forward_zone, vp.reverse_zone, vp.a_record_id, vp.ptr_record_id
             FROM vm_provisioning vp
             JOIN jobs j ON j.id=vp.job_id
             WHERE COALESCE(vp.virtual_machine_id,j.virtual_machine_id)=:vm
             ORDER BY vp.id DESC LIMIT 1'
        );
        $statement->execute(['vm' => $vmId]);
        $state = $statement->fetch();
        if (!is_array($state)) {
            return;
        }

        $ptrId = (int) ($state['ptr_record_id'] ?? 0);
        $aId = (int) ($state['a_record_id'] ?? 0);
        if ($ptrId <= 0 && $aId <= 0) {
            return;
        }
        if (!$this->dns instanceof DnsApiClientInterface) {
            throw new \RuntimeException(
                'Managed DNS cleanup is required but the DNS client is unavailable; IPAM was retained.'
            );
        }

        if ($ptrId > 0) {
            $this->dns->deleteRecord((string) $state['reverse_zone'], $ptrId);
            $this->database->pdo()->prepare('UPDATE vm_provisioning SET ptr_record_id=NULL WHERE id=:id')
                ->execute(['id' => $state['id']]);
        }
        if ($aId > 0) {
            $this->dns->deleteRecord((string) $state['forward_zone'], $aId);
            $this->database->pdo()->prepare('UPDATE vm_provisioning SET a_record_id=NULL WHERE id=:id')
                ->execute(['id' => $state['id']]);
        }
    }

    private function requireUpid(mixed $value): string
    {
        if (!is_string($value) || !str_starts_with($value, 'UPID:')) {
            throw new \RuntimeException('Proxmox did not return a valid task UPID.');
        }
        return $value;
    }
}
