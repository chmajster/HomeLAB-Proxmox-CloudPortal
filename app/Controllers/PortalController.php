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
            'firstRun' => $this->firstRunChecklist(),
            'managedProvisioning' => $this->managedProvisioningConfigured(),
            'hostnamePattern' => (string) $this->app->config->get('hostname_generator.pattern', 'vm-{project}-{counter}'),
        ]));
    }

    public function home(Request $request): Response
    {
        if ($this->app->auth()->user() === null) return Response::redirect($this->app->url('/login'));
        return Response::redirect($this->app->url('/dashboard'));
    }

    /** @return array<string,bool>|null */
    private function firstRunChecklist(): ?array
    {
        if (!$this->app->auth()->isAdmin()) return null;
        $pdo = $this->app->pdo();
        $counts = [];
        foreach (['proxmox_connections', 'networks', 'vm_templates', 'resource_plans'] as $table) {
            $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        }
        if (min($counts) > 0) return null;
        return [
            'portal' => true, 'administrator' => true, 'database' => true, 'security' => true,
            'proxmox' => $counts['proxmox_connections'] > 0, 'networks' => $counts['networks'] > 0,
            'templates' => $counts['vm_templates'] > 0, 'plans' => $counts['resource_plans'] > 0,
        ];
    }

    private function managedProvisioningConfigured(): bool
    {
        return trim((string) $this->app->config->get('dns.server_ip', '')) !== ''
            && trim((string) $this->app->config->get('dns.api_token_encrypted', '')) !== ''
            && trim((string) $this->app->config->get('hostname_generator.pattern', '')) !== '';
    }
}
