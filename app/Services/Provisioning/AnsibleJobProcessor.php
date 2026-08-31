<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Services\Audit\AuditLogger;
use Throwable;

final class AnsibleJobProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly JobRepository $jobs,
        private readonly AnsiblePlaybookService $ansible,
        private readonly AuditLogger $audit,
    ) {
    }

    public function supports(string $type): bool
    {
        return in_array($type, ['vm.ansible', 'ansible.inventory'], true);
    }

    /** @param array<string,mixed> $job */
    public function process(array $job): void
    {
        $type = (string) ($job['type'] ?? '');
        try {
            if ($type === 'ansible.inventory') {
                $this->processInventory($job);
                return;
            }
            $this->processVm($job);
        } catch (Throwable $exception) {
            $vmId = (int) ($job['virtual_machine_id'] ?? 0);
            if ($vmId > 0) {
                $this->database->pdo()->prepare('UPDATE virtual_machines SET last_error=:error WHERE id=:id')
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'id' => $vmId]);
            }
            $this->jobs->fail((int) $job['id'], $exception->getMessage());
            $final = $this->jobs->find((string) ($job['public_id'] ?? ''));
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $blueprintId = (int) ($payload['blueprint_id'] ?? 0);
            if ($type === 'vm.ansible' && $vmId > 0 && $blueprintId > 0 && is_array($final) && (string) ($final['status'] ?? '') === 'dead_letter') {
                $this->failBlueprintProvisioning($vmId, $exception->getMessage());
            }
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                $type === 'ansible.inventory' ? 'ansible.inventory.run' : 'vm.ansible',
                'failure',
                $type === 'ansible.inventory' ? 'ansible_inventory' : 'virtual_machine',
                $type === 'ansible.inventory'
                    ? (string) ((int) ($job['payload']['inventory_id'] ?? 0))
                    : ($vmId > 0 ? (string) $vmId : null),
                ['playbook' => (string) ($job['payload']['playbook'] ?? ''), 'error' => $exception->getMessage()],
            );
        }
    }

    /** @param array<string,mixed> $job */
    private function processVm(array $job): void
    {
        $vmId = (int) ($job['virtual_machine_id'] ?? 0);
        if ($vmId <= 0) throw new \RuntimeException('Ansible job is not assigned to a virtual machine.');
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $playbook = trim((string) ($payload['playbook'] ?? ''));
        $user = trim((string) ($payload['cloud_init_user'] ?? 'clouduser'));
        $extraVars = is_array($payload['extra_vars'] ?? null) ? $payload['extra_vars'] : [];
        $blueprintId = (int) ($payload['blueprint_id'] ?? 0);
        if ($playbook === '') throw new \RuntimeException('Ansible job does not contain a playbook.');

        $statement = $this->database->pdo()->prepare(
            "SELECT vm.id,vm.name,vm.status,ip.address AS ip_address
             FROM virtual_machines vm
             LEFT JOIN ip_addresses ip ON ip.virtual_machine_id=vm.id
             WHERE vm.id=:id AND vm.status<>'deleted' LIMIT 1"
        );
        $statement->execute(['id' => $vmId]);
        $vm = $statement->fetch();
        if (!is_array($vm)) throw new \RuntimeException('Virtual machine for Ansible job no longer exists.');
        $ip = trim((string) ($vm['ip_address'] ?? ''));
        if ($ip === '') throw new \RuntimeException('Virtual machine does not have an allocated IP address.');

        $result = $this->ansible->run($playbook, $ip, $user, $extraVars);
        $this->database->pdo()->prepare("UPDATE virtual_machines SET status='running',last_error=NULL WHERE id=:id")->execute(['id' => $vmId]);
        if ($blueprintId > 0) {
            $this->completeBlueprintProvisioning($vmId, $playbook);
        }
        $this->jobs->complete((int) $job['id'], [
            'virtual_machine_id' => $vmId,
            'blueprint_id' => $blueprintId > 0 ? $blueprintId : null,
            'provisioning_status' => $blueprintId > 0 ? 'READY' : null,
            'playbook' => $result['playbook'],
            'host' => $result['host'],
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ]);
        $this->audit->log(
            $job['user_id'] === null ? null : (int) $job['user_id'],
            '127.0.0.1',
            'vm.ansible',
            'success',
            'virtual_machine',
            (string) $vmId,
            ['playbook' => $playbook, 'ip_address' => $ip, 'blueprint_id' => $blueprintId ?: null],
        );
    }

    /** @param array<string,mixed> $job */
    private function processInventory(array $job): void
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $inventoryId = (int) ($payload['inventory_id'] ?? 0);
        $playbook = trim((string) ($payload['playbook'] ?? ''));
        $limitVmId = (int) ($payload['limit_vm_id'] ?? 0);
        $extraVars = is_array($payload['extra_vars'] ?? null) ? $payload['extra_vars'] : [];
        if ($inventoryId <= 0) throw new \RuntimeException('Ansible inventory job does not contain an inventory ID.');
        if ($playbook === '') throw new \RuntimeException('Ansible inventory job does not contain a playbook.');

        $inventory = $this->database->pdo()->prepare('SELECT id,name,variables FROM ansible_inventories WHERE id=:id LIMIT 1');
        $inventory->execute(['id' => $inventoryId]);
        $inventoryData = $inventory->fetch();
        if (!is_array($inventoryData)) throw new \RuntimeException('Ansible inventory no longer exists.');
        $inventoryVars = json_decode((string) $inventoryData['variables'], true, 64);
        if (!is_array($inventoryVars)) $inventoryVars = [];

        $sql = "SELECT h.virtual_machine_id,h.host_alias,h.ansible_user,h.variables,ip.address AS ip_address
                FROM ansible_inventory_hosts h
                JOIN virtual_machines vm ON vm.id=h.virtual_machine_id AND vm.status<>'deleted'
                LEFT JOIN ip_addresses ip ON ip.virtual_machine_id=vm.id
                WHERE h.inventory_id=:inventory AND h.enabled=1 AND ip.address IS NOT NULL";
        $params = ['inventory' => $inventoryId];
        if ($limitVmId > 0) {
            $sql .= ' AND h.virtual_machine_id=:vm';
            $params['vm'] = $limitVmId;
        }
        $sql .= ' ORDER BY h.host_alias';
        $hosts = $this->database->pdo()->prepare($sql);
        $hosts->execute($params);
        $hostRows = $hosts->fetchAll();
        if ($hostRows === []) throw new \RuntimeException('Ansible inventory does not contain an enabled target VM with an allocated IP address.');
        foreach ($hostRows as &$host) {
            $decoded = json_decode((string) ($host['variables'] ?? '{}'), true, 64);
            $host['variables'] = is_array($decoded) ? $decoded : [];
        }
        unset($host);

        $result = $this->ansible->runInventory($playbook, $hostRows, array_replace($inventoryVars, $extraVars));
        $this->jobs->complete((int) $job['id'], [
            'inventory_id' => $inventoryId,
            'inventory_name' => (string) $inventoryData['name'],
            'playbook' => $result['playbook'],
            'hosts' => $result['hosts'],
            'exit_code' => $result['exit_code'],
            'output' => $result['output'],
        ]);
        $this->audit->log(
            $job['user_id'] === null ? null : (int) $job['user_id'],
            '127.0.0.1',
            'ansible.inventory.run',
            'success',
            'ansible_inventory',
            (string) $inventoryId,
            ['playbook' => $playbook, 'hosts' => $result['hosts'], 'limit_vm_id' => $limitVmId > 0 ? $limitVmId : null],
        );
    }

    private function completeBlueprintProvisioning(int $vmId, string $playbook): void
    {
        $jobId = $this->blueprintProvisioningJobId($vmId);
        if ($jobId <= 0) return;
        $state = new ProvisioningStateRepository($this->database->pdo());
        $state->transition($jobId, 'CONFIGURING', 14, 'Ansible playbook');
        $state->step($jobId, 14, 'Ansible playbook', $playbook . ' completed successfully');
        $state->ready($jobId, 14, 'READY');
    }

    private function failBlueprintProvisioning(int $vmId, string $message): void
    {
        $this->database->pdo()->prepare("UPDATE virtual_machines SET status='error',last_error=:error WHERE id=:id")
            ->execute(['error' => mb_substr($message, 0, 1000), 'id' => $vmId]);
        $jobId = $this->blueprintProvisioningJobId($vmId);
        if ($jobId <= 0) return;
        try {
            (new ProvisioningStateRepository($this->database->pdo()))->error($jobId, 'Ansible failed after all retry attempts: ' . $message);
        } catch (Throwable) {
        }
    }

    private function blueprintProvisioningJobId(int $vmId): int
    {
        $statement = $this->database->pdo()->prepare(
            "SELECT vp.job_id FROM vm_provisioning vp
             JOIN virtual_machines vm ON vm.id=vp.virtual_machine_id
             WHERE vp.virtual_machine_id=:vm AND vm.blueprint_id IS NOT NULL
             ORDER BY vp.id DESC LIMIT 1"
        );
        $statement->execute(['vm' => $vmId]);
        return (int) $statement->fetchColumn();
    }
}
