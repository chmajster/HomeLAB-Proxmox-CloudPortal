<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use PDO;

final class ProvisioningStateRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createReserved(string $jobPublicId, string $reservationKey, string $hostname, string $ipAddress): void
    {
        $job = $this->pdo->prepare('SELECT id FROM jobs WHERE public_id=:id LIMIT 1');
        $job->execute(['id' => $jobPublicId]);
        $jobId = (int) $job->fetchColumn();
        if ($jobId <= 0) {
            throw new \RuntimeException('Provisioning job was not found after enqueue.');
        }
        $statement = $this->pdo->prepare(
            "INSERT INTO vm_provisioning(job_id,reservation_key,hostname,ip_address,status,current_step,current_step_name)
             VALUES(:job,:reservation,:hostname,:ip,'RESERVED',3,'DB status = RESERVED')"
        );
        $statement->execute([
            'job' => $jobId,
            'reservation' => $reservationKey,
            'hostname' => $hostname,
            'ip' => $ipAddress,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->eventById($id, 1, 'Generate hostname', 'completed', $hostname);
        $this->eventById($id, 2, 'Reserve IP', 'completed', $ipAddress);
        $this->eventById($id, 3, 'DB status = RESERVED', 'completed', null);
    }

    /** @return array<string,mixed> */
    public function forJob(int $jobId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT vp.*,COALESCE(vp.virtual_machine_id,j.virtual_machine_id) AS virtual_machine_id
             FROM vm_provisioning vp JOIN jobs j ON j.id=vp.job_id WHERE vp.job_id=:job LIMIT 1'
        );
        $statement->execute(['job' => $jobId]);
        $state = $statement->fetch();
        if (!is_array($state)) {
            throw new \RuntimeException('Managed provisioning state is missing.');
        }
        return $state;
    }

    public function begin(int $jobId, int $step, string $name): void
    {
        $statement = $this->pdo->prepare('UPDATE vm_provisioning SET current_step=:step,current_step_name=:name,last_error=NULL WHERE job_id=:job');
        $statement->execute(['step' => $step, 'name' => $name, 'job' => $jobId]);
    }

    public function step(int $jobId, int $step, string $name, ?string $message = null): void
    {
        $this->begin($jobId, $step, $name);
        $this->event($jobId, $step, $name, 'completed', $message);
    }

    public function dns(int $jobId, string $fqdn, string $forwardZone, string $reverseZone, ?int $aRecordId = null, ?int $ptrRecordId = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE vm_provisioning SET fqdn=:fqdn,forward_zone=:forward,reverse_zone=:reverse,
             a_record_id=COALESCE(:a,a_record_id),ptr_record_id=COALESCE(:ptr,ptr_record_id) WHERE job_id=:job'
        );
        $statement->execute([
            'fqdn' => $fqdn,
            'forward' => $forwardZone,
            'reverse' => $reverseZone,
            'a' => $aRecordId,
            'ptr' => $ptrRecordId,
            'job' => $jobId,
        ]);
    }

    public function creating(int $jobId, int $vmId): void
    {
        $this->pdo->prepare(
            "UPDATE vm_provisioning SET virtual_machine_id=:vm,status='CREATING',current_step=8,current_step_name='DB status = CREATING',last_error=NULL WHERE job_id=:job"
        )->execute(['vm' => $vmId, 'job' => $jobId]);
        $this->event($jobId, 8, 'DB status = CREATING', 'completed', null);
    }

    public function ready(int $jobId): void
    {
        $this->pdo->prepare(
            "UPDATE vm_provisioning SET status='READY',current_step=12,current_step_name='READY',last_error=NULL,ready_at=CURRENT_TIMESTAMP WHERE job_id=:job"
        )->execute(['job' => $jobId]);
        $this->event($jobId, 12, 'READY', 'completed', null);
    }

    public function error(int $jobId, string $message): void
    {
        $safe = mb_substr($message, 0, 2000);
        $state = $this->forJob($jobId);
        $this->pdo->prepare("UPDATE vm_provisioning SET status='ERROR',last_error=:error WHERE job_id=:job")
            ->execute(['error' => $safe, 'job' => $jobId]);
        $this->event($jobId, (int) $state['current_step'], (string) $state['current_step_name'], 'failed', $safe);
    }

    public function event(int $jobId, int $step, string $name, string $result, ?string $message): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM vm_provisioning WHERE job_id=:job LIMIT 1');
        $statement->execute(['job' => $jobId]);
        $id = (int) $statement->fetchColumn();
        if ($id > 0) {
            $this->eventById($id, $step, $name, $result, $message);
        }
    }

    private function eventById(int $provisioningId, int $step, string $name, string $result, ?string $message): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO vm_provisioning_events(provisioning_id,step,step_name,result,message) VALUES(:provisioning,:step,:name,:result,:message)'
        );
        $statement->execute([
            'provisioning' => $provisioningId,
            'step' => $step,
            'name' => mb_substr($name, 0, 100),
            'result' => $result === 'failed' ? 'failed' : 'completed',
            'message' => $message === null ? null : mb_substr($message, 0, 1000),
        ]);
    }
}
