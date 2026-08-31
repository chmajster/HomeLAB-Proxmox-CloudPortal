<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterWildcardTest extends TestCase
{
    public function testWildcardParameterCapturesNestedAssetPath(): void
    {
        $router = new Router();
        $captured = null;
        $router->add('GET', '/console/novnc/{connectionId}/{asset*}', static function (Request $request) use (&$captured): Response {
            $captured = [$request->param('connectionId'), $request->param('asset')];
            return Response::json(['ok' => true]);
        });

        $response = $router->dispatch(new Request('GET', '/console/novnc/7/core/util/logging.js', [], [], [], []));

        self::assertSame(200, $response->status());
        self::assertSame(['7', 'core/util/logging.js'], $captured);
    }

    public function testWildcardDoesNotAcceptTraversalSegments(): void
    {
        $router = new Router();
        $router->add('GET', '/console/novnc/{connectionId}/{asset*}', static fn (): Response => Response::json(['ok' => true]));

        $this->expectException(\CloudPortal\Http\HttpException::class);
        $router->dispatch(new Request('GET', '/console/novnc/7/../../config/runtime.php', [], [], [], []));
    }
}
