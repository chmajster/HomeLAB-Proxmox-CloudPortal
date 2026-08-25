<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RealProxmoxE2ETest extends TestCase
{
    public function testStandardCiDoesNotDependOnSelfHostedRunner(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../.github/workflows/ci.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString('runs-on: ubuntu-latest', $workflow);
        self::assertStringNotContainsString('runs-on:\n      - self-hosted', $workflow);
    }

    public function testRealProxmoxWorkflowIsManualAndExplicitlyDestructive(): void
    {
        $workflow = file_get_contents(__DIR__ . '/../../.github/workflows/proxmox-e2e.yml');
        self::assertIsString($workflow);
        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringContainsString("inputs.confirm_destroy == 'DESTROY TEST VM'", $workflow);
        self::assertStringContainsString('runs-on: [self-hosted, linux, x64]', $workflow);
        self::assertStringNotContainsString('pull_request:', $workflow);
        self::assertStringNotContainsString('schedule:', $workflow);
    }

    public function testRealLifecycleCoversExpectedOperationsAndCleanup(): void
    {
        $spec = file_get_contents(__DIR__ . '/../proxmox-e2e/real-proxmox.spec.js');
        self::assertIsString($spec);
        foreach (['/start', '/reboot', '/stop', '/snapshots', '/resize'] as $operation) {
            self::assertStringContainsString($operation, $spec);
        }
        self::assertStringContainsString("'DELETE'", $spec);
        self::assertStringContainsString('worker_online', $spec);
        self::assertStringContainsString('stuck_running', $spec);
        self::assertStringContainsString('finally', $spec);
    }
}
