<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\CloudInit\SshKeyService;
use CloudPortal\Services\Provisioning\CloudInitRuntime;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use PHPUnit\Framework\TestCase;

final class CloudInitAuditOpenApiTest extends TestCase
{
    public function testSshFingerprintUsesOpenSshSha256Format(): void
    {
        $blob = str_repeat('A', 32);
        $parsed = SshKeyService::parsePublicKey('ssh-ed25519 ' . base64_encode($blob) . ' unit@test');
        self::assertSame('ssh-ed25519', $parsed['type']);
        self::assertSame('SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '='), $parsed['fingerprint']);
    }

    public function testCloudInitRuntimeAppliesNativeSettingsAndValidatedSnippet(): void
    {
        $client = new class implements ProxmoxClientInterface {
            /** @var array<string,mixed> */
            public array $lastQuery = [];
            public string $lastPath = '';
            public function get(string $path, array $query = []): mixed
            {
                $this->lastPath = $path;
                $this->lastQuery = $query;
                return [['volid' => 'local:snippets/docker-host.yaml']];
            }
            public function post(string $path, array $data = []): mixed { return null; }
            public function put(string $path, array $data = []): mixed { return null; }
            public function delete(string $path, array $data = []): mixed { return null; }
            public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array { return []; }
        };
        $config = (new CloudInitRuntime())->config($client, 'pve01', [
            'cloud_init_user' => 'ubuntu',
            'qemu_guest_agent' => false,
            'ssh_public_key' => "ssh-ed25519 AAAA first\nssh-ed25519 BBBB second",
            'dns_servers' => '10.0.0.53,1.1.1.1',
            'search_domain' => 'lab.example',
            'cicustom_vendor' => 'local:snippets/docker-host.yaml',
        ], ['cores' => 2]);

        self::assertSame(['content' => 'snippets'], $client->lastQuery);
        self::assertSame('/nodes/pve01/storage/local/content', $client->lastPath);
        self::assertSame('ubuntu', $config['ciuser']);
        self::assertSame('enabled=0', $config['agent']);
        self::assertSame('10.0.0.53 1.1.1.1', $config['nameserver']);
        self::assertSame('lab.example', $config['searchdomain']);
        self::assertSame('vendor=local:snippets/docker-host.yaml', $config['cicustom']);
        self::assertStringContainsString("\n", $config['sshkeys']);
    }

    public function testVersionedMigrationContainsNewDomainTablesAndAuditContext(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/database/migrations/1.5.0.sql');
        $migrationService = (string) file_get_contents($root . '/app/Database/MigrationService.php');
        $securityMigration = (string) file_get_contents($root . '/database/migrations/1.6.0.sql');
        $platformMigration = (string) file_get_contents($root . '/database/migrations/1.7.0.sql');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS user_ssh_keys', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_init_profiles', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS cloud_init_profile_ssh_keys', $migration);
        self::assertStringContainsString('cloud_init_profile_id', $migration);
        self::assertStringContainsString('virtual_machine_id', $migration);
        self::assertStringContainsString('proxmox_upid', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS user_mfa_recovery_codes', $securityMigration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS api_tokens', $platformMigration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS api_idempotency_keys', $platformMigration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS reconciliation_incidents', $platformMigration);
        self::assertStringContainsString("CURRENT_VERSION = '1.9.0'", $migrationService);
    }

    public function testApiAndPortalExposeSelectedFeatures(): void
    {
        $root = dirname(__DIR__, 2);
        $api = (string) file_get_contents($root . '/routes/api.php');
        $web = (string) file_get_contents($root . '/routes/web.php');
        $portal = (string) file_get_contents($root . '/app/Controllers/PortalController.php');
        $ui = (string) file_get_contents($root . '/public/assets/js/cloud-features.js');

        foreach (['/api/v1/ssh-keys', '/api/v1/cloud-init-profiles', '/api/v1/admin/audit/search', '/api/v1/admin/audit/export', '/api/openapi.json'] as $path) {
            self::assertStringContainsString($path, $api);
        }
        self::assertStringContainsString("'/api/docs'", $web);
        self::assertStringContainsString("'cloud-init'", $portal);
        self::assertStringContainsString("'ssh-keys'", $portal);
        self::assertStringContainsString("'security'", $portal);
        self::assertStringContainsString('cloud_init_profile_id', $ui);
        self::assertStringContainsString('ssh_key_ids', $ui);
    }

    public function testAllProvisionersPersistCloudInitProfileIdentity(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'app/Services/Provisioning/ProxmoxProvisioner.php',
            'app/Services/Provisioning/PlacedCreateProcessor.php',
            'app/Services/Provisioning/TerraformProvisioner.php',
        ] as $file) {
            $source = (string) file_get_contents($root . '/' . $file);
            self::assertStringContainsString('cloud_init_profile_id', $source, $file);
        }
        $request = (string) file_get_contents($root . '/app/Services/Provisioning/ProvisioningRequestService.php');
        self::assertStringContainsString('SshKeyService::ids', $request);
        self::assertStringContainsString('resolveForOwner', $request);
        self::assertStringContainsString('cloud_init_vendor_sha256', $request);
    }
}
