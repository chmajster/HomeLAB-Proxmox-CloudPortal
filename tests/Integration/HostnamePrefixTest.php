<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Services\Provisioning\HostnameGenerator;

final class HostnamePrefixTest extends MariaDbTestCase
{
    public function testConfiguredPrefixIsPrependedToGeneratedHostname(): void
    {
        $fixture = $this->fixture();
        $statement = self::$pdo->prepare(
            'INSERT INTO settings(setting_key,value,is_public,updated_by) VALUES(:key,:value,0,:user) '
            . 'ON DUPLICATE KEY UPDATE value=VALUES(value),updated_by=VALUES(updated_by)'
        );
        $statement->execute([
            'key' => 'hostname_generator.prefix',
            'value' => json_encode('lab-', JSON_THROW_ON_ERROR),
            'user' => $fixture['user'],
        ]);

        $hostname = (new HostnameGenerator(self::$pdo, 'vm-{project}-{counter:03}'))
            ->generate($fixture['project'], $fixture['user']);

        self::assertStringStartsWith('lab-vm-', $hostname);
        self::assertStringEndsWith('-001', $hostname);
        self::assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $hostname);
    }

    public function testInvalidStoredPrefixIsNormalizedBeforeUse(): void
    {
        $fixture = $this->fixture();
        self::$pdo->prepare(
            'INSERT INTO settings(setting_key,value,is_public,updated_by) VALUES(:key,:value,0,:user) '
            . 'ON DUPLICATE KEY UPDATE value=VALUES(value),updated_by=VALUES(updated_by)'
        )->execute([
            'key' => 'hostname_generator.prefix',
            'value' => json_encode('LAB HOME-', JSON_THROW_ON_ERROR),
            'user' => $fixture['user'],
        ]);

        $hostname = (new HostnameGenerator(self::$pdo, 'vm-{counter}'))
            ->generate($fixture['project'], $fixture['user']);

        self::assertStringStartsWith('lab-home-vm-', $hostname);
    }
}
