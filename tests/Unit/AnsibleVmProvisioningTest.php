<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\Provisioning\AnsiblePlaybookService;
use PHPUnit\Framework\TestCase;

final class AnsibleVmProvisioningTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cloud-portal-ansible-' . bin2hex(random_bytes(8));
        mkdir($this->directory . '/roles/web', 0700, true);
        file_put_contents($this->directory . '/base.yml', "---\n- hosts: all\n  tasks: []\n");
        file_put_contents($this->directory . '/roles/web/install.yaml', "---\n- hosts: all\n  tasks: []\n");
        file_put_contents($this->directory . '/README.md', 'not a playbook');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        if (is_dir($this->directory)) rmdir($this->directory);
    }

    public function testPlaybookDiscoveryOnlyReturnsYamlInsideConfiguredDirectory(): void
    {
        $service = new AnsiblePlaybookService($this->directory);

        self::assertSame([
            ['id' => 'base.yml', 'name' => 'base.yml'],
            ['id' => 'roles/web/install.yaml', 'name' => 'roles/web/install.yaml'],
        ], $service->playbooks());
        self::assertSame('base.yml', $service->validateSelection('base.yml'));
    }

    public function testPlaybookSelectionRejectsTraversalAndAbsolutePaths(): void
    {
        $service = new AnsiblePlaybookService($this->directory);

        foreach (['../base.yml', '/etc/ansible/site.yml', 'README.md'] as $invalid) {
            try {
                $service->validateSelection($invalid);
                self::fail('Expected invalid playbook to be rejected: ' . $invalid);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testVmWizardAndWorkerContainPostProvisionAnsibleFlow(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = (string) file_get_contents($root . '/resources/views/layouts/app.php');
        $selector = (string) file_get_contents($root . '/public/assets/js/ansible-create-vm.js');
        $request = (string) file_get_contents($root . '/app/Services/Provisioning/ProvisioningRequestService.php');
        $worker = (string) file_get_contents($root . '/bin/worker.php');
        $jobs = (string) file_get_contents($root . '/app/Services/Provisioning/JobRepository.php');

        self::assertStringContainsString('ansible-create-vm.js', $layout);
        self::assertStringContainsString('name="ansible_playbook"', $selector);
        self::assertStringContainsString("'ansible_playbook' => \$ansiblePlaybook", $request);
        self::assertStringContainsString('controllerPublicKey()', $request);
        self::assertStringContainsString("'vm.ansible'", $worker);
        self::assertStringContainsString("'ansible_job_id'", $worker);
        self::assertStringContainsString("'vm.ansible'", $jobs);
    }
}
