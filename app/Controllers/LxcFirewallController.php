<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;
use CloudPortal\Services\Proxmox\ProxmoxLxcFirewallManager;

final class LxcFirewallController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function state(Request $request): Response
    {
        $this->admin();
        [$connectionId, $node, $vmid] = $this->target($request);
        return Response::json(['data' => $this->manager()->state($connectionId, $node, $vmid)]);
    }

    public function options(Request $request): Response
    {
        $this->mutation($request);
        [$connectionId, $node, $vmid] = $this->target($request);
        $data = $this->manager()->updateOptions($connectionId, $node, $vmid, $request->all());
        $this->audit($request, 'admin.proxmox_lxc.firewall.options.update', $connectionId, $node, $vmid);
        return Response::json(['data' => $data]);
    }

    public function createRule(Request $request): Response
    {
        $this->mutation($request);
        [$connectionId, $node, $vmid] = $this->target($request);
        $data = $this->manager()->createRule($connectionId, $node, $vmid, $request->all());
        $this->audit($request, 'admin.proxmox_lxc.firewall.rule.create', $connectionId, $node, $vmid);
        return Response::json(['data' => $data], 201);
    }

    public function updateRule(Request $request): Response
    {
        $this->mutation($request);
        [$connectionId, $node, $vmid] = $this->target($request);
        $position = $this->position($request->param('position'));
        $data = $this->manager()->updateRule($connectionId, $node, $vmid, $position, $request->all());
        $this->audit($request, 'admin.proxmox_lxc.firewall.rule.update', $connectionId, $node, $vmid, ['position' => $position]);
        return Response::json(['data' => $data]);
    }

    public function deleteRule(Request $request): Response
    {
        $this->mutation($request);
        [$connectionId, $node, $vmid] = $this->target($request);
        $position = $this->position($request->param('position'));
        $data = $this->manager()->deleteRule($connectionId, $node, $vmid, $position);
        $this->audit($request, 'admin.proxmox_lxc.firewall.rule.delete', $connectionId, $node, $vmid, ['position' => $position]);
        return Response::json(['data' => $data]);
    }

    private function manager(): ProxmoxLxcFirewallManager
    {
        return new ProxmoxLxcFirewallManager(new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()));
    }

    private function admin(): void
    {
        $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('admin.access');
    }

    private function mutation(Request $request): void
    {
        $this->app->csrf->verify($request);
        $this->admin();
    }

    /** @return array{int,string,int} */
    private function target(Request $request): array
    {
        $connectionId = filter_var($request->param('connectionId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $vmid = filter_var($request->param('vmid'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => 999999999]]);
        $node = trim($request->param('node'));
        if ($connectionId === false || $vmid === false || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) {
            throw new HttpException(422, 'Invalid Proxmox LXC target.');
        }
        return [(int) $connectionId, $node, (int) $vmid];
    }

    private function position(string $value): int
    {
        $position = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99999]]);
        if ($position === false) throw new HttpException(422, 'Invalid firewall rule position.');
        return (int) $position;
    }

    /** @param array<string,mixed> $metadata */
    private function audit(Request $request, string $action, int $connectionId, string $node, int $vmid, array $metadata = []): void
    {
        $this->app->audit()->log(
            $this->app->auth()->id(),
            $request->ip(),
            $action,
            'success',
            'proxmox_connection',
            $connectionId,
            ['node' => $node, 'vmid' => $vmid, 'guest_type' => 'lxc', ...$metadata],
        );
    }
}
