<?php

declare(strict_types=1);

namespace CloudPortal\Http;

/** Serves bundled assets when the web server forwards /assets/* to PHP. */
final class StaticAssets
{
    public function __construct(private readonly string $directory)
    {
    }

    public function respond(Request $request): ?Response
    {
        if ($request->path !== '/assets' && !str_starts_with($request->path, '/assets/')) {
            return null;
        }

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store'];
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            return new Response('', 405, [...$headers, 'Allow' => 'GET, HEAD']);
        }

        $missing = new Response('', 404, [...$headers, 'Content-Type' => 'text/plain; charset=utf-8']);
        $relative = rawurldecode(substr($request->path, strlen('/assets/')));
        foreach (explode('/', $relative) as $segment) {
            // Validate decoded segments to reject traversal and hidden files.
            if (preg_match('/\A[A-Za-z0-9_-][A-Za-z0-9._-]*\z/', $segment) !== 1) {
                return $missing;
            }
        }

        $contentType = match (strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => null,
        };
        if ($contentType === null) return $missing;

        $directory = realpath($this->directory);
        $file = $directory === false ? false : realpath($directory . '/' . $relative);
        if ($file === false || !str_starts_with($file, $directory . DIRECTORY_SEPARATOR)
            || !is_file($file) || !is_readable($file)) {
            return $missing;
        }

        $body = file_get_contents($file);
        if ($body === false) return $missing;

        return new Response($request->method === 'HEAD' ? '' : $body, 200, [
            ...$headers,
            'Content-Type' => $contentType,
            'Content-Length' => (string) strlen($body),
        ]);
    }
}
