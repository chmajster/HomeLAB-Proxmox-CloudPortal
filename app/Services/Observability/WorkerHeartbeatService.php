<?php

declare(strict_types=1);

namespace CloudPortal\Services\Observability;

use PDO;

final class WorkerHeartbeatService
{
    private int $processed = 0;

    public function __construct(private readonly PDO $pdo, private readonly string $workerName, private readonly string $version)
    {
    }

    public function beat(?string $lastJobPublicId = null): void
    {
        if ($lastJobPublicId !== null) {
            ++$this->processed;
        }
        $statement = $this->pdo->prepare(
            "INSERT INTO worker_heartbeats (worker_name, hostname, pid, version, processed_jobs, last_job_public_id, started_at, last_seen_at)
             VALUES (:name, :host, :pid, :version, :processed, :job, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE hostname=VALUES(hostname), pid=VALUES(pid), version=VALUES(version),
               processed_jobs=processed_jobs + IF(VALUES(last_job_public_id) IS NULL,0,1),
               last_job_public_id=COALESCE(VALUES(last_job_public_id), last_job_public_id), last_seen_at=CURRENT_TIMESTAMP"
        );
        $statement->execute([
            'name' => $this->workerName,
            'host' => gethostname() ?: 'unknown',
            'pid' => getmypid() ?: 0,
            'version' => $this->version,
            'processed' => $this->processed,
            'job' => $lastJobPublicId,
        ]);
    }
}
