<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

use CloudPortal\Services\Auth\AuthService;
use PDO;

final class AdministratorInstaller
{
    /** @param array{username:string,email:string,password:string,resume:bool,test_account?:bool} $input */
    public function create(PDO $pdo, array $input, ?int $knownId = null): int
    {
        if ($knownId !== null) {
            $known = $pdo->prepare("SELECT 1 FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id AND r.slug='admin'");
            $known->execute(['id' => $knownId]);
            if ($known->fetchColumn()) return $knownId;
        }
        $pdo->beginTransaction();
        try {
            $role = $pdo->query("SELECT id FROM roles WHERE slug='admin' FOR UPDATE")->fetchColumn();
            if ($role === false) throw new \RuntimeException('Administrator role is missing from the initialized schema.');
            $existingUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $duplicate = $pdo->prepare('SELECT u.id,u.username,u.email,u.password_hash,u.status,r.slug AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.username=:username OR u.email=:email LIMIT 1');
            $duplicate->execute(['username' => $input['username'], 'email' => $input['email']]);
            $existing = $duplicate->fetch();
            if (is_array($existing)) {
                $same = hash_equals((string) $existing['username'], $input['username'])
                    && hash_equals(mb_strtolower((string) $existing['email']), mb_strtolower($input['email']));
                if (!$input['resume'] || !$same || $existing['role'] !== 'admin' || $existing['status'] !== 'active' || !password_verify($input['password'], (string) $existing['password_hash'])) {
                    throw new \RuntimeException('Administrator username or email already exists. Use the exact existing administrator credentials to resume an interrupted installation.');
                }
                $pdo->commit();
                return (int) $existing['id'];
            }
            if ($existingUsers > 0) throw new \RuntimeException('This database already contains users. Select a fresh database or resume with the existing administrator.');
            $insert = $pdo->prepare('INSERT INTO users(role_id,username,email,password_hash,status) VALUES(:role,:username,:email,:password,\'active\')');
            $insert->execute(['role' => $role, 'username' => $input['username'], 'email' => $input['email'], 'password' => AuthService::hashPassword($input['password'])]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}
