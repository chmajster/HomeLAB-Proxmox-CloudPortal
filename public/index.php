<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Router;
use CloudPortal\Installer\Services\JsonInstaller;
use CloudPortal\Security\Headers;
use CloudPortal\Services\Http\IdempotencyService;
use CloudPortal\Support\Uuid;

$root = dirname(__DIR__);
$maintenancePath = $root . '/storage/maintenance.json';
if (is_file($maintenancePath)) {
    $maintenance = json_decode((string) @file_get_contents($maintenancePath), true);
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $message = 'Portal is temporarily unavailable while maintenance is in progress.';
    $startedAt = null;
    if (is_array($maintenance)) {
        $candidate = trim((string) ($maintenance['message'] ?? ''));
        if ($candidate !== '') $message = mb_substr($candidate, 0, 300);
        $startedAt = isset($maintenance['started_at']) ? (string) $maintenance['started_at'] : null;
    }
    http_response_code(503);
    header('Retry-After: 60');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    if (str_contains($accept, 'application/json') || str_contains($requestPath, '/api/') || str_ends_with($requestPath, '/healthz') || str_ends_with($requestPath, '/readyz')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['message' => $message, 'maintenance' => true, 'started_at' => $startedAt]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . "\n";
    }
    exit;
}

$autoload = is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Application autoloader is missing. Upload the complete release ZIP again.\n";
    exit;
}
require $autoload;

$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
Headers::apply($https);
header('Cache-Control: no-store, private');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
$app = new Application($root);
date_default_timezone_set((string) $app->config->get('app.timezone', 'UTC'));
session_name((string) $app->config->get('session.name', 'cloud_portal_session'));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $app->basePath() ?: '/',
    'domain' => '',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
if (isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > (int) $app->config->get('session.lifetime', 7200)) {
    $_SESSION = [];
    session_regenerate_id(true);
}
$_SESSION['last_activity'] = time();

$idempotencyService = null;
$idempotencyContext = null;

try {
    $request = Request::capture($app->basePath());
    $providedCorrelation = strtolower(trim((string) $request->header('x-correlation-id', '')));
    $correlationId = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $providedCorrelation) === 1
        ? $providedCorrelation
        : Uuid::v4();
    $_SERVER['CLOUD_PORTAL_CORRELATION_ID'] = $correlationId;
    header('X-Correlation-ID: ' . $correlationId);

    $jsonInstallPath = $root . '/install.json';
    if (!$app->installed() && in_array($request->path, ['/install', '/install/'], true) && is_file($jsonInstallPath)) {
        try {
            (new JsonInstaller($app))->run($jsonInstallPath);
            Response::redirect($app->url('/install/finish'))->send();
        } catch (Throwable $exception) {
            throw new HttpException(
                500,
                'Automatic installation from install.json failed. Review storage/logs/installer.log, correct or remove install.json, and retry.',
            );
        }
    }
    if (!$app->installed() && !str_starts_with($request->path, '/install')) {
        if (str_starts_with($request->path, '/api/')) throw new HttpException(503, 'Application installation has not been completed.');
        Response::redirect($app->url('/install'))->send();
    }
    if ($app->installed() && str_starts_with($request->path, '/install') && $request->path !== '/install/finish') {
        throw new HttpException(403, 'Application already installed.');
    }

    if ($app->installed() && str_starts_with($request->path, '/api/')) {
        $app->auth()->authenticateBearer($request->header('authorization'), $request->ip());
        if (trim((string) $request->header('idempotency-key', '')) !== '') {
            $idempotencyService = new IdempotencyService($app->pdo());
            $begin = $idempotencyService->begin($request, $app->auth()->id(), $app->auth()->apiTokenId());
            if ($begin instanceof Response) {
                $begin->send();
            }
            $idempotencyContext = is_array($begin) ? $begin : null;
        }
    }

    $router = new Router();
    (require $root . '/routes/api.php')($router, $app);
    (require $root . '/routes/security.php')($router, $app);
    (require $root . '/routes/web.php')($router, $app);
    $response = $router->dispatch($request);
    if ($idempotencyService instanceof IdempotencyService) {
        $idempotencyService->complete($idempotencyContext, $response);
    }
    $response->send();
} catch (HttpException $exception) {
    if ($idempotencyService instanceof IdempotencyService) {
        $idempotencyService->release($idempotencyContext);
    }
    $jsonRequest = isset($request) ? $request->expectsJson() : str_starts_with((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/api/');
    if ($jsonRequest) {
        Response::json(['error' => ['message' => $exception->getMessage(), ...$exception->details]], $exception->status)->send();
    }
    Response::html($app->view->render('errors/http', ['status' => $exception->status, 'message' => $exception->getMessage()], 'layouts/guest'), $exception->status)->send();
} catch (Throwable $exception) {
    if ($idempotencyService instanceof IdempotencyService) {
        $idempotencyService->release($idempotencyContext);
    }
    error_log($exception->__toString());
    $message = $app->config->get('app.debug', false) ? $exception->getMessage() : 'An unexpected error occurred.';
    $jsonRequest = isset($request) ? $request->expectsJson() : str_starts_with((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/api/');
    if ($jsonRequest) {
        Response::json(['error' => ['message' => $message]], 500)->send();
    }
    Response::html($app->view->render('errors/http', ['status' => 500, 'message' => $message], 'layouts/guest'), 500)->send();
}
