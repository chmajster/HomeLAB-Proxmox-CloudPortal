<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Validator;
use CloudPortal\Services\Auth\AccountSecurityService;
use CloudPortal\Services\Auth\MfaService;

final class AuthController
{
    private const MFA_SETUP_SECONDS = 600;

    public function __construct(private readonly Application $app)
    {
    }

    public function loginPage(Request $request): Response
    {
        if ($this->app->auth()->user() !== null) {
            return Response::redirect($this->app->url('/'));
        }
        if ($this->app->auth()->pendingMfaUser() !== null) {
            return Response::redirect($this->app->url('/login/mfa'));
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
            $user = $this->app->auth()->login((string) $data['identity'], (string) $data['password'], $request->ip());
            $mfaRequired = ($user['mfa_required'] ?? false) === true;
            $redirect = $this->app->url($mfaRequired ? '/login/mfa' : '/');
            if ($request->expectsJson()) {
                return Response::json(['data' => [
                    'redirect' => $redirect,
                    'mfa_required' => $mfaRequired,
                ]], $mfaRequired ? 202 : 200);
            }
            return Response::redirect($redirect);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }
            $_SESSION['login_error'] = $exception->getMessage();
            return Response::redirect($this->app->url('/login'));
        }
    }

    public function mfaPage(Request $request): Response
    {
        if ($this->app->auth()->user() !== null) {
            return Response::redirect($this->app->url('/'));
        }
        if ($this->app->auth()->pendingMfaUser() === null) {
            return Response::redirect($this->app->url('/login'));
        }
        return Response::html($this->app->view->render('auth/mfa', [
            'csrf' => $this->app->csrf->token(),
            'error' => $_SESSION['mfa_error'] ?? null,
            'appName' => $this->app->setting('portal.name', $this->app->config->get('app.name')),
            'locale' => $this->app->setting('portal.default_locale', $this->app->config->get('app.locale', 'pl')),
        ], 'layouts/guest'));
    }

    public function mfaVerify(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $pending = $this->app->auth()->pendingMfaUser();
        if ($pending === null) {
            throw new HttpException(401, 'MFA challenge has expired. Sign in again.');
        }
        $data = Validator::validate($request->all(), ['code' => 'required|string|max:32']);
        $mfa = new MfaService($this->app->pdo(), $this->app->crypto());
        if (!$mfa->verify((int) $pending['id'], (string) $data['code'])) {
            try {
                $this->app->auth()->recordMfaFailure($request->ip());
            } catch (HttpException $exception) {
                if ($request->expectsJson()) {
                    throw $exception;
                }
                $_SESSION['login_error'] = $exception->getMessage();
                return Response::redirect($this->app->url('/login'));
            }
            if ($request->expectsJson()) {
                throw new HttpException(401, 'Invalid MFA or recovery code.');
            }
            $_SESSION['mfa_error'] = 'Nieprawidłowy kod MFA lub kod odzyskiwania.';
            return Response::redirect($this->app->url('/login/mfa'));
        }

        $this->app->auth()->completeMfa($request->ip());
        return $request->expectsJson()
            ? Response::json(['data' => ['redirect' => $this->app->url('/'), 'authenticated' => true]])
            : Response::redirect($this->app->url('/'));
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

    public function securityStatus(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $mfa = new MfaService($this->app->pdo(), $this->app->crypto());
        $enabled = $mfa->enabled((int) $user['id']);
        return Response::json(['data' => [
            'mfa_enabled' => $enabled,
            'recovery_codes_remaining' => $enabled ? $mfa->remainingRecoveryCodes((int) $user['id']) : 0,
        ]]);
    }

    public function mfaSetup(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $data = Validator::validate($request->all(), ['current_password' => 'required|string|max:4096']);
        $security = new AccountSecurityService($this->app->pdo());
        if (!$security->verifyPassword((int) $user['id'], (string) $data['current_password'])) {
            throw new HttpException(401, 'Current password is invalid.');
        }

        $mfa = new MfaService($this->app->pdo(), $this->app->crypto());
        $setup = $mfa->createSetup(
            (int) $user['id'],
            (string) $this->app->setting('portal.name', $this->app->config->get('app.name', 'Algen Cloud Portal')),
            (string) ($user['email'] ?: $user['username']),
        );
        $_SESSION['mfa_setup_user_id'] = (int) $user['id'];
        $_SESSION['mfa_setup_at'] = time();
        $_SESSION['mfa_setup_secret'] = $this->app->crypto()->encrypt($setup['secret']);
        $_SESSION['mfa_setup_recovery'] = $this->app->crypto()->encrypt(json_encode($setup['recovery_codes'], JSON_THROW_ON_ERROR));
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'auth.mfa.setup', 'success');

        return Response::json(['data' => $setup]);
    }

    public function mfaEnable(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $data = Validator::validate($request->all(), ['code' => 'required|string|max:16']);
        if ((int) ($_SESSION['mfa_setup_user_id'] ?? 0) !== (int) $user['id']
            || time() - (int) ($_SESSION['mfa_setup_at'] ?? 0) > self::MFA_SETUP_SECONDS
            || !is_string($_SESSION['mfa_setup_secret'] ?? null)
            || !is_string($_SESSION['mfa_setup_recovery'] ?? null)) {
            $this->clearMfaSetup();
            throw new HttpException(409, 'MFA setup has expired. Start setup again.');
        }

        try {
            $secret = $this->app->crypto()->decrypt((string) $_SESSION['mfa_setup_secret']);
            $recovery = json_decode(
                $this->app->crypto()->decrypt((string) $_SESSION['mfa_setup_recovery']),
                true,
                32,
                JSON_THROW_ON_ERROR,
            );
            if (!is_array($recovery)) {
                throw new \RuntimeException('MFA recovery code setup is invalid.');
            }
            (new MfaService($this->app->pdo(), $this->app->crypto()))->enable(
                (int) $user['id'],
                $secret,
                (string) $data['code'],
                array_values(array_map('strval', $recovery)),
            );
            $this->app->audit()->log((int) $user['id'], $request->ip(), 'auth.mfa.enable', 'success');
        } finally {
            $this->clearMfaSetup();
        }

        return Response::json(['data' => ['mfa_enabled' => true]]);
    }

    public function mfaDisable(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $data = Validator::validate($request->all(), [
            'current_password' => 'required|string|max:4096',
            'code' => 'required|string|max:32',
        ]);
        $security = new AccountSecurityService($this->app->pdo());
        if (!$security->verifyPassword((int) $user['id'], (string) $data['current_password'])) {
            throw new HttpException(401, 'Current password is invalid.');
        }
        $mfa = new MfaService($this->app->pdo(), $this->app->crypto());
        if (!$mfa->verify((int) $user['id'], (string) $data['code'])) {
            throw new HttpException(401, 'Invalid MFA or recovery code.');
        }
        $mfa->disable((int) $user['id']);
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'auth.mfa.disable', 'success');
        $this->app->auth()->logout($request->ip());
        return Response::json(['data' => ['mfa_enabled' => false, 'reauthentication_required' => true]]);
    }

    public function changePassword(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $data = Validator::validate($request->all(), [
            'current_password' => 'required|string|max:4096',
            'new_password' => 'required|string|max:4096',
        ]);
        (new AccountSecurityService($this->app->pdo()))->changePassword(
            (int) $user['id'],
            (string) $data['current_password'],
            (string) $data['new_password'],
        );
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'auth.password.change', 'success');
        $this->app->auth()->logout($request->ip());
        return Response::json(['data' => ['password_changed' => true, 'reauthentication_required' => true]]);
    }

    private function clearMfaSetup(): void
    {
        unset($_SESSION['mfa_setup_user_id'], $_SESSION['mfa_setup_at'], $_SESSION['mfa_setup_secret'], $_SESSION['mfa_setup_recovery']);
    }
}
