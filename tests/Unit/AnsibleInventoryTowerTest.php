<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AnsibleInventoryTowerTest extends TestCase
{
    public function testPersistentInventorySchemaAndVersionAreRegistered(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = (string) file_get_contents($root . '/database/migrations/1.8.0.sql');
        $migrationService = (string) file_get_contents($root . '/app/Database/MigrationService.php');
        $installer = (string) file_get_contents($root . '/installer/Services/DatabaseInstaller.php');
        $application = (string) file_get_contents($root . '/app/Application.php');

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ansible_inventories', $migration);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS ansible_inventory_hosts', $migration);
        self::assertStringContainsString("CURRENT_VERSION = '1.9.0'", $migrationService);
        self::assertStringContainsString("SCHEMA_VERSION = '1.9.0'", $installer);
        self::assertStringContainsString("'ansible_inventories'", $installer);
        self::assertStringContainsString("'ansible_inventory_hosts'", $installer);
        self::assertStringContainsString("VERSION = '1.9.0'", $application);
    }

    public function testAnsibleInventoryApiExposesCrudHostsAndLaunchEndpoints(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = (string) file_get_contents($root . '/routes/api.php');
        $controller = (string) file_get_contents($root . '/app/Controllers/AnsibleController.php');
        $vmController = (string) file_get_contents($root . '/app/Controllers/VmController.php');

        foreach ([
            '/api/v1/ansible/playbooks',
            '/api/v1/ansible/inventories',
            '/api/v1/ansible/inventories/{id}',
            '/api/v1/ansible/inventories/{id}/hosts',
            '/api/v1/ansible/inventories/{id}/hosts/{hostId}',
            '/api/v1/ansible/inventories/{id}/launch',
            '/api/v1/vms/{id}/ansible',
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }

        self::assertStringContainsString("requirePermission('vm.view')", $controller);
        self::assertStringContainsString("requirePermission('vm.operate')", $controller);
        self::assertStringContainsString("'ansible.inventory'", $controller);
        self::assertStringContainsString("'vm.ansible'", $controller);
        self::assertStringContainsString("'extra_vars'", $controller);
        self::assertStringContainsString("'limit_vm_id'", $controller);
        self::assertStringContainsString('vm.project_id', $vmController);
    }

    public function testWorkerSupportsInventoryJobsAndRealInventoryFiles(): void
    {
        $root = dirname(__DIR__, 2);
        $processor = (string) file_get_contents($root . '/app/Services/Provisioning/AnsibleJobProcessor.php');
        $runner = (string) file_get_contents($root . '/app/Services/Provisioning/AnsiblePlaybookService.php');
        $jobs = (string) file_get_contents($root . '/app/Services/Provisioning/JobRepository.php');
        $worker = (string) file_get_contents($root . '/bin/worker.php');

        self::assertStringContainsString("['vm.ansible', 'ansible.inventory']", $processor);
        self::assertStringContainsString('runInventory(', $processor);
        self::assertStringContainsString('runInventory(', $runner);
        self::assertStringContainsString('temporaryInventory(', $runner);
        self::assertStringContainsString('waitForInventorySsh(', $runner);
        self::assertStringContainsString("str_starts_with(strtolower(\$key), 'ansible_')", $runner);
        self::assertStringContainsString("'--extra-vars'", $runner);
        self::assertStringContainsString("'ansible.inventory'", $jobs);
        self::assertStringContainsString('ansibleProcessor->supports', $worker);
    }

    public function testPortalContainsDedicatedAnsibleOperationsPage(): void
    {
        $root = dirname(__DIR__, 2);
        $portalController = (string) file_get_contents($root . '/app/Controllers/PortalController.php');
        $portal = (string) file_get_contents($root . '/resources/views/pages/portal.php');
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $shell = (string) file_get_contents($root . '/public/assets/js/ansible-shell.js');
        $ui = (string) file_get_contents($root . '/public/assets/js/ansible-tower.js');

        self::assertStringContainsString("'ansible'", $portalController);
        self::assertStringContainsString("['ansible', 'Ansible'", $portal);
        self::assertStringContainsString('ansible-shell.js', $layout);
        self::assertStringContainsString('ansible-tower.js', $layout);
        self::assertStringContainsString("['ansible', 'blueprints']", $shell);
        self::assertStringContainsString('createInventoryForm', $ui);
        self::assertStringContainsString('addHostForm', $ui);
        self::assertStringContainsString('launchInventoryForm', $ui);
        self::assertStringContainsString('launchVmForm', $ui);
    }
}
