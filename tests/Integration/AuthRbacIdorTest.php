<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\Auth\AuthService;
use CloudPortal\Services\Auth\RateLimiter;
use CloudPortal\Services\Provisioning\VmOperationService;
use CloudPortal\Support\Config;

final class AuthRbacIdorTest extends MariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testAuthenticationAndRbacUseDatabasePermissions(): void
    {
        $f = $this->fixture();
        $password = 'valid-password-for-test';
        self::$pdo->prepare('UPDATE users SET password_hash=:hash WHERE id=:id')->execute(['hash'=>password_hash($password, PASSWORD_DEFAULT),'id'=>$f['user']]);
        $auth = new AuthService(self::$pdo, new RateLimiter(self::$pdo, 5, 900, 900), new AuditLogger(self::$pdo));
        $user = $auth->login('u' . substr((string) self::$pdo->query('SELECT username FROM users WHERE id=' . $f['user'])->fetchColumn(), 1), $password, '192.0.2.1');
        self::assertSame($f['user'], (int) $user['id']);
        self::assertTrue($auth->can('vm.create'));
        self::assertFalse($auth->can('admin.access'));
    }

    public function testIdorReturnsNotFoundForAnotherUsersVm(): void
    {
        $owner = $this->fixture();
        $other = $this->fixture();
        self::$pdo->exec("INSERT INTO proxmox_connections(name,hostname,api_token_id,api_token_secret_encrypted) VALUES('idor-cluster','pve.test','u!t','encrypted')");
        $connection = (int) self::$pdo->lastInsertId();
        $statement = self::$pdo->prepare("INSERT INTO virtual_machines(connection_id,project_id,owner_user_id,vmid,node_name,name,status,vcpu,ram_mb,disk_gb) VALUES(:connection,:project,:owner,101,'pve','private-vm','stopped',2,2048,20)");
        $statement->execute(['connection'=>$connection,'project'=>$owner['project'],'owner'=>$owner['user']]);
        $vm = (int) self::$pdo->lastInsertId();
        $database = new Database(new Config([]), self::$pdo);
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Virtual machine not found.');
        (new VmOperationService($database))->accessibleVm($vm, $other['user'], false);
    }
}

