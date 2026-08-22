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

    public function testRequiresDatabaseAndAdministratorObjects(): void
    {
        $path = $this->writeJson(['administrator' => [
            'username' => 'admin',
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

    /** @param array<string,mixed> $data */
    private function writeJson(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cloudportal-json-');
        self::assertIsString($path);
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
        return $path;
    }
}
