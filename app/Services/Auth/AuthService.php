<?php

declare(strict_types=1);

namespace CloudPortal\Services\Auth;

use CloudPortal\Http\HttpException;
use CloudPortal\Services\Audit\AuditLogger;
use PDO;

final class AuthService
{
    private const MFA_CHALLENGE_SECONDS = 300;

    private static ?string $dummyPasswordHash = null;
    /** @var array<string,mixed>|null */
    private ?array $user = null;
    /** @var list<string>|null */
    private ?array $permissions = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly RateLimiter $rateLimiter,
        private readonly AuditLogger $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function login(string $identity, string $password, string $ip): array
    {
        $locks = $this->rateLimiter->acquire($identity, $ip);
        try {
            $this->rateLimiter->ensureAllowed($identity, $ip);
            $statement = $this->pdo->prepare(
                'SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id = u.role_id
                 WHERE u.username = :username OR u.email = :email LIMIT 1'
            );
            $statement->execute(['username' => trim($identity), 'email' => trim($identity)]);
            $user = $statement->fetch();
            $passwordValid = password_verify($password, is_array($user) ? (string) $user['password_hash'] : self::dummyPasswordHash());
            $valid = is_array($user) && $user['status'] === 'active' && $passwordValid;
            $this->rateLimiter->record($identity, $ip, $valid);
        } catch (HttpException $exception) {
            $this->audit->log(null, $ip, 'auth.login', 'failure', null, null, ['reason' => 'rate_limited']);
            throw $exception;
        } finally {
            $this->rateLimiter->release($locks);
        }
        if (!$valid) {
            $this->audit->log(is_array($user) ? (int) $user['id'] : null, $ip, 'auth.login', 'failure');
            throw new HttpException(401, 'Invalid credentials or blocked account.');
        }

        if ((int) ($user['mfa_enabled'] ?? 0) === 1) {
            $this->clearAuthenticatedSession();
            $_SESSION['mfa_pending_user_id'] = (int) $user['id'];
            $_SESSION['mfa_pending_session_version'] = (int) $user['session_version'];
            $_SESSION['mfa_pending_at'] = time();
            $_SESSION['mfa_attempts'] = 0;
            $this->audit->log((int) $user['id'], $ip, 'auth.login.primary', 'success', null, null, ['mfa_required' => true]);
            $safe = $this->safeUser($user);
            $safe['mfa_required'] = true;
            return $safe;
        }

        return $this->establishSession($user, $ip, false);
    }

    /** @return array<string,mixed>|null */
    public function pendingMfaUser(): ?array
    {
        $userId = $_SESSION['mfa_pending_user_id'] ?? null;
        $started = $_SESSION['mfa_pending_at'] ?? null;
        $sessionVersion = $_SESSION['mfa_pending_session_version'] ?? null;
        if ((!is_int($userId) && !ctype_digit((string) $userId))
            || (!is_int($started) && !ctype_digit((string) $started))
            || time() - (int) $started > self::MFA_CHALLENGE_SECONDS) {
            $this->clearMfaChallenge();
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT u.id,u.role_id,u.username,u.email,u.status,u.locale,u.session_version,u.mfa_enabled,r.slug AS role_slug
             FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id LIMIT 1'
        );
        $statement->execute(['id' => (int) $userId]);
        $user = $statement->fetch();
        if (!is_array($user)
            || (string) $user['status'] !== 'active'
            || (int) $user['mfa_enabled'] !== 1
            || (int) $user['session_version'] !== (int) $sessionVersion) {
            $this->clearMfaChallenge();
            return null;
        }
        return $user;
    }

    /** @return array<string,mixed> */
    public function completeMfa(string $ip): array
    {
        $pending = $this->pendingMfaUser();
        if ($pending === null) {
            throw new HttpException(401, 'MFA challenge has expired. Sign in again.');
        }
        $this->rateLimiter->clearFailuresForIdentity($this->mfaIdentity((int) $pending['id']));
        $this->clearMfaChallenge();
        return $this->establishSession($pending, $ip, true);
    }

