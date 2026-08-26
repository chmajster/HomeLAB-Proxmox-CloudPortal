<?php

declare(strict_types=1);

namespace CloudPortal\Services\Auth;

use PDO;

final class SessionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function register(int $userId, string $ip, string $userAgent, int $lifetimeSeconds): void
    {
        $hash = $this->currentHash();
        if ($hash === null) return;
        $expires = gmdate('Y-m-d H:i:s', time() + max(300, $lifetimeSeconds));
        $statement = $this->pdo->prepare(
            'INSERT INTO user_sessions(user_id,session_id_hash,ip_address,user_agent,last_seen_at,expires_at)
             VALUES(:user,:hash,:ip,:agent,CURRENT_TIMESTAMP,:expires)
             ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),ip_address=VALUES(ip_address),user_agent=VALUES(user_agent),
                                     last_seen_at=CURRENT_TIMESTAMP,expires_at=VALUES(expires_at),revoked_at=NULL'
        );
        $statement->execute([
            'user' => $userId,
            'hash' => $hash,
            'ip' => substr($ip, 0, 45),
            'agent' => mb_substr($userAgent, 0, 500),
            'expires' => $expires,
        ]);
    }

    public function touch(int $userId, int $lifetimeSeconds): bool
    {
        $hash = $this->currentHash();
        if ($hash === null) return false;
        $expires = gmdate('Y-m-d H:i:s', time() + max(300, $lifetimeSeconds));
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions SET last_seen_at=CURRENT_TIMESTAMP,expires_at=:expires
             WHERE user_id=:user AND session_id_hash=:hash AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at>CURRENT_TIMESTAMP)'
        );
        $statement->execute(['expires' => $expires, 'user' => $userId, 'hash' => $hash]);
        return $statement->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        $current = $this->currentHash();
        $statement = $this->pdo->prepare(
            'SELECT id,ip_address,user_agent,created_at,last_seen_at,expires_at,revoked_at,session_id_hash
             FROM user_sessions WHERE user_id=:user ORDER BY last_seen_at DESC,id DESC LIMIT 100'
        );
        $statement->execute(['user' => $userId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['current'] = $current !== null && hash_equals($current, (string) $row['session_id_hash']);
            $row['active'] = $row['revoked_at'] === null && ($row['expires_at'] === null || strtotime((string) $row['expires_at']) > time());
            unset($row['session_id_hash']);
        }
        return $rows;
    }

    public function revoke(int $userId, int $sessionId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP)
             WHERE id=:id AND user_id=:user AND revoked_at IS NULL'
        );
        $statement->execute(['id' => $sessionId, 'user' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function revokeCurrent(int $userId): void
    {
        $hash = $this->currentHash();
        if ($hash === null) return;
        $this->pdo->prepare('UPDATE user_sessions SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP) WHERE user_id=:user AND session_id_hash=:hash')
            ->execute(['user' => $userId, 'hash' => $hash]);
    }

    public function revokeOthers(int $userId): int
    {
        $hash = $this->currentHash();
        if ($hash === null) return 0;
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP)
             WHERE user_id=:user AND session_id_hash<>:hash AND revoked_at IS NULL'
        );
        $statement->execute(['user' => $userId, 'hash' => $hash]);
        return $statement->rowCount();
    }

    public function revokeAll(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE user_sessions SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP) WHERE user_id=:user AND revoked_at IS NULL'
        );
        $statement->execute(['user' => $userId]);
        return $statement->rowCount();
    }

    private function currentHash(): ?string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return null;
        $id = session_id();
        return $id === '' ? null : hash('sha256', $id);
    }
}
