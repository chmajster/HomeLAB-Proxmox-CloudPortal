<?php

declare(strict_types=1);

namespace CloudPortal\Services\Reconciliation;

use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Proxmox\ProxmoxException;
use PDO;

final class ReconciliationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ProxmoxClientProviderInterface $clients,
    ) {
    }

    /** @return array{detected:int,proxmox_connections_checked:int,proxmox_connections_failed:int} */
    public function scan(): array
    {
        $detected = 0;
        $checked = 0;
        $failed = 0;

        foreach ($this->pdo->query("SELECT id,public_id,virtual_machine_id FROM jobs WHERE status='running' AND started_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 2 HOUR) LIMIT 200")->fetchAll() as $job) {
            $this->upsert(
                'stale_job:' . $job['public_id'], 'stale_running_job', 'critical',
                $job['virtual_machine_id'] === null ? null : (int) $job['virtual_machine_id'], (int) $job['id'],
                ['job_public_id' => $job['public_id'], 'reason' => 'Job has remained running for more than two hours.'],
            );
            $detected++;
        }

        $retained = $this->pdo->query(
            "SELECT j.id,j.public_id,j.virtual_machine_id,j.reservation_key
             FROM jobs j JOIN quota_reservations qr ON qr.reservation_key=j.reservation_key
             WHERE j.status IN ('failed','dead_letter') AND qr.retain_until_reconciled=1 LIMIT 200"
        )->fetchAll();
        foreach ($retained as $job) {
            $this->upsert(
                'retained_create:' . $job['public_id'], 'failed_create_retained_resources', 'critical',
                $job['virtual_machine_id'] === null ? null : (int) $job['virtual_machine_id'], (int) $job['id'],
                ['job_public_id' => $job['public_id'], 'reservation_key' => $job['reservation_key'], 'automatic_cleanup' => false],
            );
            $detected++;
        }

        $ipRows = $this->pdo->query(
            "SELECT ip.id,ip.address,ip.state,ip.virtual_machine_id,vm.status AS vm_status,vm.deleted_at
             FROM ip_addresses ip LEFT JOIN virtual_machines vm ON vm.id=ip.virtual_machine_id
             WHERE ip.state IN ('reserved','allocated')
               AND ((ip.virtual_machine_id IS NULL AND ip.state='allocated') OR vm.status='deleted' OR vm.deleted_at IS NOT NULL)
             LIMIT 500"
        )->fetchAll();
        foreach ($ipRows as $ip) {
            $type = $ip['virtual_machine_id'] === null ? 'allocated_ip_without_vm' : 'deleted_vm_ip_retained';
            $this->upsert(
                'ip:' . $ip['id'] . ':' . $type, $type, 'critical',
                $ip['virtual_machine_id'] === null ? null : (int) $ip['virtual_machine_id'], null,
                ['ip_id' => (int) $ip['id'], 'address' => $ip['address'], 'state' => $ip['state'], 'automatic_cleanup' => false],
            );
            $detected++;
        }

        $connections = $this->pdo->query("SELECT id FROM proxmox_connections WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($connections as $connectionIdRaw) {
            $connectionId = (int) $connectionIdRaw;
            try {
                $client = $this->clients->forConnection($connectionId);
                $resources = $client->get('/cluster/resources', ['type' => 'vm']);
                $checked++;
                if (!is_array($resources)) continue;
                foreach ($resources as $resource) {
                    if (!is_array($resource) || (string) ($resource['type'] ?? '') !== 'qemu') continue;
                    $vmid = (int) ($resource['vmid'] ?? 0);
                    if ($vmid <= 0) continue;
                    $known = $this->pdo->prepare(
                        'SELECT id FROM virtual_machines WHERE connection_id=:connection AND vmid=:vmid AND deleted_at IS NULL LIMIT 1'
                    );
                    $known->execute(['connection' => $connectionId, 'vmid' => $vmid]);
                    if (!$known->fetchColumn()) {
                        $this->upsert(
                            'untracked:' . $connectionId . ':' . $vmid, 'untracked_proxmox_vm', 'info', null, null,
                            ['connection_id' => $connectionId, 'vmid' => $vmid, 'name' => (string) ($resource['name'] ?? ''), 'node' => (string) ($resource['node'] ?? ''), 'automatic_cleanup' => false],
                        );
                        $detected++;
                    }
                }
            } catch (\Throwable $exception) {
                $failed++;
                $this->upsert(
                    'proxmox_unavailable:' . $connectionId, 'proxmox_reconciliation_unavailable', 'warning', null, null,
                    ['connection_id' => $connectionId, 'error' => mb_substr($exception->getMessage(), 0, 500)],
                );
                $detected++;
            }
        }

        $activeVms = $this->pdo->query(
            "SELECT id,connection_id,node_name,vmid,name FROM virtual_machines
             WHERE deleted_at IS NULL AND status<>'deleted' ORDER BY id LIMIT 500"
        )->fetchAll();
        foreach ($activeVms as $vm) {
            try {
                $client = $this->clients->forConnection((int) $vm['connection_id']);
                $client->get('/nodes/' . rawurlencode((string) $vm['node_name']) . '/qemu/' . (int) $vm['vmid'] . '/status/current');
            } catch (ProxmoxException $exception) {
                if ($exception->httpStatus !== 404) continue;
                $this->upsert(
                    'missing_remote:' . $vm['id'], 'vm_missing_in_proxmox', 'critical', (int) $vm['id'], null,
                    ['connection_id' => (int) $vm['connection_id'], 'node' => $vm['node_name'], 'vmid' => (int) $vm['vmid'], 'name' => $vm['name'], 'automatic_cleanup' => false],
                );
                $detected++;
            } catch (\Throwable) {
                // Connection-wide failure is already represented above. Do not
                // create one incident per VM when Proxmox itself is unavailable.
            }
        }

        return ['detected' => $detected, 'proxmox_connections_checked' => $checked, 'proxmox_connections_failed' => $failed];
    }

    /** @return list<array<string,mixed>> */
    public function incidents(string $status = 'open'): array
    {
        if (!in_array($status, ['open','resolved','ignored','all'], true)) $status = 'open';
        $sql = 'SELECT * FROM reconciliation_incidents';
        $params = [];
        if ($status !== 'all') {
            $sql .= ' WHERE status=:status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY FIELD(severity,\'critical\',\'warning\',\'info\'),last_seen_at DESC,id DESC LIMIT 500';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['details'], true);
            $row['details'] = is_array($decoded) ? $decoded : [];
        }
        return $rows;
    }

    public function close(int $id, string $status): bool
    {
        if (!in_array($status, ['resolved','ignored'], true)) {
            throw new \InvalidArgumentException('Reconciliation incident can only be resolved or ignored.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE reconciliation_incidents SET status=:status,resolved_at=CURRENT_TIMESTAMP WHERE id=:id AND status='open'"
        );
        $statement->execute(['status' => $status, 'id' => $id]);
        return $statement->rowCount() === 1;
    }

    /** @param array<string,mixed> $details */
    private function upsert(string $key, string $type, string $severity, ?int $vmId, ?int $jobId, array $details): void
    {
        $statement = $this->pdo->prepare(
            "INSERT INTO reconciliation_incidents(incident_key,incident_type,severity,virtual_machine_id,job_id,details)
             VALUES(:key,:type,:severity,:vm,:job,:details)
             ON DUPLICATE KEY UPDATE incident_type=VALUES(incident_type),severity=VALUES(severity),
               virtual_machine_id=VALUES(virtual_machine_id),job_id=VALUES(job_id),details=VALUES(details),
               last_seen_at=CURRENT_TIMESTAMP,
               status=IF(status='resolved','open',status),resolved_at=IF(status='resolved',NULL,resolved_at)"
        );
        $statement->execute([
            'key' => mb_substr($key, 0, 191), 'type' => mb_substr($type, 0, 64), 'severity' => $severity,
            'vm' => $vmId, 'job' => $jobId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
