<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VmBlueprintAutomationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testBlueprintSchemaAndVersionAreRegistered(): void
    {
        $migration = (string) file_get_contents($this->root . '/database/migrations/1.9.0.sql');
        $application = (string) file_get_contents($this->root . '/app/Application.php');
        $migrationService = (string) file_get_contents($this->root . '/app/Database/MigrationService.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS vm_blueprints', $migration);
        self::assertStringContainsString('initial_hardening_command', $migration);
        self::assertStringContainsString('reboot_before_ansible', $migration);
        self::assertStringContainsString('ansible_playbook', $migration);
        self::assertStringContainsString('ansible_extra_vars JSON', $migration);
        self::assertStringContainsString('ADD COLUMN blueprint_id', $migration);
        self::assertStringContainsString("public const VERSION = '1.9.0'", $application);
        self::assertStringContainsString("CURRENT_VERSION = '1.9.0'", $migrationService);
    }

    public function testBlueprintApiSupportsProfileManagementAndOneClickDeploy(): void
    {
        $routes = (string) file_get_contents($this->root . '/routes/blueprints.php');
        $controller = (string) file_get_contents($this->root . '/app/Controllers/VmBlueprintController.php');
        $index = (string) file_get_contents($this->root . '/public/index.php');

        self::assertStringContainsString("'/api/v1/blueprints'", $routes);
        self::assertStringContainsString("'/api/v1/blueprints/{id}/deploy'", $routes);
        self::assertStringContainsString("'/api/v1/admin/blueprints'", $routes);
        self::assertStringContainsString("'/api/v1/admin/blueprints/{id}'", $routes);
        self::assertStringContainsString("'managed_provisioning' => true", $controller);
        self::assertStringContainsString("'blueprint_id' => \$id", $controller);
        self::assertStringContainsString("'reboot_before_ansible'", $controller);
        self::assertStringContainsString("'ansible_extra_vars'", $controller);
        self::assertLessThan(
            strpos($index, "routes/api.php"),
            strpos($index, "routes/blueprints.php"),
            'Specific blueprint routes must be registered before generic API resource routes.',
        );
    }

    public function testBlueprintUsesProxmoxApiAndWaitsForAnsibleBeforeReady(): void
    {
        $processor = (string) file_get_contents($this->root . '/app/Services/Provisioning/ManagedCreateProcessor.php');
        $jobs = (string) file_get_contents($this->root . '/app/Services/Provisioning/JobRepository.php');
        $ansible = (string) file_get_contents($this->root . '/app/Services/Provisioning/AnsibleJobProcessor.php');

        self::assertStringContainsString("\$blueprintId <= 0 && \$this->terraformCreate instanceof TerraformProvisioner", $processor);
        self::assertStringContainsString("'Clone VM from template'", $processor);
        self::assertStringContainsString("'Initial hardening'", $processor);
        self::assertStringContainsString("'/status/reboot'", $processor);
        self::assertStringContainsString("'WAITING_FOR_ANSIBLE'", $processor);
        self::assertStringNotContainsString("'REBOOTING'", $processor);
        self::assertStringContainsString("\$handoffToAnsible = (\$result['provisioning_status'] ?? null) === 'WAITING_FOR_ANSIBLE'", $jobs);
        self::assertStringContainsString('completeBlueprintProvisioning', $ansible);
        self::assertStringContainsString("\$state->ready(\$jobId, 14, 'READY')", $ansible);
        self::assertStringContainsString("UPDATE virtual_machines SET status='running',last_error=NULL", $ansible);
    }

    public function testBlueprintPassesAnsibleVariablesThroughTheWorker(): void
    {
        $request = (string) file_get_contents($this->root . '/app/Services/Provisioning/ProvisioningRequestService.php');
        $worker = (string) file_get_contents($this->root . '/bin/worker.php');

        self::assertStringContainsString("'blueprint_id' => \$blueprintId", $request);
        self::assertStringContainsString("'initial_hardening_command'", $request);
        self::assertStringContainsString("'reboot_before_ansible'", $request);
        self::assertStringContainsString("'ansible_extra_vars' => \$ansibleExtraVars", $request);
        self::assertStringContainsString("\$payload['ansible_extra_vars']", $worker);
        self::assertStringContainsString("'extra_vars' => \$extraVars", $worker);
        self::assertStringContainsString("'blueprint_id'", $worker);
    }

    public function testBlueprintUiProvidesProfileEditorAndOneClickDeploy(): void
    {
        $layout = (string) file_get_contents($this->root . '/resources/views/layouts/app.php');
        $portal = (string) file_get_contents($this->root . '/resources/views/pages/portal.php');
        $deploy = (string) file_get_contents($this->root . '/public/assets/js/blueprint-create-vm.js');
        $admin = (string) file_get_contents($this->root . '/public/assets/js/blueprint-admin.js');
        $ci = (string) file_get_contents($this->root . '/.github/workflows/ci.yml');

        self::assertStringContainsString('blueprint-create-vm.js', $layout);
        self::assertStringContainsString('blueprint-admin.js', $layout);
        self::assertStringContainsString('VM Blueprints', $portal);
        self::assertStringContainsString('/api/v1/blueprints/${encodeURIComponent(select.value)}/deploy', $deploy);
        self::assertStringContainsString('clone template → initial hardening → reboot → Ansible → running', $deploy);
        self::assertStringContainsString('Initial hardening command', $admin);
        self::assertStringContainsString('Reboot before Ansible', $admin);
        self::assertStringContainsString("options(catalog.storages, 'id', 'storage_name')", $admin);
        self::assertStringContainsString('globalCloudInit', $admin);
        self::assertStringContainsString('node --check public/assets/js/blueprint-create-vm.js', $ci);
        self::assertStringContainsString('node --check public/assets/js/blueprint-admin.js', $ci);
    }
}
