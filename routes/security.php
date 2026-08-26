<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Controllers\AuthController;
use CloudPortal\Controllers\ProxmoxPreflightController;
use CloudPortal\Controllers\SystemController;
use CloudPortal\Http\Router;

return static function (Router $router, Application $app): void {
    $auth = new AuthController($app);
    $system = new SystemController($app);
    $preflight = new ProxmoxPreflightController($app);

    $router->add('GET', '/metrics', [$system, 'metrics']);

    $router->add('GET', '/api/v1/me/security', [$auth, 'securityStatus']);
    $router->add('POST', '/api/v1/me/mfa/setup', [$auth, 'mfaSetup']);
    $router->add('POST', '/api/v1/me/mfa/enable', [$auth, 'mfaEnable']);
    $router->add('DELETE', '/api/v1/me/mfa', [$auth, 'mfaDisable']);
    $router->add('POST', '/api/v1/me/password', [$auth, 'changePassword']);

    $router->add('GET', '/api/v1/admin/proxmox/{connectionId}/preflight', [$preflight, 'show']);
};
