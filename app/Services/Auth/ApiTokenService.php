<?php

declare(strict_types=1);

namespace CloudPortal\Services\Auth;

use PDO;

final class ApiTokenService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<string> $scopes @return array{token:string,id:int,name:string,prefix:string,scopes:list<string>,expires_at:?string} */
    public function create(int $userId, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('API token name must contain between 1 and 100 characters.');
        }
        $scopes = $this->normalizeScopes($scopes);
        if ($scopes === []) {
            throw new \InvalidArgumentException('At least one API token scope is required.');
        }
        if ($expiresAt !== null) {
            $timestamp = strtotime($expiresAt);
            if ($timestamp === false || $timestamp <= time()) {
                throw new \InvalidArgumentException('API token expiry must be in the future.');
            }
            $expiresAt = gmdate('Y-m-d H:i:s', $timestamp);
        }

        $prefix = 'cp_' . bin2hex(random_bytes(6));
        $token = $prefix . '_' . bin2hex(random_bytes(32));
        $statement = $this->pdo->prepare(
            'INSERT INTO api_tokens(user_id,name,token_prefix,token_hash,scopes,expires_at)
             VALUES(:user,:name,:prefix,:hash,:scopes,:expires)'
        );
        $statement->execute([
            'user' => $userId,
            'name' => $name,
            'prefix' => $prefix,
            'hash' => hash('sha256', $token),
            'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
            'expires' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'id' => (int) $this->pdo->lastInsertId(),
            'name' => $name,
            'prefix' => $prefix,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array<string,mixed>|null */
    public function authenticate(string $token, string $ip): ?array
    {
        $token = trim($token);
        if (!preg_match('/^cp_[a-f0-9]{12}_[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $statement = $this->pdo->prepare(
            "SELECT t.id AS api_token_id,t.scopes,t.expires_at,u.id,u.role_id,u.username,u.email,u.status,u.locale,
                    u.session_version,u.mfa_enabled,r.slug AS role_slug
             FROM api_tokens t
             JOIN users u ON u.id=t.user_id
             JOIN roles r ON r.id=u.role_id
             WHERE t.token_hash=:hash AND t.status='active'
               AND (t.expires_at IS NULL OR t.expires_at>CURRENT_TIMESTAMP)
             LIMIT 1"
        );
        $statement->execute(['hash' => hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!is_array($row) || (string) $row['status'] !== 'active') {
            return null;
        }
        $scopes = json_decode((string) $row['scopes'], true);
        if (!is_array($scopes)) {
            return null;
        }
        $row['api_token_scopes'] = $this->normalizeScopes(array_map('strval', $scopes));
        unset($row['scopes']);
        $this->pdo->prepare('UPDATE api_tokens SET last_used_at=CURRENT_TIMESTAMP,last_used_ip=:ip WHERE id=:id')
            ->execute(['ip' => substr($ip, 0, 45), 'id' => $row['api_token_id']]);
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,name,token_prefix,scopes,status,expires_at,last_used_at,last_used_ip,created_at,revoked_at
             FROM api_tokens WHERE user_id=:user ORDER BY id DESC'
        );
        $statement->execute(['user' => $userId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['scopes'], true);
            $row['scopes'] = is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }
        return $rows;
    }

    public function revoke(int $userId, int $tokenId): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE api_tokens SET status='revoked',revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP)
             WHERE id=:id AND user_id=:user AND status='active'"
        );
        $statement->execute(['id' => $tokenId, 'user' => $userId]);
        return $statement->rowCount() === 1;
    }

    /** @return list<string> */
    public function allowedScopesForUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.name FROM users u
             JOIN role_permissions rp ON rp.role_id=u.role_id
             JOIN permissions p ON p.id=rp.permission_id
             WHERE u.id=:user AND u.status=\'active\' ORDER BY p.name'
        );
        $statement->execute(['user' => $userId]);
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param list<string> $scopes @return list<string> */
    private function normalizeScopes(array $scopes): array
    {
        $clean = [];
        foreach ($scopes as $scope) {
            $scope = trim($scope);
            if ($scope === '' || preg_match('/^[a-z][a-z0-9_.:-]{0,99}$/', $scope) !== 1) {
                continue;
            }
            $clean[$scope] = true;
        }
        return array_keys($clean);
    }
}
