<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Validator;
use CloudPortal\Services\Auth\AccountSecurityService;
use CloudPortal\Services\Auth\ApiTokenService;
use CloudPortal\Services\Auth\PasswordResetService;
use CloudPortal\Services\Auth\SessionService;

final class AccountSecurityController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function apiTokens(Request $request): Response
    {
        $user = $this->sessionUser();
        $service = new ApiTokenService($this->app->pdo());
        return Response::json(['data' => [
            'tokens' => $service->listForUser((int) $user['id']),
            'available_scopes' => $service->allowedScopesForUser((int) $user['id']),
        ]]);
    }

    public function createApiToken(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->sessionUser();
        $data = Validator::validate($request->all(), [
            'name' => 'required|string|max:100',
            'current_password' => 'required|string|max:4096',
        ]);
        if (!(new AccountSecurityService($this->app->pdo()))->verifyPassword((int) $user['id'], (string) $data['current_password'])) {
            throw new HttpException(401, 'Current password is invalid.');
        }
        $rawScopes = $request->input('scopes', []);
        if (!is_array($rawScopes) || $rawScopes === []) {
            throw new HttpException(422, 'At least one API token scope is required.');
        }
        $service = new ApiTokenService($this->app->pdo());
        $allowed = $service->allowedScopesForUser((int) $user['id']);
        $requested = array_values(array_unique(array_map('strval', $rawScopes)));
        $invalid = array_values(array_diff($requested, $allowed));
        if ($invalid !== []) {
            throw new HttpException(403, 'API token cannot receive permissions not granted to the current user.', ['invalid_scopes' => $invalid]);
        }
        try {
            $token = $service->create(
                (int) $user['id'],
                (string) $data['name'],
                $requested,
                is_string($request->input('expires_at')) && trim((string) $request->input('expires_at')) !== ''
                    ? (string) $request->input('expires_at') : null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'auth.api_token.create', 'success', 'api_token', $token['id'], [
            'name' => $token['name'], 'prefix' => $token['prefix'], 'scopes' => $token['scopes'], 'expires_at' => $token['expires_at'],
        ]);
        return Response::json(['data' => $token], 201);
    }

    public function revokeApiToken(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->sessionUser();
        $data = Validator::validate($request->all(), ['current_password' => 'required|string|max:4096']);
        if (!(new AccountSecurityService($this->app->pdo()))->verifyPassword((int) $user['id'], (string) $data['current_password'])) {
            throw new HttpException(401, 'Current password is invalid.');
        }
        $tokenId = (int) $request->param('id');
        if (!(new ApiTokenService($this->app->pdo()))->revoke((int) $user['id'], $tokenId)) {
            throw new HttpException(404, 'Active API token was not found.');
        }
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'auth.api_token.revoke', 'success', 'api_token', $tokenId);
        return Response::json(['data' => ['revoked' => true]]);
    }

    public function sessions(Request $request): Response
    {
        $user = $this->sessionUser();
        return Response::json(['data' => (new SessionService($this->app->pdo()))->listForUser((int) $user['id'])]);
    }

    public function revokeSession(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->sessionUser();
        $sessionId = (int) $request->param('id');
        $service = new SessionService($this->app->pdo());
        $current = false;
        foreach ($service->listForUser((int) $user['id']) as $session) {
            if ((int) $session['id'] === $sessionId) {
                $current = ($session['current'] ?? false) === true;
                break;
            }
        }
        if (!$service->revoke((int) $user['id'], $sessionId)) {
            throw new HttpException(404, 'Active session was not found.');
        }
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'auth.session.revoke', 'success', 'user_session', $sessionId, ['current' => $current]);
        if ($current) {
            $this->app->auth()->logout($request->ip());
            return Response::json(['data' => ['revoked' => true, 'reauthentication_required' => true]]);
        }
        return Response::json(['data' => ['revoked' => true, 'reauthentication_required' => false]]);
    }

    public function revokeOtherSessions(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->sessionUser();
        $count = (new SessionService($this->app->pdo()))->revokeOthers((int) $user['id']);
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'auth.session.revoke_others', 'success', null, null, ['revoked_count' => $count]);
        return Response::json(['data' => ['revoked_count' => $count]]);
    }

    public function requestPasswordReset(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $data = Validator::validate($request->all(), ['identity' => 'required|string|max:254']);
        $delivered = (new PasswordResetService($this->app->pdo()))->request(
            (string) $data['identity'],
            (string) $this->app->config->get('app.url', 'http://localhost'),
            (string) $this->app->config->get('security.password_reset_mail_from', 'no-reply@localhost'),
        );
        $this->app->audit()->log(null, $request->ip(), 'auth.password_reset.request', $delivered ? 'success' : 'failure', null, null, [
            'delivery_attempted' => true,
        ]);
        return Response::json(['data' => ['accepted' => true]], 202);
    }

    public function completePasswordReset(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $data = Validator::validate($request->all(), [
            'token' => 'required|string|max:128',
            'new_password' => 'required|string|max:4096',
        ]);
        try {
            $userId = (new PasswordResetService($this->app->pdo()))->consume((string) $data['token'], (string) $data['new_password']);
        } catch (\RuntimeException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        $this->app->audit()->log($userId, $request->ip(), 'auth.password_reset.complete', 'success');
        return Response::json(['data' => ['password_reset' => true, 'all_sessions_revoked' => true, 'api_tokens_revoked' => true]]);
    }

    public function adminIssuePasswordReset(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('users.manage');
        $userId = (int) $request->param('id');
        $exists = $this->app->pdo()->prepare("SELECT 1 FROM users WHERE id=:id AND status='active' LIMIT 1");
        $exists->execute(['id' => $userId]);
        if (!$exists->fetchColumn()) throw new HttpException(404, 'Active user was not found.');
        $token = (new PasswordResetService($this->app->pdo()))->issue($userId);
        $actor = $this->app->auth()->requireUser();
        $this->app->audit()->log((int) $actor['id'], $request->ip(), 'auth.password_reset.admin_issue', 'success', 'user', $userId);
        return Response::json(['data' => ['token' => $token, 'expires_in_seconds' => 1800]], 201);
    }

    /** @return array<string,mixed> */
    private function sessionUser(): array
    {
        $user = $this->app->auth()->requireUser();
        if ($this->app->auth()->isApiToken()) {
            throw new HttpException(403, 'This security operation requires an interactive user session.');
        }
        return $user;
    }
}
