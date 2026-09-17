<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Http\Request;
use CloudPortal\Http\StaticAssets;
use PHPUnit\Framework\TestCase;

final class StaticAssetsTest extends TestCase
{
    private function request(string $path, string $method = 'GET'): Request
    {
        return new Request($method, $path, [], [], [], []);
    }

    public function testBundledAssetsHaveTheirOriginalContentAndCorrectMimeTypes(): void
    {
        $directory = dirname(__DIR__, 2) . '/public/assets';
        $assets = new StaticAssets($directory);
        foreach ([
            'css/app.css' => 'text/css; charset=utf-8',
            'js/installer.js' => 'application/javascript; charset=utf-8',
            'icons.svg' => 'image/svg+xml',
        ] as $path => $type) {
            $response = $assets->respond($this->request('/assets/' . $path));
            self::assertNotNull($response);
            self::assertSame(200, $response->status());
            self::assertSame($type, $response->headers()['Content-Type']);
            self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
            self::assertSame(file_get_contents($directory . '/' . $path), $response->body());
            self::assertArrayNotHasKey('Location', $response->headers());
        }
    }

    public function testHeadHasNoBodyAndOtherMethodsAreRejected(): void
    {
        $assets = new StaticAssets(dirname(__DIR__, 2) . '/public/assets');
        $head = $assets->respond($this->request('/assets/css/app.css', 'HEAD'));
        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());
        self::assertGreaterThan(0, (int) $head->headers()['Content-Length']);
        $post = $assets->respond($this->request('/assets/css/app.css', 'POST'));
        self::assertSame(405, $post->status());
        self::assertSame('GET, HEAD', $post->headers()['Allow']);
    }

    public function testMissingAndUnsafePathsNeverRedirectToInstaller(): void
    {
        $assets = new StaticAssets(dirname(__DIR__, 2) . '/public/assets');
        foreach ([
            '/assets', '/assets/', '/assets/css/missing.css', '/assets/css',
            '/assets/../index.php', '/assets/%2e%2e/index.php',
            '/assets/css/../icons.svg', '/assets/css/%2e%2e%2ficons.svg',
            '/assets/css%5capp.css', '/assets/css/app.css%00',
            '/assets/.hidden.css', '/assets/css/app.css/extra',
        ] as $path) {
            $response = $assets->respond($this->request($path));
            self::assertSame(404, $response->status(), $path);
            self::assertArrayNotHasKey('Location', $response->headers());
        }
        self::assertNull($assets->respond($this->request('/install')));
        self::assertNull($assets->respond($this->request('/assets-other/file.css')));
    }

    public function testPhpFilesAndSymlinksOutsideAssetDirectoryAreNotServed(): void
    {
        $directory = sys_get_temp_dir() . '/portal-assets-' . bin2hex(random_bytes(8));
        mkdir($directory . '/assets', 0700, true);
        file_put_contents($directory . '/assets/secret.php', '<?php /* secret */');
        file_put_contents($directory . '/private.css', 'private data');
        try {
            $assets = new StaticAssets($directory . '/assets');
            self::assertSame(404, $assets->respond($this->request('/assets/secret.php'))->status());
            if (!@symlink($directory . '/private.css', $directory . '/assets/leak.css')) {
                self::markTestSkipped('Symlinks are unavailable on this platform.');
            }
            self::assertSame(404, $assets->respond($this->request('/assets/leak.css'))->status());
        } finally {
            if (is_link($directory . '/assets/leak.css')) unlink($directory . '/assets/leak.css');
            unlink($directory . '/assets/secret.php');
            unlink($directory . '/private.css');
            rmdir($directory . '/assets');
            rmdir($directory);
        }
    }
}
