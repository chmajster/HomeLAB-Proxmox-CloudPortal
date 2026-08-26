#!/usr/bin/env php
<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Database\Database;
use CloudPortal\Services\DNS\DnsSettingsService;
use CloudPortal\Services\Notifications\WebhookService;
use CloudPortal\Services\Observability\WorkerHeartbeatService;
use CloudPortal\Services\Provisioning\AdvancedJobProcessor;
use CloudPortal\Services\Provisioning\AnsibleJobProcessor;
use CloudPortal\Services\Provisioning\AnsiblePlaybookService;
use CloudPortal\Services\Provisioning\JobRepository;
use CloudPortal\Services\Provisioning\ManagedCreateProcessor;
use CloudPortal\Services\Provisioning\PlacedCreateProcessor;
use CloudPortal\Services\Provisioning\ProxmoxProvisioner;
use CloudPortal\Services\Provisioning\TerraformProvisioner;
use CloudPortal\Services\Provisioning\VmDeleteLifecycleProcessor;
use CloudPortal\Services\Provisioning\VmIdentityJobProcessor;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';
$app = new Application($root);
date_default_timezone_set((string) $app->config->get('app.timezone', 'UTC'));
if (!$app->installed()) {
    fwrite(STDERR, "Portal is not installed.\n");
    exit(1);
}
$maintenancePath = $root . '/storage/maintenance.json';
if (is_file($maintenancePath)) {
    fwrite(STDERR, "Portal maintenance mode is active; worker is not starting.\n");
    exit(0);
}
$database = new Database($app->config);
$jobs = new JobRepository($database->pdo());
$clients = new ProxmoxClientFactory($database->pdo(), $app->crypto());
$provisioner = new ProxmoxProvisioner($database, $clients, $jobs, $app->audit());
$advanced = new AdvancedJobProcessor($database, $clients, $jobs, $app->audit());
$placedCreate = new PlacedCreateProcessor($database, $clients, $jobs, $app->audit());
$identity = new VmIdentityJobProcessor($database, $clients, $jobs, $app->audit());
$ansible = AnsiblePlaybookService::fromConfig($app->config, $root);
$ansibleProcessor = new AnsibleJobProcessor($database, $jobs, $ansible, $app->audit());
$terraformCreate = new TerraformProvisioner(
    $database,
    $jobs,
    (string) $app->config->get('provisioning.terraform_command', '/usr/local/sbin/algen-terraform-provisioner'),
    (int) $app->config->get('provisioning.terraform_timeout', 1200),
);

$lifecycleDnsClient = null;
try {
    $lifecycleDnsSettings = new DnsSettingsService($database->pdo(), $app->crypto(), $app->config);
    if ($lifecycleDnsSettings->configured()) {
        $lifecycleDnsClient = $lifecycleDnsSettings->client();
    }
} catch (Throwable $exception) {
    // Do not block the worker globally. Unmanaged deletes remain possible, while
    // managed deletes retain IPAM and retry until DNS configuration is healthy.
    error_log('Lifecycle DNS configuration could not be initialized: ' . $exception->getMessage());
}
$deleteLifecycle = new VmDeleteLifecycleProcessor(
    $database,
    $clients,
    $jobs,
    $app->audit(),
    $lifecycleDnsClient,
);

$managedProcessor = static function () use ($database, $clients, $jobs, $app, $provisioner, $placedCreate, $terraformCreate): ?ManagedCreateProcessor {
    $dns = new DnsSettingsService($database->pdo(), $app->crypto(), $app->config);
    if (!$dns->configured()) {
        return null;
    }
    return new ManagedCreateProcessor(
        $database,
        $clients,
        $jobs,
        $app->audit(),
        $dns->client(),
        $provisioner,
        $placedCreate,
        $dns->forwardZone(),
        (string) $app->config->get('provisioning.vm_setup_command', '/root/vm-setup.sh'),
        (string) $app->config->get('provisioning.puppet_command', 'puppet agent --test'),
        (int) $app->config->get('provisioning.guest_agent_timeout', 300),
        (int) $app->config->get('provisioning.guest_command_timeout', 900),
        $terraformCreate,
    );
};

