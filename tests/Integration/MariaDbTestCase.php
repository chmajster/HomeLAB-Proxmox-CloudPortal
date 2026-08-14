<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class MariaDbTestCase extends TestCase
{
    protected static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        $dsn = getenv('TEST_DB_DSN') ?: '';
        if ($dsn === '') return;
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/dbname=([^;]*test[^;]*)/i', $dsn)) {
            throw new \RuntimeException('TEST_DB_DSN must be a MySQL/MariaDB database with "test" in its name.');
        }
        self::$pdo = new PDO($dsn, getenv('TEST_DB_USER') ?: '', getenv('TEST_DB_PASSWORD') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$pdo->exec("SET SESSION time_zone = '+00:00'");
        self::$pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
    }

    protected function setUp(): void
    {
        if (!self::$pdo) self::markTestSkipped('Set TEST_DB_DSN to an isolated MariaDB test database.');
        self::$pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (self::$pdo?->inTransaction()) self::$pdo->rollBack();
    }

    protected function fixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $role = (int) self::$pdo->query("SELECT id FROM roles WHERE slug='user'")->fetchColumn();
        $insert = self::$pdo->prepare('INSERT INTO users(role_id,username,email,password_hash) VALUES(:role,:name,:email,:password)');
        $insert->execute(['role'=>$role,'name'=>'u'.$suffix,'email'=>'u'.$suffix.'@example.test','password'=>password_hash('password-for-tests', PASSWORD_DEFAULT)]);
        $user = (int) self::$pdo->lastInsertId();
        $projectInsert = self::$pdo->prepare('INSERT INTO projects(name,slug) VALUES(:name,:slug)');
        $projectInsert->execute(['name'=>'Project '.$suffix,'slug'=>'project-'.$suffix]);
        $project = (int) self::$pdo->lastInsertId();
        self::$pdo->prepare('INSERT INTO project_users(project_id,user_id) VALUES(:project,:user)')->execute(compact('project','user'));
        return compact('user','project');
    }
}
