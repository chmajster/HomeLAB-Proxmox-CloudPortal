<?php

declare(strict_types=1);

namespace CloudPortal\Services\Audit;

use PDO;

final class AuditLogger
{
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'token', 'secret', 'api_token_secret', 'authorization'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string,mixed> $metadata */
    public function log(?int $userId, string $ip, string $action, string $result, ?string $resourceType = null, string|int|null $resourceId = null, array $metadata = []): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, ip_address, action, resource_type, resource_id, result, metadata)
             VALUES (:user_id, :ip, :action, :resource_type, :resource_id, :result, :metadata)'
        );
        $statement->execute([
            'user_id' => $userId,
            'ip' => substr($ip, 0, 45),
            'action' => substr($action, 0, 100),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId === null ? null : (string) $resourceId,
            'result' => $result === 'success' ? 'success' : 'failure',
            'metadata' => $metadata === [] ? null : json_encode($this->redact($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null) {
            $normalized = strtolower(str_replace(['-', '_'], '', $key));
            foreach (self::SENSITIVE_KEYS as $sensitive) {
                if (str_contains($normalized, str_replace('_', '', $sensitive))) {
                    return '[REDACTED]';
                }
            }
        }
        if (!is_array($value)) {
            return is_string($value) ? mb_substr($value, 0, 1000) : $value;
        }
        $clean = [];
        foreach ($value as $childKey => $childValue) {
            $clean[$childKey] = $this->redact($childValue, is_string($childKey) ? $childKey : null);
        }
        return $clean;
    }
}
