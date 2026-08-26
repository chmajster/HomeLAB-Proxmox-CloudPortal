<?php

declare(strict_types=1);

namespace CloudPortal\Services\Audit;

use PDO;
use Throwable;

final class AuditLogger
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'secret', 'api_token_secret', 'authorization',
        'cookie', 'api_key', 'apikey', 'csrf', 'session',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $metadata */
    public function log(?int $userId, string $ip, string $action, string $result, ?string $resourceType = null, string|int|null $resourceId = null, array $metadata = []): void
    {
        $context = $this->context($resourceType, $resourceId, $metadata);
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs
             (correlation_id,user_id,project_id,virtual_machine_id,job_id,ip_address,action,resource_type,resource_id,proxmox_upid,result,metadata)
             VALUES (:correlation_id,:user_id,:project_id,:vm_id,:job_id,:ip,:action,:resource_type,:resource_id,:upid,:result,:metadata)'
        );
        $statement->execute([
            'correlation_id' => $context['correlation_id'],
            'user_id' => $userId,
            'project_id' => $context['project_id'],
            'vm_id' => $context['virtual_machine_id'],
            'job_id' => $context['job_id'],
            'ip' => substr($ip, 0, 45),
            'action' => substr($action, 0, 100),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId === null ? null : (string) $resourceId,
            'upid' => $context['proxmox_upid'] === null ? null : mb_substr((string) $context['proxmox_upid'], 0, 255),
            'result' => $result === 'success' ? 'success' : 'failure',
            'metadata' => $metadata === [] ? null : json_encode($this->redact($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array{project_id:?int,virtual_machine_id:?int,job_id:?int,proxmox_upid:?string,correlation_id:?string}
     */
    private function context(?string $resourceType, string|int|null $resourceId, array $metadata): array
    {
        $context = [
            'project_id' => $this->positiveInt($metadata['project_id'] ?? null),
            'virtual_machine_id' => $this->positiveInt($metadata['virtual_machine_id'] ?? $metadata['vm_id'] ?? null),
            'job_id' => $this->positiveInt($metadata['job_id'] ?? null),
            'proxmox_upid' => $this->stringOrNull($metadata['proxmox_upid'] ?? $metadata['upid'] ?? null),
            'correlation_id' => $this->correlationId($metadata['correlation_id'] ?? ($_SERVER['CLOUD_PORTAL_CORRELATION_ID'] ?? null)),
        ];
        try {
            $jobReference = null;
            if ($resourceType === 'job' && $resourceId !== null) $jobReference = (string) $resourceId;
            elseif (isset($metadata['job_id']) && !is_numeric($metadata['job_id'])) $jobReference = (string) $metadata['job_id'];
            elseif (isset($metadata['job_public_id'])) $jobReference = (string) $metadata['job_public_id'];

            if ($jobReference !== null && $jobReference !== '') {
                $statement = $this->pdo->prepare('SELECT id,project_id,virtual_machine_id,proxmox_upid,correlation_id FROM jobs WHERE public_id=:ref LIMIT 1');
                $statement->execute(['ref' => $jobReference]);
                $row = $statement->fetch();
                if (is_array($row)) $this->mergeJob($context, $row);
            } elseif ($context['job_id'] !== null) {
                $statement = $this->pdo->prepare('SELECT id,project_id,virtual_machine_id,proxmox_upid,correlation_id FROM jobs WHERE id=:id LIMIT 1');
                $statement->execute(['id' => $context['job_id']]);
                $row = $statement->fetch();
                if (is_array($row)) $this->mergeJob($context, $row);
            }

            if ($resourceType === 'virtual_machine' && $resourceId !== null && $context['virtual_machine_id'] === null) {
                $context['virtual_machine_id'] = $this->positiveInt($resourceId);
            }
            if ($context['virtual_machine_id'] !== null && $context['project_id'] === null) {
                $statement = $this->pdo->prepare('SELECT project_id FROM virtual_machines WHERE id=:id LIMIT 1');
                $statement->execute(['id' => $context['virtual_machine_id']]);
                $context['project_id'] = $this->positiveInt($statement->fetchColumn());
            }
            if ($resourceType === 'project' && $resourceId !== null && $context['project_id'] === null) {
                $context['project_id'] = $this->positiveInt($resourceId);
            }
        } catch (Throwable) {
            // Audit enrichment is best effort; failure to resolve a relation must
            // never hide the primary audit event.
        }
        return $context;
    }

    /** @param array{project_id:?int,virtual_machine_id:?int,job_id:?int,proxmox_upid:?string,correlation_id:?string} $context @param array<string,mixed> $row */
    private function mergeJob(array &$context, array $row): void
    {
        $context['job_id'] ??= $this->positiveInt($row['id'] ?? null);
        $context['project_id'] ??= $this->positiveInt($row['project_id'] ?? null);
        $context['virtual_machine_id'] ??= $this->positiveInt($row['virtual_machine_id'] ?? null);
        $context['proxmox_upid'] ??= $this->stringOrNull($row['proxmox_upid'] ?? null);
        $context['correlation_id'] ??= $this->correlationId($row['correlation_id'] ?? null);
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function correlationId(mixed $value): ?string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) === 1 ? $value : null;
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null) {
            $normalized = strtolower(str_replace(['-', '_'], '', $key));
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (str_contains($normalized, str_replace('_', '', $sensitive))) return '[REDACTED]';
            }
        }
        if (!is_array($value)) return is_string($value) ? mb_substr($value, 0, 1000) : $value;
        $clean = [];
        foreach ($value as $childKey => $childValue) {
            $clean[$childKey] = $this->redact($childValue, is_string($childKey) ? $childKey : null);
        }
        return $clean;
    }
}
