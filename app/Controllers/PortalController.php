<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class PortalController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function page(Request $request): Response
    {
        $user = $this->app->auth()->user();
        if ($user === null) return Response::redirect($this->app->url('/login'));
        $page = trim($request->param('page'), '/');
        $allowed = ['dashboard', 'vms', 'create-vm', 'projects', 'networks', 'templates', 'activity', 'users', 'infrastructure', 'proxmox', 'storages', 'plans', 'quotas', 'audit', 'settings'];
        if (!in_array($page, $allowed, true)) {
            return Response::redirect($this->app->url('/dashboard'));
        }
        $adminOnly = ['users', 'infrastructure', 'proxmox', 'storages', 'plans', 'quotas', 'audit', 'settings'];
        if (in_array($page, $adminOnly, true)) {
            $this->app->auth()->requirePermission('admin.access');
        }
        return Response::html($this->app->view->render('pages/portal', [
            'user' => $user,
            'isAdmin' => $this->app->auth()->isAdmin(),
            'page' => $page,
            'csrf' => $this->app->csrf->token(),
            'appName' => $this->app->setting('portal.name', $this->app->config->get('app.name')),
            'firstRun' => $this->provisioningReadinessChecklist(),
            'managedProvisioning' => $this->managedProvisioningConfigured(),
            'hostnamePattern' => (string) $this->app->config->get('hostname_generator.pattern', 'vm-{project}-{counter}'),
        ]));
    }

    public function home(Request $request): Response
    {
        if ($this->app->auth()->user() === null) return Response::redirect($this->app->url('/login'));
        return Response::redirect($this->app->url('/dashboard'));
    }

    /**
     * The installer lock already proves that the mandatory portal setup
     * (database, administrator and security material) finished successfully.
     * This checklist therefore tracks only resources needed to provision VMs.
     * Missing catalog resources must never make the portal look unfinished.
     *
     * @return array<string,bool>|null
     */
    private function provisioningReadinessChecklist(): ?array
    {
        if (!$this->app->auth()->isAdmin()) return null;

        $pdo = $this->app->pdo();
        $tables = [
            'proxmox' => 'proxmox_connections',
            'projects' => 'projects',
            'networks' => 'networks',
            'storages' => 'storages',
            'templates' => 'vm_templates',
            'plans' => 'resource_plans',
        ];
        $ready = [];
        foreach ($tables as $key => $table) {
            $ready[$key] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() > 0;
        }

        if (!in_array(false, $ready, true)) return null;
        return $ready;
    }

    private function managedProvisioningConfigured(): bool
    {
        return trim((string) $this->app->config->get('dns.server_ip', '')) !== ''
            && trim((string) $this->app->config->get('dns.api_token_encrypted', '')) !== ''
            && trim((string) $this->app->config->get('hostname_generator.pattern', '')) !== '';
    }
}
