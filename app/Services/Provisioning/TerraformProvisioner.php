<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Services\IPAM\IPAMService;
use CloudPortal\Services\Quota\QuotaService;
use PDO;

final class TerraformProvisioner
{
    public function __construct(
        private readonly Database $database,
        private readonly JobRepository $jobs,
        private readonly string $command = '/usr/local/sbin/algen-terraform-provisioner',
        private readonly int $timeoutSeconds = 1200,
    ) {
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function create(array $job): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $reservationKey = trim((string) ($job['reservation_key'] ?? ''));
        if ($reservationKey === '' || empty($job['connection_id'])) {
            throw new \RuntimeException('Managed Terraform provisioning requires a connection and reservation key.');
        }

        $response = $this->invoke([
            'action' => 'create',
            'job_id' => (string) ($job['public_id'] ?? ''),
            'vm' => [
                'name' => (string) ($payload['name'] ?? ''),
                'ip_address' => (string) ($payload['ip_address'] ?? ''),
                'ip_cidr' => (string) ($payload['ip_cidr'] ?? ''),
                'gateway' => (string) ($payload['gateway'] ?? ''),
                'dns_servers' => (string) ($payload['dns_servers'] ?? ''),
                'search_domain' => (string) ($payload['search_domain'] ?? ''),
                'vcpu' => (int) ($payload['vcpu'] ?? 0),
                'ram_mb' => (int) ($payload['ram_mb'] ?? 0),
                'disk_gb' => (int) ($payload['disk_gb'] ?? 0),
                'bridge' => (string) ($payload['bridge'] ?? ''),
                'vlan_id' => $payload['vlan_id'] ?? null,
                'cloud_init_profile_id' => $payload['cloud_init_profile_id'] ?? null,
                'cloud_init_profile_name' => $payload['cloud_init_profile_name'] ?? null,
                'cloud_init_user' => (string) ($payload['cloud_init_user'] ?? 'clouduser'),
                'ssh_public_key' => (string) ($payload['ssh_public_key'] ?? ''),
                'ssh_public_keys' => is_array($payload['ssh_public_keys'] ?? null) ? $payload['ssh_public_keys'] : [],
                'qemu_guest_agent' => (bool) ($payload['qemu_guest_agent'] ?? true),
                'cicustom_vendor' => $payload['cicustom_vendor'] ?? null,
                'cloud_init_vendor_sha256' => $payload['cloud_init_vendor_sha256'] ?? null,
                'template_vmid' => (int) ($payload['template_vmid'] ?? 0),
                'node_name' => (string) ($payload['node_name'] ?? ''),
                'storage_name' => (string) ($payload['storage_name'] ?? ''),
            ],
        ]);
        $vmid = (int) ($response['vmid'] ?? 0);
        if (($response['ok'] ?? false) !== true || $vmid <= 0 || ($response['vm_absent'] ?? false) === true) {
            throw new \RuntimeException('Terraform provisioner did not return a valid created VM.');
        }

        $vmId = $this->database->transaction(function (PDO $pdo) use ($job, $payload, $vmid, $reservationKey): int {
            $statement = $pdo->prepare(
                'INSERT INTO virtual_machines
                 (connection_id, project_id, owner_user_id, template_id, resource_plan_id, network_id, storage_id, cloud_init_profile_id,
                  vmid, node_name, name, status, vcpu, ram_mb, disk_gb)
                 VALUES (:connection, :project, :owner, :template, :plan, :network, :storage, :cloud_profile,
                         :vmid, :node, :name, :status, :vcpu, :ram, :disk)'
            );
            $statement->execute([
                'connection' => $job['connection_id'],
                'project' => $payload['project_id'],
                'owner' => $payload['owner_user_id'],
                'template' => $payload['template_id'],
                'plan' => $payload['plan_id'],
                'network' => $payload['network_id'],
                'storage' => $payload['storage_id'],
                'cloud_profile' => $payload['cloud_init_profile_id'] ?? null,
                'vmid' => $vmid,
                'node' => $payload['node_name'],
                'name' => $payload['name'],
                'status' => 'stopped',
                'vcpu' => $payload['vcpu'],
                'ram' => $payload['ram_mb'],
                'disk' => $payload['disk_gb'],
            ]);
            $vmId = (int) $pdo->lastInsertId();
            (new IPAMService($pdo))->allocate($reservationKey, $vmId);
            (new QuotaService($pdo))->release($reservationKey);
            $pdo->prepare('UPDATE jobs SET virtual_machine_id=:vm WHERE id=:id')->execute(['vm' => $vmId, 'id' => $job['id']]);
            return $vmId;
        });

        return ['virtual_machine_id' => $vmId, 'vmid' => $vmid, 'status' => 'stopped', 'terraform' => true, 'cloud_init_profile_id' => $payload['cloud_init_profile_id'] ?? null];
    }

    /** @param array<string,mixed> $job */
    public function destroyForRollback(array $job, int $vmId): bool
    {
        $response = $this->invoke([
            'action' => 'destroy',
            'job_id' => (string) ($job['public_id'] ?? ''),
            'vm_id' => $vmId,
        ]);
        if (($response['ok'] ?? false) !== true || ($response['vm_absent'] ?? false) !== true) return false;
        $this->database->transaction(function (PDO $pdo) use ($vmId): void {
            (new IPAMService($pdo))->releaseVm($vmId);
            $pdo->prepare("UPDATE virtual_machines SET status='deleted', deleted_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['id' => $vmId]);
        });
        return true;
    }

    /** @param array<string,mixed> $request @return array<string,mixed> */
    private function invoke(array $request): array
    {
        if (!str_starts_with($this->command, '/') || preg_match('/[\r\n\0]/', $this->command)) throw new \RuntimeException('Terraform provisioner command must be a fixed absolute path.');
        $payload = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['sudo', '-n', $this->command], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) throw new \RuntimeException('Could not start Terraform provisioner.');
        fwrite($pipes[0], $payload . "\n");
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(30, $this->timeoutSeconds);
        $exitCode = -1;
        try {
            do {
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                if (strlen($stdout) > 1048576 || strlen($stderr) > 1048576) {
                    proc_terminate($process, 9);
                    throw new \RuntimeException('Terraform provisioner output exceeded 1 MiB.');
                }
                $status = proc_get_status($process);
                if (!$status['running']) { $exitCode = (int) $status['exitcode']; break; }
                if (microtime(true) >= $deadline) {
                    proc_terminate($process, 15);
                    usleep(200000);
                    proc_terminate($process, 9);
                    throw new \RuntimeException('Terraform provisioner timed out.');
                }
                usleep(100000);
            } while (true);
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);
            if ($exitCode < 0 && $closed >= 0) $exitCode = $closed;
        }
        if ($exitCode !== 0) {
            $detail = trim($stderr);
            throw new \RuntimeException('Terraform provisioner failed' . ($detail === '' ? '.' : ': ' . mb_substr($detail, 0, 1000)));
        }
        try {
            $decoded = json_decode(trim($stdout), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Terraform provisioner returned invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded)) throw new \RuntimeException('Terraform provisioner returned an invalid response.');
        return $decoded;
    }
}
