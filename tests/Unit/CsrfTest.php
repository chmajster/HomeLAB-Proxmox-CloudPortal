<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Security\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testMutatingRequestRequiresMatchingToken(): void
    {
        $csrf = new Csrf();
        $token = $csrf->token();
        $request = new Request('POST', '/api/v1/vms', [], [], ['x-csrf-token' => $token], []);
        $csrf->verify($request);
        self::assertTrue(true);
    }

    public function testInvalidTokenIsRejected(): void
    {
        $csrf = new Csrf();
        $csrf->token();
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        $csrf->verify(new Request('DELETE', '/api/v1/vms/1', [], [], ['x-csrf-token' => 'invalid'], []));
    }
}

