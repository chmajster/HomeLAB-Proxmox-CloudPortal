<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Installer\Services\JsonInstallationConfig;
use PHPUnit\Framework\TestCase;

final class JsonInstallationConfigTest extends TestCase
{
    public function testLoadsAndNormalizesValidConfiguration(): void
    {
        $path = $this->writeJson([
            'database' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'name' => 'cloudportal',
                'user' => 'portal',
                'password' => 'db-secret',
            ],
            'administrator' => [
                'username' => 'admin',
                'email' => '',
                'password' => 'StrongPassword123',
            ],
        ]);

        try {
            $config = (new JsonInstallationConfig())->load($path, 'https://cloud.example.com');
            self::assertSame('127.0.0.1', $config['database']['host']);
            self::assertSame('cloudportal', $config['database']['name']);
            self::assertSame('admin@localhost.invalid', $config['administrator']['email']);
            self::assertTrue($config['proxmox']['skipped']);
            self::assertSame('https://cloud.example.com', $config['portal']['url']);
            self::assertSame('Europe/Warsaw', $config['portal']['timezone']);
            self::assertSame('vm-{project}-{counter}', $config['hostname_generator']['pattern']);
        } finally {
            @unlink($path);
        }
    }

    public function testLoadsPanelDnsProxmoxCredentialsAndHostnamePattern(): void
    {
        $path = $this->writeJson([
            'database' => [
                'name' => 'cloudportal',
                'user' => 'portal',
                'password' => 'db-secret',
            ],
            'panel' => [
                'login' => 'admin',
                'password' => 'StrongPassword123',
            ],
            'dns' => [
                'server_ip' => '192.168.1.53',
                'api_token' => 'dns-token',
            ],
            'proxmox' => [
                'hostname' => 'pve.example.com',
                'realm' => 'pam',
                'login' => 'root@pam',
                'password' => 'proxmox-password',
                'token_name' => 'cloudportal',
                'token' => 'proxmox-token-secret',
            ],
            'hostname_generator' => [
                'pattern' => 'lab-{project}-{counter}',
            ],
        ]);

        try {
            $config = (new JsonInstallationConfig())->load($path, 'https://cloud.example.com');
            self::assertSame('admin', $config['administrator']['username']);
            self::assertSame('192.168.1.53', $config['dns']['server_ip']);
            self::assertSame('dns-token', $config['dns']['api_token']);
            self::assertFalse($config['proxmox']['skipped']);
            self::assertSame('root@pam!cloudportal', $config['proxmox']['token_id']);
            self::assertSame('proxmox-token-secret', $config['proxmox']['token_secret']);
            self::assertSame('root@pam', $config['proxmox_credentials']['login']);
            self::assertSame('proxmox-password', $config['proxmox_credentials']['password']);
            self::assertSame('lab-{project}-{counter}', $config['hostname_generator']['pattern']);
        } finally {
            @unlink($path);
        }
    }

    public function testAcceptsFullProxmoxTokenString(): void
    {
        $path = $this->writeJson([
            'database' => [
                'name' => 'cloudportal',
                'user' => 'portal',
                'password' => 'db-secret',
            ],
            'panel' => [
                'login' => 'admin',
                'password' => 'StrongPassword123',
            ],
            'proxmox' => [
                'hostname' => 'pve.example.com',
                'realm' => 'pam',
                'token' => 'root@pam!cloudportal=secret-value',
            ],
        ]);

        try {
            $config = (new JsonInstallationConfig())->load($path, 'https://cloud.example.com');
            self::assertSame('root@pam!cloudportal', $config['proxmox']['token_id']);
            self::assertSame('secret-value', $config['proxmox']['token_secret']);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsInvalidJsonBeforeInstallation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cloudportal-json-');
        self::assertIsString($path);
        file_put_contents($path, '{invalid');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('invalid JSON');
            (new JsonInstallationConfig())->load($path, 'https://cloud.example.com');
        } finally {
            @unlink($path);
        }
    }

    public function testRequiresDatabaseAndPanelOrAdministratorObject(): void
    {
        $path = $this->writeJson(['panel' => [
            'login' => 'admin',
            'password' => 'StrongPassword123',
        ]]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("missing the required 'database' object");
            (new JsonInstallationConfig())->load($path, 'https://cloud.example.com');
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsAmbiguousPanelAndAdministratorSections(): void
    {
        $path = $this->writeJson([
            'database' => ['name' => 'cloudportal', 'user' => 'portal'],
            'panel' => ['login' => 'admin'],
            'administrator' => ['username' => 'admin'],
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("either 'panel' or legacy 'administrator'");
            (new JsonInstallationConfig())->load($path, 'https://cloud.example.com');
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsHostnamePatternWithoutCounter(): void
    {
        $path = $this->writeJson([
            'database' => ['name' => 'cloudportal', 'user' => 'portal'],
            'panel' => ['login' => 'admin', 'password' => 'StrongPassword123'],
            'hostname_generator' => ['pattern' => 'vm-{project}'],
        ]);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('must contain {counter}');
            (new JsonInstallationConfig())->load($path, 'https://cloud.example.com');
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string,mixed> $data */
    private function writeJson(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cloudportal-json-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        return $path;
    }
}