    public function recordMfaFailure(string $ip): void
    {
        $userId = $_SESSION['mfa_pending_user_id'] ?? null;
        if (!is_numeric($userId)) {
            $this->clearMfaChallenge();
            throw new HttpException(401, 'MFA challenge has expired. Sign in again.');
        }

        $identity = $this->mfaIdentity((int) $userId);
        $locks = $this->rateLimiter->acquire($identity, $ip);
        try {
            $this->rateLimiter->ensureAllowed($identity, $ip);
            $this->rateLimiter->record($identity, $ip, false);
        } catch (HttpException $exception) {
            $this->clearMfaChallenge();
            $this->audit->log((int) $userId, $ip, 'auth.mfa', 'failure', null, null, ['reason' => 'rate_limited']);
            throw new HttpException(429, 'Too many invalid MFA attempts. Sign in again.');
        } finally {
            $this->rateLimiter->release($locks);
        }

        $attempts = (int) ($_SESSION['mfa_attempts'] ?? 0) + 1;
        $_SESSION['mfa_attempts'] = $attempts;
        $this->audit->log((int) $userId, $ip, 'auth.mfa', 'failure', null, null, ['attempts' => $attempts]);
        if ($attempts >= 8) {
            $this->clearMfaChallenge();
            throw new HttpException(429, 'Too many invalid MFA attempts. Sign in again.');
        }
    }

    public function logout(string $ip): void
    {
        $userId = $this->id();
        if ($userId !== null) {
            $this->audit->log($userId, $ip, 'auth.logout', 'success');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $this->user = null;
        $this->permissions = null;
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        if ($this->user !== null) {
            return $this->user;
        }
        $id = $_SESSION['user_id'] ?? null;
        if (!is_int($id) && !ctype_digit((string) $id)) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.role_id, u.username, u.email, u.status, u.locale, u.session_version, u.mfa_enabled, r.slug AS role_slug
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id LIMIT 1'
        );
        $statement->execute(['id' => (int) $id]);
        $user = $statement->fetch();
        if (!is_array($user) || $user['status'] !== 'active' || (int) ($user['session_version'] ?? 0) !== (int) ($_SESSION['session_version'] ?? 0)) {
            unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['authenticated_at'], $_SESSION['mfa_authenticated_at']);
            return null;
        }
        return $this->user = $user;
    }

    public function id(): ?int
    {
        return ($user = $this->user()) === null ? null : (int) $user['id'];
    }

    /** @return array<string,mixed> */
    public function requireUser(): array
    {
        return $this->user() ?? throw new HttpException(401, 'Authentication required.');
    }

    public function isAdmin(): bool
    {
        return ($this->user()['role_slug'] ?? null) === 'admin';
    }

    public function can(string $permission): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        if ($this->permissions === null) {
            $statement = $this->pdo->prepare(
                'SELECT p.name FROM permissions p JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = :role_id'
            );
            $statement->execute(['role_id' => $user['role_id']]);
            $this->permissions = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        }
        return in_array($permission, $this->permissions, true);
    }

    public function requirePermission(string $permission): void
    {
        $this->requireUser();
        if (!$this->can($permission)) {
            throw new HttpException(403, 'Permission denied.');
        }
    }

    public static function hashPassword(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        return $hash ?: throw new \RuntimeException('Password hashing failed.');
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function establishSession(array $user, string $ip, bool $mfa): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
        $this->clearMfaChallenge();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_version'] = (int) $user['session_version'];
        $_SESSION['authenticated_at'] = time();
        if ($mfa) {
            $_SESSION['mfa_authenticated_at'] = time();
        }
        $this->pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $user['id']]);
        $this->audit->log((int) $user['id'], $ip, $mfa ? 'auth.mfa' : 'auth.login', 'success');
        $safe = $this->safeUser($user);
        $safe['mfa_required'] = false;
        $this->user = $safe;
        return $safe;
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function safeUser(array $user): array
    {
        unset($user['password_hash'], $user['mfa_secret_encrypted']);
        return $user;
    }

    private function clearAuthenticatedSession(): void
    {
        unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['authenticated_at'], $_SESSION['mfa_authenticated_at']);
        $this->user = null;
        $this->permissions = null;
    }

    private function clearMfaChallenge(): void
    {
        unset($_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending_session_version'], $_SESSION['mfa_pending_at'], $_SESSION['mfa_attempts']);
    }

    private function mfaIdentity(int $userId): string
    {
        return 'mfa:user:' . $userId;
    }

    private static function dummyPasswordHash(): string
    {
        return self::$dummyPasswordHash ??= self::hashPassword('invalid-password-' . __CLASS__);
    }
}
