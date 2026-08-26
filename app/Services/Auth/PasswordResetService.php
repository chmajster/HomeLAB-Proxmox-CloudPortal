<?php

declare(strict_types=1);

namespace CloudPortal\Services\Auth;

use PDO;

final class PasswordResetService
{
    private const TTL_SECONDS = 1800;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function request(string $identity, string $baseUrl, string $mailFrom): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT id,email FROM users WHERE (username=:identity OR email=:identity) AND status='active' LIMIT 1"
        );
        $statement->execute(['identity' => trim($identity)]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            return true;
        }

        $token = $this->issue((int) $user['id']);
        $url = rtrim($baseUrl, '/') . '/password-reset?token=' . rawurlencode($token);
        $subject = 'Cloud Portal password reset';
        $body = "A password reset was requested for your Cloud Portal account.\n\n"
            . "Reset link: {$url}\n\n"
            . "This link expires in 30 minutes and can only be used once.\n"
            . "If you did not request this reset, ignore this message.\n";
        $headers = 'From: ' . $this->safeMailFrom($mailFrom) . "\r\n"
            . 'Content-Type: text/plain; charset=UTF-8';
        $delivered = @mail((string) $user['email'], $subject, $body, $headers);
        if (!$delivered) {
            $this->pdo->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE token_hash=:hash AND used_at IS NULL')
                ->execute(['hash' => hash('sha256', $token)]);
        }
        return $delivered;
    }

    public function issue(int $userId): string
    {
        $this->pdo->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=:user AND used_at IS NULL')
            ->execute(['user' => $userId]);
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(:user,:hash,:expires)'
        )->execute([
            'user' => $userId,
            'hash' => hash('sha256', $token),
            'expires' => gmdate('Y-m-d H:i:s', time() + self::TTL_SECONDS),
        ]);
        return $token;
    }

    public function consume(string $token, string $newPassword): int
    {
        $token = trim($token);
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new \RuntimeException('Password reset token is invalid or expired.');
        }
        (new AccountSecurityService($this->pdo))->assertPasswordPolicy($newPassword);

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) $this->pdo->beginTransaction();
        else $this->pdo->exec('SAVEPOINT password_reset');
        try {
            $statement = $this->pdo->prepare(
                'SELECT id,user_id FROM password_reset_tokens
                 WHERE token_hash=:hash AND used_at IS NULL AND expires_at>CURRENT_TIMESTAMP LIMIT 1 FOR UPDATE'
            );
            $statement->execute(['hash' => hash('sha256', $token)]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new \RuntimeException('Password reset token is invalid or expired.');
            }
            $userId = (int) $row['user_id'];
            $this->pdo->prepare(
                'UPDATE users SET password_hash=:hash,session_version=session_version+1,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND status=\'active\''
            )->execute(['hash' => AuthService::hashPassword($newPassword), 'id' => $userId]);
            $this->pdo->prepare('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE user_id=:user AND used_at IS NULL')
                ->execute(['user' => $userId]);
            $this->pdo->prepare('UPDATE user_sessions SET revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP) WHERE user_id=:user AND revoked_at IS NULL')
                ->execute(['user' => $userId]);
            $this->pdo->prepare("UPDATE api_tokens SET status='revoked',revoked_at=COALESCE(revoked_at,CURRENT_TIMESTAMP) WHERE user_id=:user AND status='active'")
                ->execute(['user' => $userId]);
            if ($ownsTransaction) $this->pdo->commit();
            else $this->pdo->exec('RELEASE SAVEPOINT password_reset');
            return $userId;
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            elseif (!$ownsTransaction) $this->pdo->exec('ROLLBACK TO SAVEPOINT password_reset');
            throw $exception;
        }
    }

    private function safeMailFrom(string $mailFrom): string
    {
        $mailFrom = trim(str_replace(["\r", "\n"], '', $mailFrom));
        return filter_var($mailFrom, FILTER_VALIDATE_EMAIL) ? $mailFrom : 'no-reply@localhost';
    }
}
