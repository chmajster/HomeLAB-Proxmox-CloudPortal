<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use PDO;

final class VmIdentityService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function rename(int $vmId, int $userId, bool $isAdmin, string $name): string
    {
        $name = trim($name);
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]{1,62}$/', $name) !== 1) {
            throw new HttpException(422, 'VM name must contain 2-63 letters, digits or hyphens.');
        }

        return $this->database->transaction(function (PDO $pdo) use ($vmId, $userId, $isAdmin, $name): string {
            $vm = (new VmOperationService($this->database))->accessibleVm($vmId, $userId, $isAdmin, true);
            if ((string) $vm['name'] === $name) {
                throw new HttpException(409, 'The VM already has this name.');
            }

            $activeJob = $pdo->prepare("SELECT 1 FROM jobs WHERE virtual_machine_id=:vm AND status IN ('queued','running') LIMIT 1");
            $activeJob->execute(['vm' => $vmId]);
            if ($activeJob->fetchColumn()) {
                throw new HttpException(409, 'Another operation is already running for this VM.');
            }

            $duplicate = $pdo->prepare("SELECT id FROM virtual_machines WHERE project_id=:project AND name=:name AND status<>'deleted' AND id<>:vm LIMIT 1 FOR UPDATE");
            $duplicate->execute(['project' => $vm['project_id'], 'name' => $name, 'vm' => $vmId]);
            if ($duplicate->fetchColumn()) {
                throw new HttpException(409, 'A VM with this name already exists in the project.');
            }

            return (new JobRepository($pdo))->enqueue(
                'vm.rename',
                $userId,
                (int) $vm['project_id'],
                (int) $vm['connection_id'],
                ['name' => $name, 'previous_name' => (string) $vm['name']],
                null,
                $vmId,
            );
        });
    }
}
