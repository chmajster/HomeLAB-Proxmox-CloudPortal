<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Controllers\AccountSecurityController;
use CloudPortal\Controllers\AuthController;
use CloudPortal\Controllers\ProxmoxPreflightController;
use CloudPortal\Controllers\SystemController;
use CloudPortal\Http\Router;

return static function (Router $router, Application $app): void {
    $auth = new AuthController($app);
    $account = new AccountSecurityController($app);
    $system = new SystemController($app);
    $preflight = new ProxmoxPreflightController($app);

    $router->add('GET', '/metrics', [$system, 'metrics']);

    $router->add('GET', '/api/v1/me/security', [$auth, 'securityStatus']);
    $router->add('POST', '/api/v1/me/mfa/setup', [$auth, 'mfaSetup']);
    $router->add('POST', '/api/v1/me/mfa/enable', [$auth, 'mfaEnable']);
    $router->add('DELETE', '/api/v1/me/mfa', [$auth, 'mfaDisable']);
    $router->add('POST', '/api/v1/me/password', [$auth, 'changePassword']);

    $router->add('GET', '/api/v1/me/api-tokens', [$account, 'apiTokens']);
    $router->add('POST', '/api/v1/me/api-tokens', [$account, 'createApiToken']);
    $router->add('DELETE', '/api/v1/me/api-tokens/{id}', [$account, 'revokeApiToken']);
    $router->add('GET', '/api/v1/me/sessions', [$account, 'sessions']);
    $router->add('DELETE', '/api/v1/me/sessions/{id}', [$account, 'revokeSession']);
    $router->add('POST', '/api/v1/me/sessions/revoke-others', [$account, 'revokeOtherSessions']);

    $router->add('POST', '/api/v1/auth/password-reset/request', [$account, 'requestPasswordReset']);
    $router->add('POST', '/api/v1/auth/password-reset/complete', [$account, 'completePasswordReset']);
    $router->add('POST', '/api/v1/admin/users/{id}/password-reset-token', [$account, 'adminIssuePasswordReset']);

    $router->add('GET', '/api/v1/admin/proxmox/{connectionId}/preflight', [$preflight, 'show']);
};
