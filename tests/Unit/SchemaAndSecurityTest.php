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
        $approvedProcessAdapters = array_values(array_filter([
            realpath(dirname(__DIR__, 2) . '/app/Services/Provisioning/TerraformProvisioner.php'),
            realpath(dirname(__DIR__, 2) . '/app/Services/Provisioning/AnsiblePlaybookService.php'),
        ]));
        foreach (['app', 'installer'] as $directory) {
            $root = dirname(__DIR__, 2) . '/' . $directory;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') continue;
                if (in_array(realpath($file->getPathname()), $approvedProcessAdapters, true)) continue;
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                self::assertDoesNotMatchRegularExpression('/(?<!->)(?<!::)\b(shell_exec|exec|system|passthru|proc_open|popen)\s*\(/', $source, $file->getPathname());
            }
        }
    }

    public function testTerraformProvisionerUsesProcOpenWithoutAShell(): void
    {
        $path = dirname(__DIR__, 2) . '/app/Services/Provisioning/TerraformProvisioner.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString("private readonly string \$command = '/usr/local/sbin/algen-terraform-provisioner'", $source);
        self::assertStringContainsString("!str_starts_with(\$this->command, '/')", $source);
        self::assertStringContainsString("preg_match('/[\\r\\n\\0]/', \$this->command)", $source);
        self::assertStringContainsString("proc_open(['sudo', '-n', \$this->command]", $source);
        self::assertStringContainsString("['bypass_shell' => true]", $source);
        self::assertDoesNotMatchRegularExpression('/(?<!->)(?<!::)\b(shell_exec|exec|system|passthru|popen)\s*\(/', $source);
    }

    public function testAnsiblePlaybookServiceUsesProcOpenWithoutAShell(): void
    {
        $path = dirname(__DIR__, 2) . '/app/Services/Provisioning/AnsiblePlaybookService.php';
        $source = file_get_contents($path);
        self::assertIsString($source);
        self::assertStringContainsString("private readonly string \$command = '/usr/bin/ansible-playbook'", $source);
        self::assertStringContainsString("!str_starts_with(\$this->command, '/')", $source);
        self::assertStringContainsString("preg_match('/[\\r\\n\\0]/', \$this->command)", $source);
        self::assertStringContainsString("proc_open(\$parts, \$descriptors, \$pipes, null, \$environment, ['bypass_shell' => true])", $source);
        self::assertStringContainsString("'ANSIBLE_HOST_KEY_CHECKING'", $source);
        self::assertStringContainsString('temporaryInventory(', $source);
        self::assertDoesNotMatchRegularExpression('/(?<!->)(?<!::)\b(shell_exec|exec|system|passthru|popen)\s*\(/', $source);
        self::assertStringNotContainsString('escapeshellarg(', $source);
    }

    public function testProxmoxSecretsUseEncryptedColumnOnly(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql');
        self::assertStringContainsString('api_token_secret_encrypted', $schema);
        self::assertDoesNotMatchRegularExpression('/\bapi_token_secret\s+(?:VARCHAR|TEXT)/i', $schema);
    }
}
