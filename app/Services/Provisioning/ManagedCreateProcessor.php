<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Database\Database;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\DNS\DnsApiClientInterface;
use CloudPortal\Services\IPAM\IPAMService;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxClientProviderInterface;
use CloudPortal\Services\Quota\QuotaService;
use Throwable;

final class ManagedCreateProcessor
{
    public function __construct(
        private readonly Database $database,
        private readonly ProxmoxClientProviderInterface $clients,
        private readonly JobRepository $jobs,
        private readonly AuditLogger $audit,
        private readonly DnsApiClientInterface $dns,
        private readonly ProxmoxProvisioner $localCreate,
        private readonly PlacedCreateProcessor $placedCreate,
        private readonly ?string $forwardZone = null,
        private readonly string $setupCommand = '/usr/local/sbin/vm-setup.sh',
        private readonly string $puppetCommand = 'puppet agent --test',
        private readonly int $guestAgentTimeout = 300,
        private readonly int $guestCommandTimeout = 900,
    ) {
    }

    /** @param array<string,mixed> $job */
    public function supports(array $job): bool
    {
        return in_array((string) ($job['type'] ?? ''), ['vm.create', 'vm.create.placed'], true)
            && ($job['payload']['managed_provisioning'] ?? false) === true;
    }

    /** @param array<string,mixed> $job */
    public function process(array $job): void
    {
        $state = new ProvisioningStateRepository($this->database->pdo());
        $jobId = (int) $job['id'];
        $vmId = 0;
        try {
            $provisioning = $state->forJob($jobId);
            $hostname = (string) $provisioning['hostname'];
            $ipAddress = (string) $provisioning['ip_address'];
            $vmId = (int) ($provisioning['virtual_machine_id'] ?? 0);

            $state->begin($jobId, 4, 'Create A');
            $dns = $this->dns->ensureVmRecords($hostname, $ipAddress, $this->forwardZone);
            $state->dns(
                $jobId,
                $dns['fqdn'],
                $dns['forward_zone'],
                $dns['reverse_zone'],
                $dns['a_record_id'] > 0 ? $dns['a_record_id'] : null,
                $dns['ptr_record_id'] > 0 ? $dns['ptr_record_id'] : null,
            );
            $state->step($jobId, 4, 'Create A', $dns['fqdn'] . ' -> ' . $ipAddress);
            $state->step($jobId, 5, 'Create PTR', $ipAddress . ' -> ' . $dns['fqdn']);

            $state->begin($jobId, 6, 'Verify DNS');
            $this->dns->verifyVmRecords($dns['fqdn'], $ipAddress);
            $state->step($jobId, 6, 'Verify DNS', 'A and PTR verified against HomeLAB-DNS');

            $final = $this->jobs->find((string) $job['public_id']);
            if (!is_array($final)) {
                throw new \RuntimeException('Provisioning job disappeared before Proxmox create.');
            }

            $state->begin($jobId, 7, 'Create VM');
            if ($vmId <= 0) {
                if ((string) $job['type'] === 'vm.create.placed') {
                    $this->placedCreate->process($job);
                } else {
                    $this->localCreate->process($job);
                }

                $final = $this->jobs->find((string) $job['public_id']);
                if (!is_array($final)) {
                    throw new \RuntimeException('Provisioning job disappeared after Proxmox create.');
                }
                if ((string) $final['status'] === 'queued') {
                    return;
                }
                $vmId = (int) ($final['virtual_machine_id'] ?? 0);
                if ($vmId <= 0) {
                    $message = (string) ($final['error_message'] ?? 'Proxmox VM creation failed.');
                    $this->cleanupPreVmFailure($jobId, $job, $message);
                    return;
                }
                $state->step($jobId, 7, 'Create VM', 'VM ID ' . $vmId);
            } else {
                $state->step($jobId, 7, 'Create VM', 'VM ID ' . $vmId . ' already exists; provisioning resumed');
            }
            $state->creating($jobId, $vmId);

            $vm = $this->vm($vmId);
            $client = $this->clients->forConnection((int) $vm['connection_id']);
            $path = '/nodes/' . rawurlencode((string) $vm['node_name']) . '/qemu/' . (int) $vm['vmid'];

            $state->begin($jobId, 9, 'VM starts');
            $current = $client->get($path . '/status/current');
            if (!is_array($current) || (string) ($current['status'] ?? '') !== 'running') {
                $upid = $this->requireUpid($client->post($path . '/status/start'));
                $client->waitForTask((string) $vm['node_name'], $upid, 900);
            }
            $this->database->pdo()->prepare("UPDATE virtual_machines SET status='running',last_error=NULL WHERE id=:id")
                ->execute(['id' => $vmId]);
            $this->waitForGuestAgent($client, $path);
            $state->step($jobId, 9, 'VM starts', 'QEMU guest agent is responding');

            $state->begin($jobId, 10, 'vm-setup.sh');
            $this->execGuest($client, $path, $this->setupCommand, 'vm-setup.sh');
            $state->step($jobId, 10, 'vm-setup.sh', 'completed');

            $state->begin($jobId, 11, 'Puppet');
            $this->execGuest($client, $path, $this->puppetCommand, 'Puppet');
            $state->step($jobId, 11, 'Puppet', 'completed');

            $state->ready($jobId);
            $ready = $state->forJob($jobId);
            $latest = $this->jobs->find((string) $job['public_id']);
            $result = is_array($latest['result'] ?? null) ? $latest['result'] : (is_array($final['result'] ?? null) ? $final['result'] : []);
            $result['virtual_machine_id'] = $vmId;
            $result['hostname'] = (string) $ready['hostname'];
            $result['fqdn'] = (string) $ready['fqdn'];
            $result['ip_address'] = (string) $ready['ip_address'];
            $result['provisioning_status'] = 'READY';
            $this->jobs->complete($jobId, $result);
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.provisioning.ready',
                'success',
                'virtual_machine',
                (string) $vmId,
                ['hostname' => $ready['hostname'], 'fqdn' => $ready['fqdn'], 'ip_address' => $ready['ip_address']],
            );
        } catch (Throwable $exception) {
            if ($vmId > 0) {
                $this->database->pdo()->prepare("UPDATE virtual_machines SET status='error',last_error=:error WHERE id=:id")
                    ->execute(['error' => mb_substr($exception->getMessage(), 0, 1000), 'id' => $vmId]);
            } else {
                $this->cleanupPreVmFailure($jobId, $job, $exception->getMessage(), false);
            }
            try {
                $state->error($jobId, $exception->getMessage());
            } catch (Throwable) {
            }
            $this->jobs->failPermanent($jobId, $exception->getMessage());
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.provisioning',
                'failure',
                'job',
                (string) $job['public_id'],
                ['step_error' => $exception->getMessage(), 'virtual_machine_id' => $vmId ?: null],
            );
        }
    }

    /** @param array<string,mixed> $job */
    private function cleanupPreVmFailure(int $jobId, array $job, string $message, bool $markJob = true): void
    {
        $stateRepository = new ProvisioningStateRepository($this->database->pdo());
        try {
            $state = $stateRepository->forJob($jobId);
            if (!empty($state['ptr_record_id']) && !empty($state['reverse_zone'])) {
                $this->dns->deleteRecord((string) $state['reverse_zone'], (int) $state['ptr_record_id']);
            }
            if (!empty($state['a_record_id']) && !empty($state['forward_zone'])) {
                $this->dns->deleteRecord((string) $state['forward_zone'], (int) $state['a_record_id']);
            }
        } catch (Throwable) {
        }
        if (!empty($job['reservation_key'])) {
            $this->database->transaction(function ($pdo) use ($job): void {
                (new IPAMService($pdo))->releaseReservation((string) $job['reservation_key']);
                (new QuotaService($pdo))->release((string) $job['reservation_key']);
            });
        }
        try {
            $stateRepository->error($jobId, $message);
        } catch (Throwable) {
        }
        if ($markJob) {
            $this->jobs->failPermanent($jobId, $message);
        }
    }

    /** @return array<string,mixed> */
    private function vm(int $vmId): array
    {
        $statement = $this->database->pdo()->prepare("SELECT * FROM virtual_machines WHERE id=:id AND status<>'deleted' LIMIT 1");
        $statement->execute(['id' => $vmId]);
        $vm = $statement->fetch();
        if (!is_array($vm)) {
            throw new \RuntimeException('Created virtual machine is missing from the database.');
        }
        return $vm;
    }

    private function waitForGuestAgent(ProxmoxClientInterface $client, string $path): void
    {
        $deadline = time() + max(30, $this->guestAgentTimeout);
        $lastError = 'QEMU guest agent did not answer.';
        do {
            try {
                $client->post($path . '/agent/ping');
                return;
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
                sleep(5);
            }
        } while (time() < $deadline);
        throw new \RuntimeException('Timed out waiting for QEMU guest agent: ' . $lastError);
    }

    private function execGuest(ProxmoxClientInterface $client, string $path, string $command, string $label): void
    {
        $command = trim($command);
        if ($command === '' || preg_match('/[\r\n]/', $command)) {
            throw new \RuntimeException($label . ' command is invalid.');
        }
        $response = $client->post($path . '/agent/exec', [
            'command' => ['/bin/sh', '-lc', $command],
        ]);
        $pid = is_array($response) ? (int) ($response['pid'] ?? 0) : 0;
        if ($pid <= 0) {
            throw new \RuntimeException('QEMU guest agent did not return a PID for ' . $label . '.');
        }
        $deadline = time() + max(30, $this->guestCommandTimeout);
        do {
            $status = $client->get($path . '/agent/exec-status', ['pid' => $pid]);
            if (is_array($status) && !empty($status['exited'])) {
                $exitCode = (int) ($status['exitcode'] ?? 1);
                if ($exitCode !== 0) {
                    $stderr = trim((string) ($status['err-data'] ?? ''));
                    $suffix = $stderr === '' ? '' : ': ' . mb_substr($stderr, 0, 500);
                    throw new \RuntimeException($label . ' failed with exit code ' . $exitCode . $suffix);
                }
                return;
            }
            sleep(2);
        } while (time() < $deadline);
        throw new \RuntimeException('Timed out waiting for ' . $label . ' inside the VM.');
    }

    private function requireUpid(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new \RuntimeException('Proxmox did not return a task UPID.');
        }
        return $value;
    }
}