$queueSelectedAnsible = static function (array $final) use ($jobs): array {
    if (!in_array((string) ($final['type'] ?? ''), ['vm.create', 'vm.create.placed'], true)) return $final;
    if ((string) ($final['status'] ?? '') !== 'completed') return $final;
    $vmId = (int) ($final['virtual_machine_id'] ?? 0);
    if ($vmId <= 0) return $final;
    $payload = is_array($final['payload'] ?? null) ? $final['payload'] : [];
    $playbook = trim((string) ($payload['ansible_playbook'] ?? ''));
    if ($playbook === '') return $final;
    $result = is_array($final['result'] ?? null) ? $final['result'] : [];
    if (!empty($result['ansible_job_id'])) return $final;

    $ansibleJobId = $jobs->enqueue(
        'vm.ansible',
        $final['user_id'] === null ? null : (int) $final['user_id'],
        $final['project_id'] === null ? null : (int) $final['project_id'],
        $final['connection_id'] === null ? null : (int) $final['connection_id'],
        [
            'playbook' => $playbook,
            'cloud_init_user' => (string) ($payload['cloud_init_user'] ?? 'clouduser'),
        ],
        null,
        $vmId,
        3,
    );
    $result['ansible_job_id'] = $ansibleJobId;
    $result['ansible_playbook'] = $playbook;
    $jobs->complete((int) $final['id'], $result);
    return $jobs->find((string) $final['public_id']) ?? $final;
};

$heartbeat = new WorkerHeartbeatService($database->pdo(), (string) ($argv[0] ?? 'cloud-worker') . '@' . (gethostname() ?: 'unknown'), Application::VERSION);
$webhooks = new WebhookService($database->pdo(), $app->crypto());
$heartbeat->beat();

foreach ($jobs->staleRunning() as $staleJob) {
    if (!$jobs->acquireExecutionLock((string) $staleJob['public_id'])) continue;
    try {
        if ($ansibleProcessor->supports((string) $staleJob['type'])) {
            $jobs->requeueInterrupted((int) $staleJob['id'], 'Worker interrupted while running Ansible; the playbook job was returned to the retry queue.');
            continue;
        }
        $managedWithCreatedVm = ($staleJob['payload']['managed_provisioning'] ?? false) === true
            && !empty($staleJob['virtual_machine_id']);
        if ($managedWithCreatedVm) {
            $jobs->requeueInterrupted((int) $staleJob['id'], 'Worker interrupted after VM creation; managed provisioning will resume from the persisted VM.');
        } elseif ((string) $staleJob['type'] === 'vm.delete') {
            $jobs->requeueInterrupted((int) $staleJob['id'], 'Worker interrupted during VM deletion; fail-closed lifecycle deletion will resume idempotently.');
        } elseif ((string) $staleJob['type'] === 'vm.create.placed' || $advanced->supports((string) $staleJob['type']) || $identity->supports((string) $staleJob['type'])) {
            $jobs->fail((int) $staleJob['id'], 'Worker interrupted; operation was returned to the retry queue.');
        } else {
            $provisioner->recoverStale($staleJob);
        }
    } finally {
        $jobs->releaseExecutionLock((string) $staleJob['public_id']);
    }
}

