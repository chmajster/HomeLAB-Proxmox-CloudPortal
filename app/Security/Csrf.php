<?php

declare(strict_types=1);

namespace CloudPortal\Security;

use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;

final class Csrf
{
    public function token(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public function verify(Request $request): void
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        $provided = $request->header('x-csrf-token') ?? (is_string($request->input('_csrf')) ? $request->input('_csrf') : '');
        if ($provided === '' || !hash_equals($this->token(), $provided)) {
            throw new HttpException(419, 'CSRF token mismatch.');
        }
    }
}

