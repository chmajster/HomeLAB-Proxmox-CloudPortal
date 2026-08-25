<?php

declare(strict_types=1);

namespace CloudPortal\Services\Observability;

use CloudPortal\Database\MigrationService;
use CloudPortal\Services\Provisioning\JobRepository;
use PDO;

final class HealthService
{
    private const WORKER_ONLINE_SECONDS = 90;
    private const STUCK_JOB_SECONDS = 300;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $database = false;
        try {
            $database = (int) $this->pdo->query('SELECT 1')->fetchColumn() === 1;
        } catch (\Throwable) {
        }

        $schema = false;
        if ($database) {
            try {
                $statement = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=:version');
                $statement->execute(['version' => MigrationService::CURRENT_VERSION]);
                $schema = (bool) $statement->fetchColumn();
            } catch (\Throwable) {
            }
        }

        $jobs = $database ? (new JobRepository($this->pdo))->metrics() : ['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'dead_letter' => 0];
        $stuckRunning = 0;
        if ($database) {
            try {
                $statement = $this->pdo->prepare("SELECT COUNT(*) FROM jobs WHERE status='running' AND started_at IS NOT NULL AND started_at<:cutoff");
                $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', time() - self::STUCK_JOB_SECONDS)]);
                $stuckRunning = (int) $statement->fetchColumn();
            } catch (\Throwable) {
            }
        }
        $jobs['stuck_running'] = $stuckRunning;

        $worker = null;
        if ($database) {
            try {
                $worker = $this->pdo->query('SELECT worker_name, hostname, pid, version, processed_jobs, last_job_public_id, started_at, last_seen_at FROM worker_heartbeats ORDER BY last_seen_at DESC LIMIT 1')->fetch() ?: null;
            } catch (\Throwable) {
            }
        }
        $lastSeen = is_array($worker) ? strtotime((string) $worker['last_seen_at']) : false;
        $workerAge = $lastSeen === false ? null : max(0, time() - $lastSeen);
        $workerOnline = $workerAge !== null && $workerAge <= self::WORKER_ONLINE_SECONDS;
        $workerRequired = ((int) ($jobs['queued'] ?? 0) + (int) ($jobs['running'] ?? 0)) > 0;
        $workerHealthy = !$workerRequired || $workerOnline;
        $workerStatus = $workerOnline ? 'online' : ($worker === null ? 'never_seen' : 'offline');

        $proxmox = ['active' => 0, 'error' => 0, 'disabled' => 0];
        if ($database) {
            try {
                foreach ($this->pdo->query('SELECT status, COUNT(*) count FROM proxmox_connections GROUP BY status')->fetchAll() as $row) {
                    $status = (string) $row['status'];
                    if (isset($proxmox[$status])) {
                        $proxmox[$status] = (int) $row['count'];
                    }
                }
            } catch (\Throwable) {
            }
        }

        return [
            'ok' => $database,
            'ready' => $database && $schema && $workerHealthy,
            'database' => $database,
            'schema_current' => $schema,
            'jobs' => $jobs,
            'worker' => $worker,
            'worker_status' => $workerStatus,
            'worker_online' => $workerOnline,
            'worker_required' => $workerRequired,
            'worker_age_seconds' => $workerAge,
            'worker_healthy' => $workerHealthy,
            'worker_online_threshold_seconds' => self::WORKER_ONLINE_SECONDS,
            'stuck_job_threshold_seconds' => self::STUCK_JOB_SECONDS,
            'proxmox_connections' => $proxmox,
            'checked_at' => gmdate(DATE_ATOM),
        ];
    }
}
