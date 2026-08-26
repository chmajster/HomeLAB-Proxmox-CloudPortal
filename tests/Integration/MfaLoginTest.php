<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Security\Crypto;
use CloudPortal\Services\Audit\AuditLogger;
use CloudPortal\Services\Auth\AuthService;
use CloudPortal\Services\Auth\MfaService;
use CloudPortal\Services\Auth\RateLimiter;

final class MfaLoginTest extends MariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    public function testPasswordCreatesPendingChallengeAndMfaCompletesSession(): void
    {
        $fixture = $this->fixture();
        $password = 'Mfa-Test-Password-2026!';
        $crypto = new Crypto(base64_encode(str_repeat('K', 32)));
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        self::$pdo->prepare(
            'UPDATE users SET password_hash=:hash,mfa_enabled=1,mfa_secret_encrypted=:secret WHERE id=:id'
        )->execute([
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'secret' => $crypto->encrypt($secret),
            'id' => $fixture['user'],
        ]);
        $username = (string) self::$pdo->query('SELECT username FROM users WHERE id=' . (int) $fixture['user'])->fetchColumn();
        $auth = new AuthService(self::$pdo, new RateLimiter(self::$pdo, 5, 900, 900), new AuditLogger(self::$pdo));

        $primary = $auth->login($username, $password, '192.0.2.15');
        self::assertTrue($primary['mfa_required']);
        self::assertArrayNotHasKey('user_id', $_SESSION);
        self::assertSame($fixture['user'], (int) $_SESSION['mfa_pending_user_id']);

        $mfa = new MfaService(self::$pdo, $crypto);
        self::assertTrue($mfa->verify($fixture['user'], self::totp($secret, time())));
        $authenticated = $auth->completeMfa('192.0.2.15');
        self::assertFalse($authenticated['mfa_required']);
        self::assertSame($fixture['user'], (int) $_SESSION['user_id']);
        self::assertArrayHasKey('mfa_authenticated_at', $_SESSION);
    }

    public function testRecoveryCodeCanOnlyBeUsedOnce(): void
    {
        $fixture = $this->fixture();
        $crypto = new Crypto(base64_encode(str_repeat('R', 32)));
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        self::$pdo->prepare(
            'UPDATE users SET mfa_enabled=1,mfa_secret_encrypted=:secret WHERE id=:id'
        )->execute(['secret' => $crypto->encrypt($secret), 'id' => $fixture['user']]);
        self::$pdo->prepare('INSERT INTO user_mfa_recovery_codes(user_id,code_hash) VALUES(:user,:hash)')
            ->execute(['user' => $fixture['user'], 'hash' => password_hash('ABCDE12345', PASSWORD_DEFAULT)]);

        $mfa = new MfaService(self::$pdo, $crypto);
        self::assertTrue($mfa->verify($fixture['user'], 'ABCDE-12345'));
        self::assertFalse($mfa->verify($fixture['user'], 'ABCDE-12345'));
        self::assertSame(0, $mfa->remainingRecoveryCodes($fixture['user']));
    }

    private static function totp(string $base32Secret, int $timestamp): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($base32Secret) as $char) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) break;
            $key .= chr(bindec($chunk));
        }
        $counter = intdiv($timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', intdiv($counter, 0x100000000), $counter & 0xffffffff), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
