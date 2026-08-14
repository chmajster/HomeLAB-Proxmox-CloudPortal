<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Router;
use CloudPortal\Http\Validator;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testRouterExtractsOnlyDeclaredParameters(): void
    {
        $router = new Router();
        $router->add('GET', '/api/v1/vms/{id}', static fn (Request $request): Response => Response::json(['id' => $request->param('id')]));
        self::assertInstanceOf(Response::class, $router->dispatch(new Request('GET', '/api/v1/vms/123', [], [], [], [])));
    }

    public function testRouterDistinguishesMethodNotAllowedFromNotFound(): void
    {
        $router = new Router();
        $router->add('GET', '/api/v1/vms/{id}', static fn (): Response => Response::json(['ok' => true]));
        try {
            $router->dispatch(new Request('POST', '/api/v1/vms/123', [], [], [], []));
            self::fail('A wrong HTTP method was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(405, $exception->status);
            self::assertSame(['GET'], $exception->details['allowed_methods']);
        }
    }

    public function testValidationRejectsManualResourceLimitBypass(): void
    {
        $validated = Validator::validate(['plan_id' => '2', 'vcpu' => 999], ['plan_id' => 'required|int|min:1']);
        self::assertSame(['plan_id' => '2'], $validated);
        self::assertArrayNotHasKey('vcpu', $validated);
    }

    public function testValidationReportsInvalidEmail(): void
    {
        $this->expectException(HttpException::class);
        Validator::validate(['email' => 'not-an-email'], ['email' => 'required|email']);
    }

    public function testRawRequestBodySurvivesRouteParameterBinding(): void
    {
        $request = new Request('POST', '/api/v1/admin/iso-uploads/a/chunks', [], [], [], [], [], 'iso-bytes');
        $routed = $request->withRouteParams(['uploadId' => 'abc']);

        self::assertSame('iso-bytes', $routed->rawBody());
        self::assertSame('abc', $routed->param('uploadId'));
    }
}
