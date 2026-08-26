<?php

declare(strict_types=1);

namespace CloudPortal\Services\Auth;

use PDO;

final class AccountSecurityService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id=:id AND status=\'active\' LIMIT 1');
        $statement->execute(['id' => $userId]);
        $hash = $statement->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        if (!$this->verifyPassword($userId, $currentPassword)) {
            throw new \RuntimeException('Current password is invalid.');
        }
        $this->assertPasswordPolicy($newPassword);
        if (hash_equals($currentPassword, $newPassword)) {
            throw new \RuntimeException('New password must differ from the current password.');
        }
        $this->pdo->prepare(
            'UPDATE users SET password_hash=:hash,session_version=session_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id'
        )->execute(['hash' => AuthService::hashPassword($newPassword), 'id' => $userId]);
    }

    public function assertPasswordPolicy(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 4096) {
            throw new \RuntimeException('Password must contain at least 12 characters.');
        }
        $classes = 0;
        $classes += preg_match('/[a-z]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[A-Z]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/\d/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[^A-Za-z0-9]/', $password) === 1 ? 1 : 0;
        if ($classes < 3) {
            throw new \RuntimeException('Password must use at least three character classes: lowercase, uppercase, digits, symbols.');
        }
    }
}
