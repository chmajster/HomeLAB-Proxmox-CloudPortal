<?php

declare(strict_types=1);

namespace CloudPortal\Services\Auth;

use CloudPortal\Http\HttpException;
use CloudPortal\Services\Audit\AuditLogger;
use PDO;

final class AuthService
{
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        unset($_SESSION['_csrf']);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_version'] = (int) $user['session_version'];
        $_SESSION['authenticated_at'] = time();
        $this->pdo->prepare('UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $user['id']]);
        $this->audit->log((int) $user['id'], $ip, 'auth.login', 'success');
        unset($user['password_hash']);
        $this->user = $user;
        return $user;
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
        session_destroy();
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
            'SELECT u.id, u.role_id, u.username, u.email, u.status, u.locale, u.session_version, r.slug AS role_slug
             FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id LIMIT 1'
        );
        $statement->execute(['id' => (int) $id]);
        $user = $statement->fetch();
        if (!is_array($user) || $user['status'] !== 'active' || (int) ($user['session_version'] ?? 0) !== (int) ($_SESSION['session_version'] ?? 0)) {
            unset($_SESSION['user_id'], $_SESSION['session_version']);
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

    private static function dummyPasswordHash(): string
    {
        return self::$dummyPasswordHash ??= self::hashPassword('invalid-password-' . __CLASS__);
    }
}
