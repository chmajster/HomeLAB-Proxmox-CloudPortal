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
        private readonly string $setupCommand = '/root/vm-setup.sh',
        private readonly string $puppetCommand = 'puppet agent --test',
        private readonly int $guestAgentTimeout = 300,
        private readonly int $guestCommandTimeout = 900,
        private readonly ?TerraformProvisioner $terraformCreate = null,
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
            $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
            $provisioning = $state->forJob($jobId);
            $hostname = (string) $provisioning['hostname'];
            $ipAddress = (string) $provisioning['ip_address'];
            $vmId = (int) ($provisioning['virtual_machine_id'] ?? 0);

            $state->transition($jobId, 'DNS_CONFIGURING', 4, 'Create DNS records');
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
            $state->transition($jobId, 'DNS_READY', 6, 'Verify DNS', 'A and PTR verified against HomeLAB-DNS');

            $final = $this->jobs->find((string) $job['public_id']);
            if (!is_array($final)) {
                throw new \RuntimeException('Provisioning job disappeared before VM creation.');
            }

            $state->transition($jobId, 'PROVISIONING', 7, 'Clone VM from template');
            if ($vmId <= 0) {
                if ($this->terraformCreate instanceof TerraformProvisioner) {
                    $created = $this->terraformCreate->create($job);
                    $vmId = (int) ($created['virtual_machine_id'] ?? 0);
                } else {
                    if ((string) $job['type'] === 'vm.create.placed') {
                        $this->placedCreate->process($job);
                    } else {
                        $this->localCreate->process($job);
                    }
                    $final = $this->jobs->find((string) $job['public_id']);
                    if (!is_array($final)) {
                        throw new \RuntimeException('Provisioning job disappeared after VM creation.');
                    }
                    if ((string) $final['status'] === 'queued') {
                        return;
                    }
                    $vmId = (int) ($final['virtual_machine_id'] ?? 0);
                }
                if ($vmId <= 0) {
                    $message = is_array($final) ? (string) ($final['error_message'] ?? 'VM creation failed.') : 'VM creation failed.';
                    $this->cleanupPreVmFailure($jobId, $job, $message);
                    return;
                }
                $state->step($jobId, 7, 'Clone VM from template', 'VM database ID ' . $vmId);
            } else {
                $state->step($jobId, 7, 'Clone VM from template', 'VM database ID ' . $vmId . ' already exists; provisioning resumed');
            }
            $state->creating($jobId, $vmId);

            $blueprintId = (int) ($payload['blueprint_id'] ?? 0);
            if ($blueprintId > 0) {
                $this->database->pdo()->prepare('UPDATE virtual_machines SET blueprint_id=:blueprint WHERE id=:id')
                    ->execute(['blueprint' => $blueprintId, 'id' => $vmId]);
            }

            $vm = $this->vm($vmId);
            $client = $this->clients->forConnection((int) $vm['connection_id']);
            $path = '/nodes/' . rawurlencode((string) $vm['node_name']) . '/qemu/' . (int) $vm['vmid'];

            $state->transition($jobId, 'BOOTING', 9, 'VM starts');
            $current = $client->get($path . '/status/current');
            if (!is_array($current) || (string) ($current['status'] ?? '') !== 'running') {
                $upid = $this->requireUpid($client->post($path . '/status/start'));
                $client->waitForTask((string) $vm['node_name'], $upid, 900);
            }
            $this->database->pdo()->prepare("UPDATE virtual_machines SET status='running',last_error=NULL WHERE id=:id")
                ->execute(['id' => $vmId]);
            $this->waitForGuestAgent($client, $path);
            $state->step($jobId, 9, 'VM starts', 'QEMU guest agent is responding');

            $hardeningCommand = trim((string) ($payload['initial_hardening_command'] ?? $this->setupCommand));
            if ($hardeningCommand !== '') {
                $state->transition($jobId, 'BOOTSTRAPPING', 10, 'Initial hardening');
                $this->execGuest($client, $path, $hardeningCommand, 'Initial hardening');
                $state->step($jobId, 10, 'Initial hardening', 'completed');
            } else {
                $state->step($jobId, 10, 'Initial hardening', 'skipped');
            }

            $runPuppet = array_key_exists('run_puppet', $payload) ? (bool) $payload['run_puppet'] : true;
            if ($runPuppet) {
                $state->transition($jobId, 'PUPPET_ENROLLMENT', 11, 'Puppet enrollment');
                $this->execGuest($client, $path, $this->puppetCommand, 'Puppet');
                $state->step($jobId, 11, 'Puppet enrollment', 'completed');
            } else {
                $state->step($jobId, 11, 'Puppet enrollment', 'skipped by blueprint');
            }

            $playbook = trim((string) ($payload['ansible_playbook'] ?? ''));
            $rebootBeforeAnsible = !empty($payload['reboot_before_ansible']) && $playbook !== '';
            if ($rebootBeforeAnsible) {
                $state->transition($jobId, 'REBOOTING', 12, 'Reboot before Ansible');
                $upid = $this->requireUpid($client->post($path . '/status/reboot'));
                $client->waitForTask((string) $vm['node_name'], $upid, 900);
                sleep(3);
                $this->waitForGuestAgent($client, $path);
                $state->step($jobId, 12, 'Reboot before Ansible', 'VM rebooted and guest agent is responding');
            }

            $state->transition($jobId, 'CONFIGURING', 13, 'Ready for Ansible');
            $state->step($jobId, 13, 'Ready for Ansible', $playbook === '' ? 'No Ansible playbook selected' : 'Ansible job will be queued automatically');
            $state->ready($jobId);
            $ready = $state->forJob($jobId);
            $latest = $this->jobs->find((string) $job['public_id']);
            $result = is_array($latest['result'] ?? null) ? $latest['result'] : (is_array($final['result'] ?? null) ? $final['result'] : []);
            $result['virtual_machine_id'] = $vmId;
            $result['hostname'] = (string) $ready['hostname'];
            $result['fqdn'] = (string) $ready['fqdn'];
            $result['ip_address'] = (string) $ready['ip_address'];
            $result['blueprint_id'] = $blueprintId > 0 ? $blueprintId : null;
            $result['provisioning_status'] = $playbook === '' ? 'READY' : 'READY_FOR_ANSIBLE';
            $this->jobs->complete($jobId, $result);
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.provisioning.ready',
                'success',
                'virtual_machine',
                (string) $vmId,
                ['hostname' => $ready['hostname'], 'fqdn' => $ready['fqdn'], 'ip_address' => $ready['ip_address'], 'blueprint_id' => $blueprintId ?: null],
            );
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            if ($vmId > 0 && $this->terraformCreate instanceof TerraformProvisioner) {
                try {
                    $state->rollback($jobId, 'Provisioning failed after VM creation; Terraform rollback started.');
                    if ($this->terraformCreate->destroyForRollback($job, $vmId)) {
                        $this->cleanupPreVmFailure($jobId, $job, $message, false);
                        $state->error($jobId, $message);
                    } else {
                        $state->rollbackFailed($jobId, $message . ' Terraform could not verify VM removal; IP and DNS were retained.');
                    }
                } catch (Throwable $rollbackException) {
                    try {
                        $state->rollbackFailed($jobId, $message . ' Rollback error: ' . $rollbackException->getMessage());
                    } catch (Throwable) {
                    }
                }
            } elseif ($vmId > 0) {
                $this->database->pdo()->prepare("UPDATE virtual_machines SET status='error',last_error=:error WHERE id=:id")
                    ->execute(['error' => mb_substr($message, 0, 1000), 'id' => $vmId]);
                try {
                    $state->error($jobId, $message);
                } catch (Throwable) {
                }
            } else {
                $this->cleanupPreVmFailure($jobId, $job, $message, false);
                try {
                    $state->error($jobId, $message);
                } catch (Throwable) {
                }
            }
            $this->jobs->failPermanent($jobId, $message);
            $this->audit->log(
                $job['user_id'] === null ? null : (int) $job['user_id'],
                '127.0.0.1',
                'vm.provisioning',
                'failure',
                'job',
                (string) $job['public_id'],
                ['step_error' => $message, 'virtual_machine_id' => $vmId ?: null],
            );
        }
    }

    /** @param array<string,mixed> $job */
    private function cleanupPreVmFailure(int $jobId, array $job, string $message, bool $markJob = true): void
    {
        $stateRepository = new ProvisioningStateRepository($this->database->pdo());

        // Fail closed: a reservation must never be released while a VM with this
        // provisioning hostname may still exist. An API error is also treated as
        // "unknown", therefore resources stay reserved for reconciliation.
        if ($this->remoteVmExistsOrUnknown($job)) {
            try {
                $stateRepository->rollbackFailed(
                    $jobId,
                    $message . ' Proxmox VM absence could not be proven; IP and DNS were retained for reconciliation.',
                );
            } catch (Throwable) {
            }
            if ($markJob) {
                $this->jobs->failPermanent($jobId, $message);
            }
            return;
        }

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
        if ($markJob) {
            $this->jobs->failPermanent($jobId, $message);
        }
    }

    /** @param array<string,mixed> $job */
    private function remoteVmExistsOrUnknown(array $job): bool
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '' || empty($job['connection_id'])) {
            return true;
        }

        try {
            $client = $this->clients->forConnection((int) $job['connection_id']);
            $resources = $client->get('/cluster/resources', ['type' => 'vm']);
            if (!is_array($resources)) {
                return true;
            }
            foreach ($resources as $resource) {
                if (is_array($resource) && (string) ($resource['name'] ?? '') === $name) {
                    return true;
                }
            }
            return false;
        } catch (Throwable) {
            return true;
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