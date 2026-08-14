<?php

declare(strict_types=1);

namespace CloudPortal\Http;

final class Router
{
    /** @var list<array{method:string,pattern:string,handler:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];
        foreach ($this->routes as $route) {
            $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static fn (array $m): string => '(?P<' . $m[1] . '>[A-Za-z0-9._:-]+)', $route['pattern']);
            if (preg_match('#^' . $regex . '$#D', $request->path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $request->method) {
                $allowedMethods[] = $route['method'];
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            return ($route['handler'])($request->withRouteParams($params));
        }

        if ($allowedMethods !== []) {
            throw new HttpException(405, 'Method not allowed.', ['allowed_methods' => array_values(array_unique($allowedMethods))]);
        }

        throw new HttpException(404, 'Resource not found.');
    }
}
