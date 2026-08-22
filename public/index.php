<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Router;
use CloudPortal\Installer\Services\JsonInstaller;
use CloudPortal\Security\Headers;

$root = dirname(__DIR__);
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

try {
    $request = Request::capture($app->basePath());
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
    $router = new Router();
    (require $root . '/routes/api.php')($router, $app);
    (require $root . '/routes/web.php')($router, $app);
    $router->dispatch($request)->send();
} catch (HttpException $exception) {
    $jsonRequest = isset($request) ? $request->expectsJson() : str_starts_with((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/api/');
    if ($jsonRequest) {
        Response::json(['error' => ['message' => $exception->getMessage(), ...$exception->details]], $exception->status)->send();
    }
    Response::html($app->view->render('errors/http', ['status' => $exception->status, 'message' => $exception->getMessage()], 'layouts/guest'), $exception->status)->send();
} catch (Throwable $exception) {
    error_log($exception->__toString());
    $message = $app->config->get('app.debug', false) ? $exception->getMessage() : 'An unexpected error occurred.';
    $jsonRequest = isset($request) ? $request->expectsJson() : str_starts_with((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/api/');
    if ($jsonRequest) {
        Response::json(['error' => ['message' => $message]], 500)->send();
    }
    Response::html($app->view->render('errors/http', ['status' => 500, 'message' => $message], 'layouts/guest'), 500)->send();
}
