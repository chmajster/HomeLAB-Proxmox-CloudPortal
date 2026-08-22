<?php

declare(strict_types=1);

namespace CloudPortal\Services\Observability;

use CloudPortal\Database\MigrationService;
use CloudPortal\Services\Provisioning\JobRepository;
use PDO;

final class HealthService
{
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
        $worker = null;
        if ($database) {
            try {
                $worker = $this->pdo->query('SELECT worker_name, hostname, version, processed_jobs, last_job_public_id, last_seen_at FROM worker_heartbeats ORDER BY last_seen_at DESC LIMIT 1')->fetch() ?: null;
            } catch (\Throwable) {
            }
        }
        $workerAge = is_array($worker) ? max(0, time() - strtotime((string) $worker['last_seen_at'])) : null;
        $workerRequired = ((int) ($jobs['queued'] ?? 0) + (int) ($jobs['running'] ?? 0)) > 0;
        $workerHealthy = !$workerRequired || ($workerAge !== null && $workerAge <= 90);
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
            'worker_age_seconds' => $workerAge,
            'worker_healthy' => $workerHealthy,
            'proxmox_connections' => $proxmox,
            'checked_at' => gmdate(DATE_ATOM),
        ];
    }
}
