<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use PDO;
use Throwable;

final class VmIdentityJobProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly ProxmoxClientProviderInterface $clients,
        private readonly JobRepository $jobs,
        private readonly AuditLogger $audit,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'vm.rename';
    }

    /** @param array<string,mixed> $job */
    public function process(array $job): void
    {
        try {
            $result = $this->rename($job);
            $this->jobs->complete((int) $job['id'], $result);
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.rename',
                'success',
                'virtual_machine',
                (string) $job['virtual_machine_id'],
                $result,
            );
        } catch (Throwable $exception) {
            $this->jobs->fail((int) $job['id'], $exception->getMessage());
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.rename',
                'failure',
                'virtual_machine',
                (string) $job['virtual_machine_id'],
                ['error' => $exception->getMessage()],
            );
        }
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function rename(array $job): array
    {
        $vmId = (int) ($job['virtual_machine_id'] ?? 0);
        $name = trim((string) ($job['payload']['name'] ?? ''));
        if ($vmId <= 0 || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{1,62}$/', $name) !== 1) {
            throw new \RuntimeException('Rename job payload is invalid.');
        }

        $statement = $this->database->pdo()->prepare("SELECT * FROM virtual_machines WHERE id=:id AND status<>'deleted' LIMIT 1");
        $statement->execute(['id' => $vmId]);
        $vm = $statement->fetch();
        if (!is_array($vm)) {
            throw new \RuntimeException('Virtual machine no longer exists.');
        }

        $previousName = (string) $vm['name'];
        if ($previousName !== $name) {
            $this->assertNameAvailable((int) $vm['project_id'], $vmId, $name);

            $client = $this->clients->forConnection((int) $vm['connection_id']);
            $path = '/nodes/' . rawurlencode((string) $vm['node_name']) . '/qemu/' . (int) $vm['vmid'] . '/config';
            $client->put($path, ['name' => $name]);

            try {
                $this->database->transaction(function (PDO $pdo) use ($vmId, $vm, $name): void {
                    $duplicate = $pdo->prepare("SELECT id FROM virtual_machines WHERE project_id=:project AND name=:name AND status<>'deleted' AND id<>:vm LIMIT 1 FOR UPDATE");
                    $duplicate->execute(['project' => $vm['project_id'], 'name' => $name, 'vm' => $vmId]);
                    if ($duplicate->fetchColumn()) {
                        throw new \RuntimeException('A VM with this name already exists in the project.');
                    }
                    $pdo->prepare('UPDATE virtual_machines SET name=:name,last_error=NULL WHERE id=:id')->execute(['name' => $name, 'id' => $vmId]);
                });
            } catch (Throwable $exception) {
                try {
                    $client->put($path, ['name' => $previousName]);
                } catch (Throwable $rollbackException) {
                    throw new \RuntimeException(
                        $exception->getMessage() . ' Proxmox rename rollback also failed: ' . $rollbackException->getMessage(),
                        0,
                        $exception,
                    );
                }
                throw $exception;
            }
        }

        return [
            'virtual_machine_id' => $vmId,
            'previous_name' => (string) ($job['payload']['previous_name'] ?? $previousName),
            'name' => $name,
        ];
    }

    private function assertNameAvailable(int $projectId, int $vmId, string $name): void
    {
        $statement = $this->database->pdo()->prepare("SELECT 1 FROM virtual_machines WHERE project_id=:project AND name=:name AND status<>'deleted' AND id<>:vm LIMIT 1");
        $statement->execute(['project' => $projectId, 'name' => $name, 'vm' => $vmId]);
        if ($statement->fetchColumn()) {
            throw new \RuntimeException('A VM with this name already exists in the project.');
        }
    }
}