$lastReconciliation = 0;
$reconcileFailed = static function () use ($jobs, $provisioner, $placedCreate, &$lastReconciliation): void {
    if (time() - $lastReconciliation < 60) return;
    $lastReconciliation = time();
    foreach ($jobs->retainedFailedCreates() as $failedJob) {
        if (!$jobs->acquireExecutionLock((string) $failedJob['public_id'])) continue;
        try {
            if ((string) $failedJob['type'] === 'vm.create.placed') {
                $placedCreate->reconcileFailedCreate($failedJob);
            } else {
                $provisioner->reconcileFailedCreate($failedJob);
            }
        } catch (Throwable $exception) {
            error_log('Failed to reconcile job ' . (string) $failedJob['public_id'] . ': ' . $exception->getMessage());
        } finally {
            $jobs->releaseExecutionLock((string) $failedJob['public_id']);
        }
    }
};
$reconcileFailed();
$once = in_array('--once', $argv, true);
$lastHeartbeat = time();
do {
    if (is_file($maintenancePath)) {
        fwrite(STDERR, "Portal maintenance mode became active; worker will not claim another job.\n");
        break;
    }
    if (time() - $lastHeartbeat >= 15) {
        $heartbeat->beat();
        $lastHeartbeat = time();
    }
    $job = $jobs->claimNext();
    if ($job === null) {
        $reconcileFailed();
        if ($once) break;
        sleep(2);
        continue;
    }
    if (!$jobs->acquireExecutionLock((string) $job['public_id'])) {
        error_log('Could not acquire execution lock for claimed job ' . (string) $job['public_id'] . '.');
        continue;
    }
    try {
        if ($ansibleProcessor->supports((string) $job['type'])) {
            $ansibleProcessor->process($job);
        } elseif ($deleteLifecycle->supports((string) $job['type'])) {
            $deleteLifecycle->process($job);
        } elseif (($job['payload']['managed_provisioning'] ?? false) === true) {
            $processor = null;
            $processorError = null;
            try {
                $processor = $managedProcessor();
            } catch (Throwable $exception) {
                $processorError = $exception;
                error_log('Managed DNS configuration could not be initialized: ' . $exception->getMessage());
            }
            if ($processorError instanceof Throwable) {
                $jobs->failPermanent((int) $job['id'], 'Managed DNS configuration is invalid or its secret cannot be decrypted. VM creation was not started.');
            } elseif (!$processor instanceof ManagedCreateProcessor) {
                $jobs->failPermanent((int) $job['id'], 'Managed provisioning requires an enabled and complete DNS configuration. VM creation was not started.');
            } else {
                $processor->process($job);
            }
        } elseif ((string) $job['type'] === 'vm.create.placed') {
            $placedCreate->process($job);
        } elseif ($identity->supports((string) $job['type'])) {
            $identity->process($job);
        } elseif ($advanced->supports((string) $job['type'])) {
            $advanced->process($job);
        } else {
            $provisioner->process($job);
        }
        $final = $jobs->find((string) $job['public_id']);
        if (is_array($final)) {
            $final = $queueSelectedAnsible($final);
            $status = (string) $final['status'];
            $event = match ($status) {
                'completed' => 'job.completed',
                'dead_letter' => 'job.dead_letter',
                'failed' => 'job.failed',
                'queued' => 'job.retrying',
                default => 'job.updated',
            };
            try {
                $payload = [
                    'job_id' => (string) $final['public_id'], 'type' => (string) $final['type'], 'status' => $status,
                    'attempts' => (int) $final['attempts'], 'max_attempts' => (int) $final['max_attempts'],
                    'project_id' => $final['project_id'] === null ? null : (int) $final['project_id'],
                    'virtual_machine_id' => $final['virtual_machine_id'] === null ? null : (int) $final['virtual_machine_id'],
                    'error' => $final['error_message'],
                ];
                $webhooks->publish($event, $payload);
                $webhooks->publish((string) $final['type'] . '.' . $status, $payload);
            } catch (Throwable $exception) {
                error_log('Webhook delivery failed: ' . $exception->getMessage());
            }
        }
        $heartbeat->beat((string) $job['public_id']);
    } finally {
        $jobs->releaseExecutionLock((string) $job['public_id']);
    }
} while (!$once);
