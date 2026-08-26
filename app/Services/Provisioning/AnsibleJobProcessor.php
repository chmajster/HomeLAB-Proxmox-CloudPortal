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
        return $type === 'vm.ansible';
    }

    /** @param array<string,mixed> $job */
    public function process(array $job): void
    {
        $vmId = (int) ($job['virtual_machine_id'] ?? 0);
        try {
            if ($vmId <= 0) throw new \RuntimeException('Ansible job is not assigned to a virtual machine.');
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $playbook = trim((string) ($payload['playbook'] ?? ''));
            $user = trim((string) ($payload['cloud_init_user'] ?? ''));
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

            $result = $this->ansible->run($playbook, $ip, $user);
            $this->database->pdo()->prepare('UPDATE virtual_machines SET last_error=NULL WHERE id=:id')->execute(['id' => $vmId]);
            $this->jobs->complete((int) $job['id'], [
                'virtual_machine_id' => $vmId,
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
                ['playbook' => $playbook, 'ip_address' => $ip],
            );
        } catch (Throwable $exception) {
            if ($vmId > 0) {
                $this->database->pdo()->prepare('UPDATE virtual_machines SET last_error=:error WHERE id=:id')
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'id' => $vmId]);
            }
            $this->jobs->fail((int) $job['id'], $exception->getMessage());
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.ansible',
                'failure',
                'virtual_machine',
                $vmId > 0 ? (string) $vmId : null,
                ['playbook' => (string) ($job['payload']['playbook'] ?? ''), 'error' => $exception->getMessage()],
            );
        }
    }
}
