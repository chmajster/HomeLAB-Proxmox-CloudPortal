<?php

declare(strict_types=1);

namespace CloudPortal;

use CloudPortal\Database\Database;
use CloudPortal\Security\Crypto;
use CloudPortal\Security\Csrf;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\Auth\AuthService;
use CloudPortal\Services\Auth\RateLimiter;
use CloudPortal\Support\Config;
use CloudPortal\Support\View;
use PDO;

final class Application
{
    public const VERSION = '1.3.0';
    private ?PDO $pdo = null;
    private ?AuthService $auth = null;
    private ?AuditLogger $audit = null;
    private ?Crypto $crypto = null;
    /** @var array<string,mixed> */
    private array $settings = [];

    public readonly Config $config;
    public readonly Csrf $csrf;
    public readonly View $view;

    public function __construct(public readonly string $root)
    {
        $this->config = Config::load($root);
        $this->csrf = new Csrf();
        $this->view = new View($root . '/resources/views', ['basePath' => $this->basePath()]);
    }

    public function basePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/.');
        return $directory === '' || $directory === '/' ? '' : '/' . ltrim($directory, '/');
    }

    public function url(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');
        return $this->basePath() . $path;
    }

    public function installed(): bool
    {
        $runtime = $this->root . '/config/runtime.php';
        $lock = $this->root . '/storage/installed.lock';
        if (!is_file($runtime) || !is_file($lock) || $this->config->get('app.installed') !== true) {
            return false;
        }
        $installId = (string) $this->config->get('app.install_id', '');
        $lockData = json_decode((string) @file_get_contents($lock), true);
        return $installId !== '' && is_array($lockData) && hash_equals($installId, (string) ($lockData['install_id'] ?? ''));
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= (new Database($this->config))->pdo();
    }

    public function audit(): AuditLogger
    {
        return $this->audit ??= new AuditLogger($this->pdo());
    }

    public function auth(): AuthService
    {
        return $this->auth ??= new AuthService(
            $this->pdo(),
            new RateLimiter(
                $this->pdo(),
                (int) $this->config->get('security.login_attempts', 5),
                (int) $this->config->get('security.login_window_seconds', 900),
                (int) $this->config->get('security.lockout_seconds', 900),
            ),
            $this->audit(),
        );
    }

    public function crypto(): Crypto
    {
        return $this->crypto ??= new Crypto((string) $this->config->get('security.encryption_key', $this->config->get('app.key')));
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        if (!$this->installed()) {
            return $default;
        }
        if (array_key_exists($key, $this->settings)) {
            return $this->settings[$key];
        }
        $statement = $this->pdo()->prepare('SELECT value FROM settings WHERE setting_key = :key');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            return $this->settings[$key] = $default;
        }
        try {
            return $this->settings[$key] = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->settings[$key] = $default;
        }
    }
}
