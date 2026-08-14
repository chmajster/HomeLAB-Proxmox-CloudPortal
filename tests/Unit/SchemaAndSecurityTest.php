<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaAndSecurityTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function requiredTables(): iterable
    {
        foreach (['users','roles','permissions','role_permissions','projects','project_users','proxmox_connections','proxmox_nodes','virtual_machines','vm_templates','resource_plans','quotas','networks','ip_addresses','snapshots','jobs','audit_logs','settings','password_reset_tokens'] as $table) {
            yield $table => [$table];
        }
    }

    #[DataProvider('requiredTables')]
    public function testSchemaContainsRequiredTable(string $table): void
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        self::assertIsString($schema);
        self::assertMatchesRegularExpression('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\s*\(/i', $schema);
    }

    public function testProductionCodeDoesNotExecuteShellCommands(): void
    {
        foreach (['app', 'installer'] as $directory) {
            $root = dirname(__DIR__, 2) . '/' . $directory;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') continue;
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                self::assertDoesNotMatchRegularExpression('/(?<!->)(?<!::)\b(shell_exec|exec|system|passthru|proc_open|popen)\s*\(/', $source, $file->getPathname());
            }
        }
    }

    public function testProxmoxSecretsUseEncryptedColumnOnly(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        self::assertStringContainsString('api_token_secret_encrypted', $schema);
        self::assertDoesNotMatchRegularExpression('/\bapi_token_secret\s+(?:VARCHAR|TEXT)/i', $schema);
    }
}
