<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\Auth\AuthService;
use PHPUnit\Framework\TestCase;

final class AuthAndPasswordTest extends TestCase
{
    public function testPasswordsAreOneWayHashed(): void
    {
        $password = 'long-production-password';
        $hash = AuthService::hashPassword($password);
        self::assertNotSame($password, $hash);
        self::assertTrue(password_verify($password, $hash));
    }
}

