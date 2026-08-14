<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Security\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function testSecretsAreAuthenticatedAndNotStoredAsPlaintext(): void
    {
        $crypto = new Crypto(base64_encode(random_bytes(32)));
        $secret = 'proxmox-token-secret';
        $encrypted = $crypto->encrypt($secret);

        self::assertNotSame($secret, $encrypted);
        self::assertStringNotContainsString($secret, $encrypted);
        self::assertSame($secret, $crypto->decrypt($encrypted));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $crypto = new Crypto(base64_encode(random_bytes(32)));
        $payload = base64_decode($crypto->encrypt('secret'), true);
        self::assertIsString($payload);
        $payload[30] = chr(ord($payload[30]) ^ 1);

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt(base64_encode($payload));
    }
}

