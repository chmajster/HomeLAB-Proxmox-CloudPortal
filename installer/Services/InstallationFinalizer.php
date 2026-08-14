<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

use CloudPortal\Application;
use CloudPortal\Security\Crypto;
use PDO;

final class InstallationFinalizer
{
    public function __construct(
        private readonly DatabaseInstaller $database,
        private readonly RuntimeConfigWriter $config,
        private readonly InstallationLock $lock,
        private readonly ProxmoxTester $proxmox,
    ) {
    }

    /** @param array<string,mixed> $state @return array{stages:list<array{name:string,status:string,detail:string}>,summary:array<string,mixed>} */
    public function finalize(array $state): array
    {
        foreach (['install_id', 'database', 'administrator', 'portal', 'security', 'config_written'] as $required) {
            if (!isset($state[$required])) throw new \RuntimeException('Installer state is incomplete at finalization.');
        }
        $stages = [];
        $run = function (string $name, callable $operation) use (&$stages): mixed {
            $index = count($stages);
            $stages[] = ['name' => $name, 'status' => 'running', 'detail' => ''];
            try {
                $result = $operation();
                $stages[$index] = ['name' => $name, 'status' => 'completed', 'detail' => 'Completed'];
                return $result;
            } catch (\Throwable $exception) {
                $stages[$index] = ['name' => $name, 'status' => 'failed', 'detail' => $exception->getMessage()];
                throw new InstallationFailed($exception->getMessage(), $stages, $exception);
            }
        };

        $run('Verifying configuration', fn () => $this->config->verify((string) $state['install_id']));
        /** @var PDO $pdo */
        $pdo = $run('Connecting to database', fn (): PDO => $this->database->connect($state['database']));
        $run('Verifying database schema', fn () => $this->database->verify($pdo));
        $run('Verifying roles', function () use ($pdo): void {
            if ((int) $pdo->query("SELECT COUNT(*) FROM roles WHERE slug IN ('admin','user')")->fetchColumn() !== 2) throw new \RuntimeException('Required roles are missing.');
        });
        $run('Verifying permissions', function () use ($pdo): void {
            if ((int) $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn() < 12) throw new \RuntimeException('Required permissions are incomplete.');
            if ((int) $pdo->query('SELECT COUNT(*) FROM role_permissions')->fetchColumn() < 5) throw new \RuntimeException('Role permissions are incomplete.');
        });
        $run('Verifying administrator', function () use ($pdo, $state): void {
            $statement = $pdo->prepare("SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id AND r.slug='admin' AND u.status='active'");
            $statement->execute(['id' => $state['administrator']['id']]);
            if (!$statement->fetchColumn()) throw new \RuntimeException('Administrator verification failed.');
        });
        $run('Saving portal settings', function () use ($pdo, $state): void {
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('INSERT INTO settings(setting_key,value,is_public,updated_by) VALUES(:key,:value,:public,:user) ON DUPLICATE KEY UPDATE value=VALUES(value),is_public=VALUES(is_public),updated_by=VALUES(updated_by)');
                foreach ([
                    ['portal.name', $state['portal']['name'], 1], ['portal.default_locale', $state['portal']['locale'], 1],
                    ['portal.base_url', $state['portal']['url'], 0], ['portal.timezone', $state['portal']['timezone'], 0],
                    ['portal.session_lifetime', $state['portal']['session_lifetime'], 0],
                ] as [$key, $value, $public]) {
                    $statement->execute(['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'public' => $public, 'user' => $state['administrator']['id']]);
                }
                $pdo->commit();
            } catch (\Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
        });
        $proxmoxStatus = $run('Configuring Proxmox', function () use ($pdo, $state): string {
            $connection = $state['proxmox'] ?? ['skipped' => true];
            if (($connection['skipped'] ?? false) === true) return 'Skipped';
            $crypto = new Crypto((string) $state['security']['encryption_key']);
            $secret = $crypto->decrypt((string) $connection['token_secret_encrypted']);
            try {
                $test = $this->proxmox->test([...$connection, 'token_secret' => $secret]);
                $statement = $pdo->prepare(
                    'INSERT INTO proxmox_connections(name,hostname,port,realm,api_token_id,api_token_secret_encrypted,verify_ssl,status,cluster_name,last_checked_at,created_by)
                     VALUES(:name,:host,:port,:realm,:token,:secret,:verify,\'active\',:cluster,CURRENT_TIMESTAMP,:admin)
                     ON DUPLICATE KEY UPDATE name=VALUES(name),realm=VALUES(realm),api_token_secret_encrypted=VALUES(api_token_secret_encrypted),verify_ssl=VALUES(verify_ssl),status=\'active\',cluster_name=VALUES(cluster_name),last_checked_at=CURRENT_TIMESTAMP,last_error=NULL'
                );
                $statement->execute([
                    'name' => $connection['name'], 'host' => $connection['hostname'], 'port' => $connection['port'],
                    'realm' => $connection['realm'], 'token' => $connection['token_id'], 'secret' => $connection['token_secret_encrypted'],
                    'verify' => (int) $connection['verify_ssl'], 'cluster' => $test['cluster'], 'admin' => $state['administrator']['id'],
                ]);
            } finally {
                sodium_memzero($secret);
            }
            return 'Configured';
        });
        $run('Verifying security keys', function () use ($state): void {
            $crypto = new Crypto((string) $state['security']['encryption_key']);
            $probe = 'installer-verification-' . bin2hex(random_bytes(8));
            if (!hash_equals($probe, $crypto->decrypt($crypto->encrypt($probe)))) throw new \RuntimeException('Encryption self-test failed.');
            if (session_status() !== PHP_SESSION_ACTIVE) throw new \RuntimeException('A secure PHP session is not active.');
        });
        $run('Finalizing installation', function () use ($pdo, $state): void {
            $this->database->verify($pdo);
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() < 1) throw new \RuntimeException('No administrator exists after finalization.');
            $this->config->activate((string) $state['install_id']);
        });
        $run('Creating installation lock', fn () => $this->lock->create((string) $state['install_id'], Application::VERSION));

        return ['stages' => $stages, 'summary' => [
            'portal_name' => $state['portal']['name'], 'portal_url' => $state['portal']['url'],
            'administrator' => $state['administrator']['username'],
            'administrator_test_account' => (bool) ($state['administrator']['test_account'] ?? false),
            'database_server' => $state['database']['host'] . ':' . $state['database']['port'],
            'proxmox' => $proxmoxStatus, 'version' => Application::VERSION,
        ]];
    }
}
