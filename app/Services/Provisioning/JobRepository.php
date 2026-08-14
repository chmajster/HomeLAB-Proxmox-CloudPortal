<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Support\Uuid;
use PDO;

final class JobRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(string $type, ?int $userId, ?int $projectId, ?int $connectionId, array $payload, ?string $reservationKey = null, ?int $vmId = null): string
    {
        $publicId = Uuid::v4();
        $statement = $this->pdo->prepare(
            'INSERT INTO jobs (public_id, type, user_id, project_id, virtual_machine_id, connection_id, reservation_key, payload)
             VALUES (:public_id, :type, :user, :project, :vm, :connection, :reservation, :payload)'
        );
        $statement->execute([
            'public_id' => $publicId,
            'type' => $type,
            'user' => $userId,
            'project' => $projectId,
            'vm' => $vmId,
            'connection' => $connectionId,
            'reservation' => $reservationKey,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return $publicId;
    }

    /** @return array<string,mixed>|null */
    public function claimNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->query(
                "SELECT * FROM jobs WHERE status = 'queued' AND available_at <= CURRENT_TIMESTAMP
                 ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $job = $statement->fetch();
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare(
                "UPDATE jobs SET status = 'running', started_at = CURRENT_TIMESTAMP, attempts = attempts + 1 WHERE id = :id"
            );
            $update->execute(['id' => $job['id']]);
            $this->pdo->commit();
            $job['status'] = 'running';
            $job['payload'] = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
            return $job;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function upid(int $jobId, string $upid): void
    {
        $this->pdo->prepare('UPDATE jobs SET proxmox_upid = :upid WHERE id = :id')->execute(['upid' => $upid, 'id' => $jobId]);
    }

    /** @param array<string,mixed> $payload */
    public function payload(int $jobId, array $payload): void
    {
        $this->pdo->prepare('UPDATE jobs SET payload = :payload WHERE id = :id')->execute([
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'id' => $jobId,
        ]);
    }

    /** @param array<string,mixed> $result */
    public function complete(int $jobId, array $result = []): void
    {
        $this->pdo->prepare(
            "UPDATE jobs SET status = 'completed', result = :result, error_message = NULL, finished_at = CURRENT_TIMESTAMP WHERE id = :id"
        )->execute([
            'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'id' => $jobId,
        ]);
    }

    public function fail(int $jobId, string $message): void
    {
        $this->pdo->prepare(
            "UPDATE jobs SET status = 'failed', error_message = :message, finished_at = CURRENT_TIMESTAMP WHERE id = :id"
        )->execute(['message' => mb_substr($message, 0, 2000), 'id' => $jobId]);
    }

    /** @return list<array<string,mixed>> */
    public function staleRunning(int $seconds = 7200): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM jobs WHERE status = 'running' AND started_at < :cutoff ORDER BY id"
        );
        $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', time() - $seconds)]);
        $jobs = $statement->fetchAll();
        foreach ($jobs as &$job) {
            $job['payload'] = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
        }
        return $jobs;
    }

    public function acquireExecutionLock(string $publicId): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => 'portal_job_' . $publicId]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function releaseExecutionLock(string $publicId): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute(['name' => 'portal_job_' . $publicId]);
    }

    /** @return list<array<string,mixed>> */
    public function retainedFailedCreates(): array
    {
        $statement = $this->pdo->query(
            "SELECT j.* FROM jobs j JOIN quota_reservations qr ON qr.reservation_key=j.reservation_key
             WHERE j.status='failed' AND j.type='vm.create' AND qr.retain_until_reconciled=1 ORDER BY j.id LIMIT 100"
        );
        $jobs = $statement->fetchAll();
        foreach ($jobs as &$job) {
            $job['payload'] = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
        }
        return $jobs;
    }

    public function markReconciled(int $jobId): void
    {
        $this->pdo->prepare("UPDATE jobs SET result=JSON_OBJECT('cleanup_reconciled',true,'reconciled_at',UTC_TIMESTAMP()) WHERE id=:id")
            ->execute(['id' => $jobId]);
    }
}
