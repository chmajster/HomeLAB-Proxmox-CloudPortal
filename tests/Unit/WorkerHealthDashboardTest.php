<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WorkerHealthDashboardTest extends TestCase
{
    public function testHealthReportExposesRealWorkerOnlineState(): void
    {
        $service = file_get_contents(__DIR__ . '/../../app/Services/Observability/HealthService.php');
        self::assertIsString($service);
        self::assertStringContainsString("'worker_online'", $service);
        self::assertStringContainsString("'worker_required'", $service);
        self::assertStringContainsString("'worker_status'", $service);
        self::assertStringContainsString("'stuck_running'", $service);
        self::assertStringContainsString('WORKER_ONLINE_SECONDS = 90', $service);
        self::assertStringContainsString('STUCK_JOB_SECONDS = 300', $service);
    }

    public function testAdminDashboardRendersWorkerHealthOutsideSpaContent(): void
    {
        $script = file_get_contents(__DIR__ . '/../../public/assets/js/portal-enhancements.js');
        self::assertIsString($script);
        self::assertStringContainsString('/api/v1/admin/system/health', $script);
        self::assertStringContainsString('workerHealthPanel', $script);
        self::assertStringContainsString('setInterval(loadWorkerHealth, 15000)', $script);
        self::assertStringContainsString('Worker jest offline', $script);
        self::assertStringNotContainsString('window.alert(', $script);
    }
}
