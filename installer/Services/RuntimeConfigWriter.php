<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

use CloudPortal\Security\Crypto;

final class RuntimeConfigWriter
{
    public function __construct(private readonly string $root)
    {
    }

    /** @param array<string,mixed> $state */
    public function write(array $state): string
    {
        foreach (['install_id', 'database', 'portal', 'security'] as $key) {
            if (!isset($state[$key]) || ($key !== 'install_id' && !is_array($state[$key]))) throw new \RuntimeException('Installer state is incomplete for configuration generation.');
        }
        $config = [
            'app' => [
                'name' => $state['portal']['name'], 'env' => 'production', 'debug' => false,
                'url' => $state['portal']['url'], 'timezone' => $state['portal']['timezone'],
                'locale' => $state['portal']['locale'], 'key' => $state['security']['app_key'],
                'installed' => false, 'install_id' => $state['install_id'],
            ],
            'database' => [
                'host' => $state['database']['host'], 'port' => $state['database']['port'],
                'name' => $state['database']['name'], 'user' => $state['database']['user'],
                'password' => $state['database']['password'],
            ],
            'session' => ['name' => 'cloud_portal_session', 'lifetime' => $state['portal']['session_lifetime']],
            'security' => [
                'encryption_key' => $state['security']['encryption_key'], 'csrf_secret' => $state['security']['csrf_secret'],
                'login_attempts' => 5, 'login_window_seconds' => 900, 'lockout_seconds' => 900,
            ],
        ];

        $bootstrap = $state['bootstrap'] ?? [];
        if (is_array($bootstrap)) {
            $crypto = new Crypto((string) $state['security']['encryption_key']);
            $dns = $bootstrap['dns'] ?? [];
            if (is_array($dns) && $dns !== []) {
                $config['dns'] = [
                    'server_ip' => (string) ($dns['server_ip'] ?? ''),
                    'api_token_encrypted' => $crypto->encrypt((string) ($dns['api_token'] ?? '')),
                ];
            }
            $proxmoxCredentials = $bootstrap['proxmox_credentials'] ?? [];
            if (is_array($proxmoxCredentials) && $proxmoxCredentials !== []) {
                $password = (string) ($proxmoxCredentials['password'] ?? '');
                $config['proxmox_credentials'] = [
                    'login' => (string) ($proxmoxCredentials['login'] ?? ''),
                    'password_encrypted' => $password === '' ? '' : $crypto->encrypt($password),
                ];
            }
            $hostnameGenerator = $bootstrap['hostname_generator'] ?? [];
            if (is_array($hostnameGenerator) && isset($hostnameGenerator['pattern'])) {
                $config['hostname_generator'] = ['pattern' => (string) $hostnameGenerator['pattern']];
            }
        }

        $target = $this->path();
        if (is_file($target)) {
            $existing = require $target;
            if (is_array($existing) && hash_equals((string) ($existing['app']['install_id'] ?? ''), (string) $state['install_id'])) {
                $this->verify($state['install_id']);
                return $target;
            }
            throw new \RuntimeException('config/runtime.php already belongs to another installation. Remove it manually only after verifying the target database and backups.');
        }
        if (!is_writable(dirname($target))) throw new \RuntimeException('The config directory is not writable. Grant the web-server user write access to config and retry.');
        $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $handle = @fopen($temporary, 'x');
        if ($handle === false) throw new \RuntimeException('Cannot create a temporary runtime configuration in the config directory.');
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !SensitiveFilePermissions::protect($temporary)) throw new \RuntimeException('Cannot safely write runtime configuration.');
        } finally {
            fclose($handle);
        }
        if (!@link($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot finalize runtime configuration; another installer may have completed concurrently.');
        }
        @unlink($temporary);
        $this->verify((string) $state['install_id'], false);
        return $target;
    }

    public function activate(string $installId): void
    {
        $this->verify($installId);
        $target = $this->path();
        $config = require $target;
        if (($config['app']['installed'] ?? false) === true) return;
        $config['app']['installed'] = true;
        $temporary = $target . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
        $handle = @fopen($temporary, 'x');
        if ($handle === false) throw new \RuntimeException('Cannot prepare the final runtime configuration.');
        $success = false;
        try {
            $success = flock($handle, LOCK_EX) && fwrite($handle, $contents) === strlen($contents) && fflush($handle) && SensitiveFilePermissions::protect($temporary);
        } finally {
            fclose($handle);
        }
        if (!$success || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new \RuntimeException('Cannot activate the runtime configuration. Check config directory permissions and retry.');
        }
        clearstatcache(true, $target);
        if (function_exists('opcache_invalidate')) @opcache_invalidate($target, true);
        $this->verify($installId, true);
    }

    public function verify(string $installId, ?bool $installed = null): void
    {
        $target = $this->path();
        clearstatcache(true, $target);
        if (!is_file($target)) throw new \RuntimeException('Runtime configuration is missing. Return to the configuration step and retry.');
        if (!SensitiveFilePermissions::areSafe($target)) throw new \RuntimeException('Runtime configuration has unsafe POSIX permissions. Set config/runtime.php to 0600 and retry.');
        $config = require $target;
        if (!is_array($config) || ($installed !== null && ($config['app']['installed'] ?? null) !== $installed) || !hash_equals($installId, (string) ($config['app']['install_id'] ?? ''))) throw new \RuntimeException('Runtime configuration verification failed.');
        foreach (['key' => $config['app']['key'] ?? '', 'encryption_key' => $config['security']['encryption_key'] ?? '', 'csrf_secret' => $config['security']['csrf_secret'] ?? ''] as $name => $encoded) {
            $decoded = base64_decode((string) $encoded, true);
            if ($decoded === false || strlen($decoded) !== 32) throw new \RuntimeException("Runtime security key {$name} is invalid.");
        }

        $crypto = new Crypto((string) $config['security']['encryption_key']);
        foreach ([
            'dns.api_token_encrypted' => $config['dns']['api_token_encrypted'] ?? '',
            'proxmox_credentials.password_encrypted' => $config['proxmox_credentials']['password_encrypted'] ?? '',
        ] as $name => $encrypted) {
            if ($encrypted === '') continue;
            try {
                if ($crypto->decrypt((string) $encrypted) === '') throw new \RuntimeException('empty decrypted value');
            } catch (\Throwable $exception) {
                throw new \RuntimeException("Runtime secret {$name} is invalid.", 0, $exception);
            }
        }
    }

    public function path(): string
    {
        return $this->root . '/config/runtime.php';
    }
}
