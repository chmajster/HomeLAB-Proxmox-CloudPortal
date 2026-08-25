<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProxmoxPasswordBootstrapperRequestTest extends TestCase
{
    public function testBootstrapperNeverStoresPasswordAndUsesHttpsOnly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/installer/Services/ProxmoxPasswordBootstrapper.php');

        self::assertStringContainsString("CURLOPT_PROTOCOLS => CURLPROTO_HTTPS", $source);
        self::assertStringContainsString("'password' => (string) (\$config['password'] ?? '')", $source);
        self::assertStringNotContainsString('password_encrypted', $source);
        self::assertStringNotContainsString('password_hash', $source);
    }
}
