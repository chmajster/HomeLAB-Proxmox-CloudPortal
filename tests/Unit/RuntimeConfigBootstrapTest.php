<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Installer\Services\RuntimeConfigWriter;
use CloudPortal\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class RuntimeConfigBootstrapTest extends TestCase
{
    public function testBootstrapSecretsAreEncryptedInRuntimeConfiguration(): void
    {
        $root = sys_get_temp_dir() . '/cloudportal-runtime-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/config', 0700, true));
        $encryptionKey = base64_encode(random_bytes(32));
        $state = [
            'install_id' => bin2hex(random_bytes(16)),
            'database' => [
                'host' => '127.0.0.1',
                'port' => 3306,
                'name' => 'cloudportal',
                'user' => 'portal',
                'password' => 'database-secret',
            ],
            'portal' => [
                'name' => 'Cloud Portal',
                'url' => 'https://cloud.example.com',
                'timezone' => 'Europe/Warsaw',
                'locale' => 'pl',
                'session_lifetime' => 7200,
            ],
            'security' => [
                'app_key' => base64_encode(random_bytes(32)),
                'encryption_key' => $encryptionKey,
                'csrf_secret' => base64_encode(random_bytes(32)),
            ],
            'bootstrap' => [
                'dns' => [
                    'server_ip' => '192.168.1.53',
                    'api_token' => 'dns-secret',
                ],
                'proxmox_credentials' => [
                    'login' => 'root@pam',
                    'password' => 'proxmox-password',
                ],
                'hostname_generator' => [
                    'pattern' => 'vm-{project}-{counter}',
                ],
            ],
        ];

        try {
            $path = (new RuntimeConfigWriter($root))->write($state);
            $runtime = require $path;
            self::assertSame('192.168.1.53', $runtime['dns']['server_ip']);
            self::assertNotSame('dns-secret', $runtime['dns']['api_token_encrypted']);
            self::assertNotSame('proxmox-password', $runtime['proxmox_credentials']['password_encrypted']);
            self::assertSame('root@pam', $runtime['proxmox_credentials']['login']);
            self::assertSame('vm-{project}-{counter}', $runtime['hostname_generator']['pattern']);

            $crypto = new Crypto($encryptionKey);
            self::assertSame('dns-secret', $crypto->decrypt($runtime['dns']['api_token_encrypted']));
            self::assertSame('proxmox-password', $crypto->decrypt($runtime['proxmox_credentials']['password_encrypted']));
        } finally {
            @unlink($root . '/config/runtime.php');
            @rmdir($root . '/config');
            @rmdir($root);
        }
    }
}
