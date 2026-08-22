<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Services\Provisioning\HostnameGenerator;
use CloudPortal\Services\Provisioning\JobRepository;
use CloudPortal\Services\Provisioning\ProvisioningStateRepository;
use CloudPortal\Support\Uuid;

final class ManagedProvisioningStateTest extends MariaDbTestCase
{
    public function testHostnameGeneratorUsesAtomicMonotonicCounter(): void
    {
        $fixture = $this->fixture();
        $generator = new HostnameGenerator(self::$pdo, 'vm-{project}-{counter}');

        $first = $generator->generate($fixture['project'], $fixture['user']);
        $second = $generator->generate($fixture['project'], $fixture['user']);

        self::assertNotSame($first, $second);
        self::assertStringEndsWith('-1', $first);
        self::assertStringEndsWith('-2', $second);
        self::assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $first);
        self::assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $second);
    }

    public function testManagedJobCannotCompleteBeforeReady(): void
    {
        $fixture = $this->fixture();
        $jobs = new JobRepository(self::$pdo);
        $publicId = $jobs->enqueue(
            'vm.create',
            $fixture['user'],
            $fixture['project'],
            null,
            ['managed_provisioning' => true],
            Uuid::v4(),
        );
        $job = $jobs->find($publicId);
        self::assertIsArray($job);

        $states = new ProvisioningStateRepository(self::$pdo);
        $states->createReserved($publicId, (string) $job['reservation_key'], 'vm-test-1', '10.0.10.10');

        $jobs->complete((int) $job['id'], ['phase' => 'proxmox-created']);
        $running = $jobs->find($publicId);
        self::assertIsArray($running);
        self::assertSame('running', $running['status']);
        self::assertSame('RESERVED', $states->forJob((int) $job['id'])['status']);

        $states->ready((int) $job['id']);
        $jobs->complete((int) $job['id'], ['provisioning_status' => 'READY']);
        $completed = $jobs->find($publicId);
        self::assertIsArray($completed);
        self::assertSame('completed', $completed['status']);
        self::assertSame('READY', $states->forJob((int) $job['id'])['status']);
    }

    public function testReservedStateStartsWithFirstThreeWorkflowEvents(): void
    {
        $fixture = $this->fixture();
        $jobs = new JobRepository(self::$pdo);
        $publicId = $jobs->enqueue(
            'vm.create',
            $fixture['user'],
            $fixture['project'],
            null,
            ['managed_provisioning' => true],
            Uuid::v4(),
        );
        $job = $jobs->find($publicId);
        self::assertIsArray($job);

        $states = new ProvisioningStateRepository(self::$pdo);
        $states->createReserved($publicId, (string) $job['reservation_key'], 'vm-test-2', '10.0.10.11');
        $state = $states->forJob((int) $job['id']);

        self::assertSame('RESERVED', $state['status']);
        self::assertSame(3, (int) $state['current_step']);
        $statement = self::$pdo->prepare(
            'SELECT step,step_name,result FROM vm_provisioning_events WHERE provisioning_id=:id ORDER BY id'
        );
        $statement->execute(['id' => $state['id']]);
        self::assertSame([
            ['step' => 1, 'step_name' => 'Generate hostname', 'result' => 'completed'],
            ['step' => 2, 'step_name' => 'Reserve IP', 'result' => 'completed'],
            ['step' => 3, 'step_name' => 'DB status = RESERVED', 'result' => 'completed'],
        ], array_map(static fn (array $row): array => [
            'step' => (int) $row['step'],
            'step_name' => (string) $row['step_name'],
            'result' => (string) $row['result'],
        ], $statement->fetchAll()));
    }
}
