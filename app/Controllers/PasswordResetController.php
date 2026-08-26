<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Validator;
use CloudPortal\Services\Auth\PasswordResetService;

final class PasswordResetController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function requestPage(Request $request): Response
    {
        if ($this->app->auth()->user() !== null) return Response::redirect($this->app->url('/security'));
        return Response::html($this->app->view->render('auth/forgot-password', [
            'csrf' => $this->app->csrf->token(),
            'message' => $_SESSION['password_reset_message'] ?? null,
            'error' => $_SESSION['password_reset_error'] ?? null,
            'appName' => $this->app->setting('portal.name', $this->app->config->get('app.name')),
            'locale' => $this->app->setting('portal.default_locale', $this->app->config->get('app.locale', 'pl')),
        ], 'layouts/guest'));
    }

    public function requestSubmit(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $data = Validator::validate($request->all(), ['identity' => 'required|string|max:254']);
        (new PasswordResetService($this->app->pdo()))->request(
            (string) $data['identity'],
            (string) $this->app->config->get('app.url', 'http://localhost'),
            (string) $this->app->config->get('security.password_reset_mail_from', 'no-reply@localhost'),
        );
        $this->app->audit()->log(null, $request->ip(), 'auth.password_reset.request', 'success', null, null, ['public_flow' => true]);
        $_SESSION['password_reset_message'] = 'Jeśli podane konto istnieje, wiadomość z linkiem resetującym została wysłana.';
        unset($_SESSION['password_reset_error']);
        return Response::redirect($this->app->url('/forgot-password'));
    }

    public function resetPage(Request $request): Response
    {
        $token = trim((string) $request->query('token', ''));
        return Response::html($this->app->view->render('auth/password-reset', [
            'csrf' => $this->app->csrf->token(),
            'token' => $token,
            'error' => $_SESSION['password_reset_error'] ?? null,
            'appName' => $this->app->setting('portal.name', $this->app->config->get('app.name')),
            'locale' => $this->app->setting('portal.default_locale', $this->app->config->get('app.locale', 'pl')),
        ], 'layouts/guest'));
    }

    public function resetSubmit(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $data = Validator::validate($request->all(), [
            'token' => 'required|string|max:128',
            'new_password' => 'required|string|max:4096',
        ]);
        try {
            $userId = (new PasswordResetService($this->app->pdo()))->consume((string) $data['token'], (string) $data['new_password']);
            $this->app->audit()->log($userId, $request->ip(), 'auth.password_reset.complete', 'success', null, null, ['public_flow' => true]);
            $_SESSION['login_error'] = 'Hasło zostało zmienione. Zaloguj się ponownie.';
            unset($_SESSION['password_reset_error']);
            return Response::redirect($this->app->url('/login'));
        } catch (\Throwable $exception) {
            $_SESSION['password_reset_error'] = $exception->getMessage();
            return Response::redirect($this->app->url('/password-reset?token=' . rawurlencode((string) $data['token'])));
        }
    }
}
