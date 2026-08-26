<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Integration;

use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Auth\ApiTokenService;
use CloudPortal\Services\Auth\AuthService;
use CloudPortal\Services\Auth\PasswordResetService;
use CloudPortal\Services\Http\IdempotencyService;

final class ApiSecurityHardeningTest extends MariaDbTestCase
{
    public function testApiTokenIsStoredAsHashAndCanBeRevoked(): void
    {
        $fixture = $this->fixture();
        $service = new ApiTokenService(self::$pdo);
        $created = $service->create($fixture['user'], 'automation', ['vm.view'], gmdate('Y-m-d H:i:s', time() + 3600));

        self::assertStringStartsWith('cp_', $created['token']);
        $row = self::$pdo->query('SELECT token_hash,token_prefix FROM api_tokens WHERE id=' . (int) $created['id'])->fetch();
        self::assertIsArray($row);
        self::assertSame(hash('sha256', $created['token']), $row['token_hash']);
        self::assertStringNotContainsString($created['token'], json_encode($row, JSON_THROW_ON_ERROR));

        $authenticated = $service->authenticate($created['token'], '127.0.0.1');
        self::assertIsArray($authenticated);
        self::assertSame($fixture['user'], (int) $authenticated['id']);
        self::assertSame(['vm.view'], $authenticated['api_token_scopes']);

        self::assertTrue($service->revoke($fixture['user'], (int) $created['id']));
        self::assertNull($service->authenticate($created['token'], '127.0.0.1'));
    }

    public function testPasswordResetTokenIsSingleUseAndRevokesApiTokens(): void
    {
        $fixture = $this->fixture();
        $apiTokens = new ApiTokenService(self::$pdo);
        $apiToken = $apiTokens->create($fixture['user'], 'before-reset', ['vm.view']);
        $reset = new PasswordResetService(self::$pdo);
        $token = $reset->issue($fixture['user']);

        self::assertSame($fixture['user'], $reset->consume($token, 'NewPassword!234'));
        $hash = self::$pdo->query('SELECT password_hash FROM users WHERE id=' . (int) $fixture['user'])->fetchColumn();
        self::assertIsString($hash);
        self::assertTrue(password_verify('NewPassword!234', $hash));
        self::assertNull($apiTokens->authenticate($apiToken['token'], '127.0.0.1'));

        $this->expectException(\RuntimeException::class);
        $reset->consume($token, 'AnotherPassword!234');
    }

    public function testIdempotencyReplaysCompletedResponseAndRejectsPayloadReuse(): void
    {
        $fixture = $this->fixture();
        $service = new IdempotencyService(self::$pdo);
        $request = new Request(
            'POST', '/api/v1/vms', [], ['name' => 'vm01'], ['idempotency-key' => 'test-key-12345678'],
            ['REMOTE_ADDR' => '127.0.0.1'], [], '{"name":"vm01"}',
        );
        $context = $service->begin($request, $fixture['user'], null);
        self::assertIsArray($context);
        $response = Response::json(['data' => ['job_id' => 'job-1']], 202);
        $service->complete($context, $response);

        $replayed = $service->begin($request, $fixture['user'], null);
        self::assertInstanceOf(Response::class, $replayed);
        self::assertSame(202, $replayed->status());
        self::assertSame('true', $replayed->headers()['Idempotency-Replayed'] ?? null);
        self::assertSame($response->body(), $replayed->body());

        $different = new Request(
            'POST', '/api/v1/vms', [], ['name' => 'vm02'], ['idempotency-key' => 'test-key-12345678'],
            ['REMOTE_ADDR' => '127.0.0.1'], [], '{"name":"vm02"}',
        );
        try {
            $service->begin($different, $fixture['user'], null);
            self::fail('Reusing an idempotency key with a different payload must fail.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
        }
    }

    public function testBearerScopeCannotEscalateBeyondTokenScope(): void
    {
        $fixture = $this->fixture();
        $token = (new ApiTokenService(self::$pdo))->create($fixture['user'], 'read-only', ['vm.view']);
        $audit = new \CloudPortal\Services\Audit\AuditLogger(self::$pdo);
        $rate = new \CloudPortal\Services\Auth\RateLimiter(self::$pdo, 5, 900, 900);
        $auth = new AuthService(self::$pdo, $rate, $audit);
        $auth->authenticateBearer('Bearer ' . $token['token'], '127.0.0.1');

        self::assertTrue($auth->can('vm.view'));
        self::assertFalse($auth->can('vm.create'));
        self::assertFalse($auth->isAdmin());
    }
}
