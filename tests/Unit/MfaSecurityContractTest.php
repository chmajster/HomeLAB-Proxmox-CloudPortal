<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Services\Auth\AccountSecurityService;
use CloudPortal\Services\Auth\MfaService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class MfaSecurityContractTest extends TestCase
{
    public function testTotpMatchesRfc6238Sha1VectorReducedToSixDigits(): void
    {
        /** @var MfaService $service */
        $service = (new ReflectionClass(MfaService::class))->newInstanceWithoutConstructor();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // RFC 6238: ASCII 12345678901234567890

        self::assertTrue($service->verifyTotpSecret($secret, '287082', 59));
        self::assertFalse($service->verifyTotpSecret($secret, '287083', 59));
    }

    public function testPrimaryLoginDoesNotAuthenticateMfaUser(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Auth/AuthService.php');
        $mfaBranch = strpos($source, "if ((int) (\$user['mfa_enabled'] ?? 0) === 1)");
        $pending = strpos($source, "\$_SESSION['mfa_pending_user_id']", $mfaBranch === false ? 0 : $mfaBranch);
        $establish = strpos($source, 'return $this->establishSession($user, $ip, false);');

        self::assertNotFalse($mfaBranch);
        self::assertNotFalse($pending);
        self::assertNotFalse($establish);
        self::assertLessThan($establish, $mfaBranch, 'MFA branch must execute before the normal session is established.');
        self::assertStringContainsString('$this->clearAuthenticatedSession();', $source);
        self::assertStringContainsString('MFA_CHALLENGE_SECONDS = 300', $source);
    }

    public function testRecoveryCodesAreHashedAndConsumedAtomically(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/Auth/MfaService.php');
        self::assertStringContainsString('password_hash($normalized, PASSWORD_DEFAULT)', $source);
        self::assertStringContainsString('password_verify($normalized', $source);
        self::assertStringContainsString('FOR UPDATE', $source);
        self::assertStringContainsString('SET used_at=CURRENT_TIMESTAMP', $source);
        self::assertStringNotContainsString('recovery_code VARCHAR', (string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/1.6.0.sql'));
    }

    public function testPasswordPolicyRequiresLengthAndCharacterDiversity(): void
    {
        /** @var AccountSecurityService $service */
        $service = (new ReflectionClass(AccountSecurityService::class))->newInstanceWithoutConstructor();

        $service->assertPasswordPolicy('Correct-Horse-7Battery');
        self::expectException(\RuntimeException::class);
        $service->assertPasswordPolicy('short1A!');
    }
}
