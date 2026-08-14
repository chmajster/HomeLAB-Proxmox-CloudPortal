<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Validator;

final class AuthController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function loginPage(Request $request): Response
    {
        if ($this->app->auth()->user() !== null) {
            return Response::redirect($this->app->url('/'));
        }
        return Response::html($this->app->view->render('auth/login', [
            'csrf' => $this->app->csrf->token(),
            'error' => $_SESSION['login_error'] ?? null,
            'appName' => $this->app->setting('portal.name', $this->app->config->get('app.name')),
            'locale' => $this->app->setting('portal.default_locale', $this->app->config->get('app.locale', 'pl')),
        ], 'layouts/guest'));
    }

    public function login(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $data = Validator::validate($request->all(), [
            'identity' => 'required|string|max:254',
            'password' => 'required|string|max:4096',
        ]);
        try {
            $this->app->auth()->login((string) $data['identity'], (string) $data['password'], $request->ip());
            return $request->expectsJson() ? Response::json(['data' => ['redirect' => $this->app->url('/')]]) : Response::redirect($this->app->url('/'));
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }
            $_SESSION['login_error'] = $exception->getMessage();
            return Response::redirect($this->app->url('/login'));
        }
    }

    public function logout(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->logout($request->ip());
        return $request->expectsJson() ? Response::json(['data' => ['logged_out' => true]]) : Response::redirect($this->app->url('/login'));
    }

    public function me(Request $request): Response
    {
        return Response::json(['data' => $this->app->auth()->requireUser(), 'csrf_token' => $this->app->csrf->token()]);
    }
}
