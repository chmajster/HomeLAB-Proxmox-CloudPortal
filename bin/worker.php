#!/usr/bin/env php
<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Database\Database;
use CloudPortal\Services\Provisioning\JobRepository;
use CloudPortal\Services\Provisioning\ProxmoxProvisioner;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';
$app = new Application($root);
date_default_timezone_set((string) $app->config->get('app.timezone', 'UTC'));
if (!$app->installed()) {
    fwrite(STDERR, "Portal is not installed.\n");
    exit(1);
}
$database = new Database($app->config);
$jobs = new JobRepository($database->pdo());
$provisioner = new ProxmoxProvisioner(
    $database,
    new ProxmoxClientFactory($database->pdo(), $app->crypto()),
    $jobs,
    $app->audit(),
);
foreach ($jobs->staleRunning() as $staleJob) {
    if (!$jobs->acquireExecutionLock((string) $staleJob['public_id'])) {
        continue;
    }
    try {
        $provisioner->recoverStale($staleJob);
    } finally {
        $jobs->releaseExecutionLock((string) $staleJob['public_id']);
    }
}
$lastReconciliation = 0;
$reconcileFailed = static function () use ($jobs, $provisioner, &$lastReconciliation): void {
    if (time() - $lastReconciliation < 60) {
        return;
    }
    $lastReconciliation = time();
    foreach ($jobs->retainedFailedCreates() as $failedJob) {
        if (!$jobs->acquireExecutionLock((string) $failedJob['public_id'])) {
            continue;
        }
        try {
            $provisioner->reconcileFailedCreate($failedJob);
        } catch (Throwable $exception) {
            error_log('Failed to reconcile job ' . (string) $failedJob['public_id'] . ': ' . $exception->getMessage());
        } finally {
            $jobs->releaseExecutionLock((string) $failedJob['public_id']);
        }
    }
};
$reconcileFailed();
$once = in_array('--once', $argv, true);
do {
    $job = $jobs->claimNext();
    if ($job === null) {
        $reconcileFailed();
        if ($once) {
            break;
        }
        sleep(2);
        continue;
    }
    if (!$jobs->acquireExecutionLock((string) $job['public_id'])) {
        error_log('Could not acquire the execution lock for claimed job ' . (string) $job['public_id'] . '; leaving it for stale-job recovery.');
        continue;
    }
    try {
        $provisioner->process($job);
    } finally {
        $jobs->releaseExecutionLock((string) $job['public_id']);
    }
} while (!$once);
