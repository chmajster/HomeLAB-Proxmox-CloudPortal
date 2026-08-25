<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use PDOException;

final class CloudInitMigrationCompatibilityTest extends MariaDbTestCase
{
    public function testCloudInitProfileScopeIndexesWorkWithoutFkGeneratedColumnDependency(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();

        $insert = self::$pdo->prepare(
            'INSERT INTO cloud_init_profiles (owner_user_id,name,is_global,created_by) VALUES (:owner,:name,:global,:creator)'
        );

        $insert->execute([
            'owner' => $first['user'],
            'name' => 'shared-name',
            'global' => 0,
            'creator' => $first['user'],
        ]);
        $insert->execute([
            'owner' => $second['user'],
            'name' => 'shared-name',
            'global' => 0,
            'creator' => $second['user'],
        ]);

        $insert->execute([
            'owner' => null,
            'name' => 'global-name',
            'global' => 1,
            'creator' => $first['user'],
        ]);

        try {
            $insert->execute([
                'owner' => null,
                'name' => 'global-name',
                'global' => 1,
                'creator' => $second['user'],
            ]);
            self::fail('Duplicate global Cloud-Init profile names must be rejected.');
        } catch (PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
        }

        $create = (string) self::$pdo->query('SHOW CREATE TABLE cloud_init_profiles')->fetchColumn(1);
        self::assertStringContainsString('global_name', $create);
        self::assertStringNotContainsString('owner_scope', $create);
        self::assertStringContainsString('fk_cloud_init_profiles_owner', $create);
    }
}
