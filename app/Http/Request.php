<?php

declare(strict_types=1);

namespace CloudPortal\Http;

final class Request
{
    /** @param array<string,string> $routeParams */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $server,
        private readonly array $routeParams = [],
        private readonly string $rawBody = '',
    ) {
    }

    public static function capture(string $basePath = ''): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!is_array($headers)) {
            $headers = [];
        }
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] ??= $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] ??= $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH']) && is_string($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] ??= $_SERVER['CONTENT_LENGTH'];
        }
        $maxRawBody = 9 * 1024 * 1024;
        $contentLength = filter_var($headers['Content-Length'] ?? $headers['content-length'] ?? null, FILTER_VALIDATE_INT);
        if ($contentLength !== false && $contentLength > $maxRawBody) {
            throw new HttpException(413, 'Request body is too large.');
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxRawBody + 1) ?: '';
        if (strlen($raw) > $maxRawBody) {
            throw new HttpException(413, 'Request body is too large.');
        }
        $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
        $body = $_POST;
        if (str_contains($contentType, 'application/json') && $raw !== '') {
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
                $body = is_array($decoded) ? $decoded : [];
            } catch (\JsonException) {
                throw new HttpException(400, 'Invalid JSON request body.');
            }
        }

        $uri = '/' . ltrim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
        $basePath = '/' . trim($basePath, '/');
        if ($basePath !== '/' && ($uri === $basePath || str_starts_with($uri, $basePath . '/'))) {
            $uri = substr($uri, strlen($basePath)) ?: '/';
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . ltrim($uri, '/'),
            $_GET,
            $body,
            array_change_key_case($headers, CASE_LOWER),
            $_SERVER,
            [],
            $raw,
        );
    }

    /** @param array<string,string> $params */
    public function withRouteParams(array $params): self
    {
        return new self($this->method, $this->path, $this->query, $this->body, $this->headers, $this->server, $params, $this->rawBody);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function param(string $key): string
    {
        return $this->routeParams[$key] ?? throw new HttpException(500, 'Missing route parameter.');
    }

    public function header(string $key, ?string $default = null): ?string
    {
        return isset($this->headers[strtolower($key)]) ? (string) $this->headers[strtolower($key)] : $default;
    }

    public function ip(): string
    {
        return filter_var($this->server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }

    public function expectsJson(): bool
    {
        return str_starts_with($this->path, '/api/') || str_contains((string) $this->header('accept'), 'application/json');
    }
}
