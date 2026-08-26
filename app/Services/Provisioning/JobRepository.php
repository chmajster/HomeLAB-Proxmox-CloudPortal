<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Support\Uuid;
use PDO;

final class JobRepository
{
    private const RETRYABLE_TYPES = [
        'vm.start','vm.shutdown','vm.stop','vm.reboot','vm.suspend','vm.resume','vm.delete','vm.resize','vm.rename',
        'vm.snapshot.rollback','vm.clone','vm.reconfigure','vm.disk.attach','vm.disk.detach','vm.nic.upsert','vm.nic.delete',
        'vm.migrate','vm.backup','vm.restore','vm.create.placed','vm.ansible','ansible.inventory',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $payload */
    public function enqueue(string $type, ?int $userId, ?int $projectId, ?int $connectionId, array $payload, ?string $reservationKey = null, ?int $vmId = null, int $maxAttempts = 3): string
    {
        $publicId = Uuid::v4();
        $correlationId = $this->currentCorrelationId() ?? Uuid::v4();
        $statement = $this->pdo->prepare('INSERT INTO jobs (public_id,correlation_id,type,user_id,project_id,virtual_machine_id,connection_id,reservation_key,payload,max_attempts) VALUES (:public_id,:correlation_id,:type,:user,:project,:vm,:connection,:reservation,:payload,:max_attempts)');
        $statement->execute([
            'public_id' => $publicId, 'correlation_id' => $correlationId, 'type' => $type, 'user' => $userId, 'project' => $projectId, 'vm' => $vmId,
            'connection' => $connectionId, 'reservation' => $reservationKey,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'max_attempts' => max(1, min(20, $maxAttempts)),
        ]);
        return $publicId;
    }

    /** @return array<string,mixed>|null */
    public function claimNext(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->query("SELECT * FROM jobs WHERE status='queued' AND available_at<=CURRENT_TIMESTAMP ORDER BY available_at,id LIMIT 1 FOR UPDATE SKIP LOCKED");
            $job = $statement->fetch();
            if (!is_array($job)) {
                $this->pdo->commit();
                return null;
            }
            if ((int) $job['attempts'] >= (int) $job['max_attempts']) {
                $this->pdo->prepare("UPDATE jobs SET status='dead_letter',dead_letter_at=CURRENT_TIMESTAMP,finished_at=CURRENT_TIMESTAMP WHERE id=:id")->execute(['id' => $job['id']]);
                $this->pdo->commit();
                return null;
            }
            $this->pdo->prepare("UPDATE jobs SET status='running',started_at=CURRENT_TIMESTAMP,finished_at=NULL,attempts=attempts+1 WHERE id=:id")->execute(['id' => $job['id']]);
            $this->pdo->commit();
            $job['status'] = 'running';
            $job['attempts'] = (int) $job['attempts'] + 1;
            $job['payload'] = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
            $correlationId = $this->normalizeCorrelationId($job['correlation_id'] ?? null);
            if ($correlationId !== null) $_SERVER['CLOUD_PORTAL_CORRELATION_ID'] = $correlationId;
            return $job;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function upid(int $jobId, string $upid): void
    {
        $this->pdo->prepare('UPDATE jobs SET proxmox_upid=:upid WHERE id=:id')->execute(['upid' => $upid, 'id' => $jobId]);
    }

    /** @param array<string,mixed> $payload */
    public function payload(int $jobId, array $payload): void
    {
        $this->pdo->prepare('UPDATE jobs SET payload=:payload WHERE id=:id')->execute(['payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'id' => $jobId]);
    }

    /** @param array<string,mixed> $result */
    public function complete(int $jobId, array $result = []): void
    {
        $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $managedStatus = $this->managedProvisioningStatus($jobId);
        if ($managedStatus !== null && $managedStatus !== 'READY') {
            $vmId = isset($result['virtual_machine_id']) ? (int) $result['virtual_machine_id'] : 0;
            $this->pdo->prepare(
                "UPDATE jobs SET status='running',result=:result,error_message=NULL,finished_at=NULL,dead_letter_at=NULL,
                 virtual_machine_id=COALESCE(NULLIF(:vm,0),virtual_machine_id) WHERE id=:id"
            )->execute(['result' => $encoded, 'vm' => $vmId, 'id' => $jobId]);
            if ($vmId > 0) {
                $this->pdo->prepare('UPDATE vm_provisioning SET virtual_machine_id=:vm WHERE job_id=:job AND virtual_machine_id IS NULL')
                    ->execute(['vm' => $vmId, 'job' => $jobId]);
            }
            return;
        }
        $this->pdo->prepare("UPDATE jobs SET status='completed',result=:result,error_message=NULL,finished_at=CURRENT_TIMESTAMP,dead_letter_at=NULL WHERE id=:id")
            ->execute(['result' => $encoded, 'id' => $jobId]);
    }

    public function requeueInterrupted(int $jobId, string $message): void
    {
        $this->pdo->prepare(
            "UPDATE jobs SET status='queued',error_message=:message,available_at=CURRENT_TIMESTAMP,started_at=NULL,finished_at=NULL
             WHERE id=:id AND status='running'"
        )->execute(['message' => mb_substr($message, 0, 2000), 'id' => $jobId]);
    }

    public function fail(int $jobId, string $message): void
    {
        $statement = $this->pdo->prepare('SELECT type,attempts,max_attempts FROM jobs WHERE id=:id FOR UPDATE');
        $statement->execute(['id' => $jobId]);
        $job = $statement->fetch();
        if (!is_array($job)) return;
        $attempts = (int) $job['attempts'];
        $maxAttempts = max(1, (int) $job['max_attempts']);
        $safeMessage = mb_substr($message, 0, 2000);
        if (!in_array((string) $job['type'], self::RETRYABLE_TYPES, true)) {
            $this->failPermanent($jobId, $safeMessage);
            return;
        }
        if ($attempts < $maxAttempts) {
            $delay = min(3600, 30 * (2 ** max(0, $attempts - 1))) + random_int(0, 15);
            $this->pdo->prepare("UPDATE jobs SET status='queued',error_message=:message,available_at=:available,finished_at=NULL WHERE id=:id")
                ->execute(['message' => $safeMessage, 'available' => gmdate('Y-m-d H:i:s', time() + $delay), 'id' => $jobId]);
            return;
        }
        $this->pdo->prepare("UPDATE jobs SET status='dead_letter',error_message=:message,finished_at=CURRENT_TIMESTAMP,dead_letter_at=CURRENT_TIMESTAMP WHERE id=:id")
            ->execute(['message' => $safeMessage, 'id' => $jobId]);
    }

    public function failPermanent(int $jobId, string $message): void
    {
        $this->pdo->prepare("UPDATE jobs SET status='failed',error_message=:message,finished_at=CURRENT_TIMESTAMP WHERE id=:id")
            ->execute(['message' => mb_substr($message, 0, 2000), 'id' => $jobId]);
    }

    public function manualRetry(string $publicId): bool
    {
        $statement = $this->pdo->prepare("UPDATE jobs SET status='queued',attempts=0,error_message=NULL,result=NULL,proxmox_upid=NULL,available_at=CURRENT_TIMESTAMP,started_at=NULL,finished_at=NULL,dead_letter_at=NULL WHERE public_id=:id AND status IN ('failed','dead_letter')");
        $statement->execute(['id' => $publicId]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    public function find(string $publicId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM jobs WHERE public_id=:id LIMIT 1');
        $statement->execute(['id' => $publicId]);
        $job = $statement->fetch();
        if (!is_array($job)) return null;
        $job['payload'] = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
        if (is_string($job['result'] ?? null)) $job['result'] = json_decode((string) $job['result'], true, 64);
        return $job;
    }

    /** @return array<string,int> */
    public function metrics(): array
    {
        $rows = $this->pdo->query('SELECT status,COUNT(*) AS count FROM jobs GROUP BY status')->fetchAll();
        $result = ['queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'dead_letter' => 0];
        foreach ($rows as $row) {
            if (isset($result[(string) $row['status']])) $result[(string) $row['status']] = (int) $row['count'];
        }
        return $result;
    }

    /** @return list<array<string,mixed>> */
    public function staleRunning(int $seconds = 7200): array
    {
        $statement = $this->pdo->prepare("SELECT * FROM jobs WHERE status='running' AND started_at<:cutoff ORDER BY id");
        $statement->execute(['cutoff' => gmdate('Y-m-d H:i:s', time() - $seconds)]);
        $jobs = $statement->fetchAll();
        foreach ($jobs as &$job) $job['payload'] = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
        return $jobs;
    }

    public function acquireExecutionLock(string $publicId): bool
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name,0)');
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
        $statement = $this->pdo->query("SELECT j.* FROM jobs j JOIN quota_reservations qr ON qr.reservation_key=j.reservation_key WHERE j.status IN ('failed','dead_letter') AND j.type IN ('vm.create','vm.create.placed') AND qr.retain_until_reconciled=1 ORDER BY j.id LIMIT 100");
        $jobs = $statement->fetchAll();
        foreach ($jobs as &$job) $job['payload'] = json_decode((string) $job['payload'], true, 64, JSON_THROW_ON_ERROR);
        return $jobs;
    }

    public function markReconciled(int $jobId): void
    {
        $this->pdo->prepare("UPDATE jobs SET result=JSON_OBJECT('cleanup_reconciled',true,'reconciled_at',UTC_TIMESTAMP()) WHERE id=:id")->execute(['id' => $jobId]);
    }

    private function managedProvisioningStatus(int $jobId): ?string
    {
        $statement = $this->pdo->prepare(
            "SELECT vp.status FROM jobs j
             JOIN vm_provisioning vp ON vp.job_id=j.id
             WHERE j.id=:id AND JSON_UNQUOTE(JSON_EXTRACT(j.payload,'$.managed_provisioning'))='true' LIMIT 1"
        );
        $statement->execute(['id' => $jobId]);
        $status = $statement->fetchColumn();
        return is_string($status) ? $status : null;
    }

    private function currentCorrelationId(): ?string
    {
        return $this->normalizeCorrelationId($_SERVER['CLOUD_PORTAL_CORRELATION_ID'] ?? null);
    }

    private function normalizeCorrelationId(mixed $value): ?string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1 ? $value : null;
    }
}
