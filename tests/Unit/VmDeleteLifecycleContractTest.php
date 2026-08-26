<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VmDeleteLifecycleContractTest extends TestCase
{
    public function testDeleteWorkflowProvesRemoteAbsenceBeforeDnsAndIpamCleanup(): void
    {
        $root = dirname(__DIR__, 2);
        $source = (string) file_get_contents($root . '/app/Services/Provisioning/VmDeleteLifecycleProcessor.php');

        $remote = strpos($source, '$this->ensureRemoteVmAbsent($jobId, $vm);');
        $dns = strpos($source, '$this->cleanupManagedDns($vmId);');
        $ipam = strpos($source, '(new IPAMService($pdo))->releaseVm($vmId);');
        $complete = strpos($source, '$this->jobs->complete($jobId, $result);');

        self::assertNotFalse($remote);
        self::assertNotFalse($dns);
        self::assertNotFalse($ipam);
        self::assertNotFalse($complete);
        self::assertLessThan($dns, $remote, 'Remote VM absence must be verified before DNS cleanup.');
        self::assertLessThan($ipam, $dns, 'DNS cleanup must finish before IPAM is released.');
        self::assertLessThan($complete, $ipam, 'The durable job must complete only after IPAM release/local deletion.');
    }

    public function testManagedDnsRecordsAreClearedIndividuallyForRetrySafety(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Provisioning/VmDeleteLifecycleProcessor.php');

        self::assertStringContainsString('deleteRecord((string) $state[\'reverse_zone\'], $ptrId)', $source);
        self::assertStringContainsString('SET ptr_record_id=NULL', $source);
        self::assertStringContainsString('deleteRecord((string) $state[\'forward_zone\'], $aId)', $source);
        self::assertStringContainsString('SET a_record_id=NULL', $source);
        self::assertStringContainsString('DNS client is unavailable; IPAM was retained', $source);
    }

    public function testWorkerRoutesDeleteJobsToLifecycleProcessorAndRequeuesInterruptedDeletes(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/worker.php');

        self::assertStringContainsString('new VmDeleteLifecycleProcessor(', $source);
        self::assertStringContainsString("(string) $staleJob['type'] === 'vm.delete'", $source);
        self::assertStringContainsString('fail-closed lifecycle deletion will resume idempotently', $source);

        $deleteRoute = strpos($source, 'if ($deleteLifecycle->supports((string) $job[\'type\']))');
        $managedRoute = strpos($source, "elseif (($job['payload']['managed_provisioning'] ?? false) === true)");
        self::assertNotFalse($deleteRoute);
        self::assertNotFalse($managedRoute);
        self::assertLessThan($managedRoute, $deleteRoute, 'Delete routing must take precedence over managed-create routing.');
    }
}
