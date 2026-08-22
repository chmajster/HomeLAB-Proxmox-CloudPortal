<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Services\Observability\HealthService;
use CloudPortal\Services\Placement\PlacementService;
use CloudPortal\Services\Provisioning\JobRepository;

final class PlatformOperationsTest extends MariaDbTestCase
{
    public function testRetryBackoffDeadLetterAndManualRetry(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        $jobs = new JobRepository(self::$pdo);
        $publicId = $jobs->enqueue('vm.reconfigure', null, null, null, [], null, null, 2);
        try {
            $first = $jobs->claimNext();
            self::assertIsArray($first);
            self::assertSame(1, $first['attempts']);
            $jobs->fail((int) $first['id'], 'first failure');
            self::$pdo->prepare('UPDATE jobs SET available_at=CURRENT_TIMESTAMP WHERE public_id=:id')->execute(['id' => $publicId]);
            $second = $jobs->claimNext();
            self::assertIsArray($second);
            self::assertSame(2, $second['attempts']);
            $jobs->fail((int) $second['id'], 'second failure');
            self::assertSame('dead_letter', $jobs->find($publicId)['status']);
            self::assertTrue($jobs->manualRetry($publicId));
            $retried = $jobs->find($publicId);
            self::assertSame('queued', $retried['status']);
            self::assertSame(0, (int) $retried['attempts']);
        } finally {
            self::$pdo->prepare('DELETE FROM jobs WHERE public_id=:id')->execute(['id' => $publicId]);
        }
    }

    public function testNonRetryableCreateFailsWithoutDuplicateRisk(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        $jobs = new JobRepository(self::$pdo);
        $publicId = $jobs->enqueue('vm.create', null, null, null, [], null, null, 3);
        try {
            $job = $jobs->claimNext();
            self::assertIsArray($job);
            $jobs->fail((int) $job['id'], 'unsafe to retry automatically');
            self::assertSame('failed', $jobs->find($publicId)['status']);
        } finally {
            self::$pdo->prepare('DELETE FROM jobs WHERE public_id=:id')->execute(['id' => $publicId]);
        }
    }

    public function testPlacementSkipsMaintenanceAndPrefersHealthyCapacity(): void
    {
        self::$pdo->exec("INSERT INTO proxmox_connections(name,hostname,api_token_id,api_token_secret_encrypted) VALUES('placement-test','pve.example.test','test@pve!portal','encrypted')");
        $connection = (int) self::$pdo->lastInsertId();
        $insert = self::$pdo->prepare('INSERT INTO proxmox_nodes(connection_id,node_name,status,maintenance_mode,placement_weight,cpu_usage,memory_total,memory_used) VALUES(:connection,:node,\'online\',:maintenance,:weight,:cpu,1000,:memory)');
        $insert->execute(['connection'=>$connection,'node'=>'busy','maintenance'=>0,'weight'=>100,'cpu'=>0.90,'memory'=>900]);
        $insert->execute(['connection'=>$connection,'node'=>'healthy','maintenance'=>0,'weight'=>100,'cpu'=>0.10,'memory'=>200]);
        $insert->execute(['connection'=>$connection,'node'=>'maintenance','maintenance'=>1,'weight'=>1000,'cpu'=>0.00,'memory'=>0]);
        self::assertSame('healthy', (new PlacementService(self::$pdo))->recommend($connection));
    }

    public function testHealthReportsCurrentSchemaAndQueueMetrics(): void
    {
        $report = (new HealthService(self::$pdo))->report();
        self::assertTrue($report['database']);
        self::assertTrue($report['schema_current']);
        self::assertArrayHasKey('dead_letter', $report['jobs']);
        self::assertArrayHasKey('worker_healthy', $report);
    }
}
