<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\CloudInit\CloudInitProfileService;
use CloudPortal\Services\CloudInit\SshKeyService;

final class CloudInitController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function sshKeys(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        return Response::json(['data' => (new SshKeyService($this->app->pdo()))->list((int) $user['id'])]);
    }

    public function createSshKey(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $id = (new SshKeyService($this->app->pdo()))->create((int) $user['id'], (string) $request->input('name', ''), (string) $request->input('public_key', ''));
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'ssh_key.create', 'success', 'ssh_key', $id);
        return Response::json(['data' => ['id' => $id]], 201);
    }

    public function deleteSshKey(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $id = (int) $request->param('id');
        (new SshKeyService($this->app->pdo()))->delete((int) $user['id'], $id);
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'ssh_key.delete', 'success', 'ssh_key', $id);
        return Response::json(['data' => ['deleted' => true]]);
    }

    public function profiles(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        return Response::json(['data' => (new CloudInitProfileService($this->app->pdo()))->listForUser((int) $user['id'], $this->app->auth()->isAdmin())]);
    }

    public function createProfile(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $id = (new CloudInitProfileService($this->app->pdo()))->create((int) $user['id'], $this->app->auth()->isAdmin(), $request->all());
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'cloud_init_profile.create', 'success', 'cloud_init_profile', $id);
        return Response::json(['data' => ['id' => $id]], 201);
    }

    public function updateProfile(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $id = (int) $request->param('id');
        (new CloudInitProfileService($this->app->pdo()))->update((int) $user['id'], $this->app->auth()->isAdmin(), $id, $request->all());
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'cloud_init_profile.update', 'success', 'cloud_init_profile', $id);
        return Response::json(['data' => ['id' => $id, 'updated' => true]]);
    }

    public function deleteProfile(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $id = (int) $request->param('id');
        (new CloudInitProfileService($this->app->pdo()))->delete((int) $user['id'], $this->app->auth()->isAdmin(), $id);
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'cloud_init_profile.delete', 'success', 'cloud_init_profile', $id);
        return Response::json(['data' => ['deleted' => true]]);
    }

    public function vendorData(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $service = new CloudInitProfileService($this->app->pdo());
        $profileId = (int) $request->param('id');
        $profile = $this->app->auth()->isAdmin()
            ? $service->owned((int) $user['id'], true, $profileId)
            : $service->resolveForOwner($profileId, (int) $user['id']);
        $yaml = $service->vendorData($profile);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $profile['name']) ?: 'cloud-init-profile';
        return new Response($yaml, 200, [
            'Content-Type' => 'text/yaml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.yaml"',
            'X-Content-SHA256' => hash('sha256', $yaml),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
