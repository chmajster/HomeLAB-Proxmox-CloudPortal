<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Provisioning\VmOperationService;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;
use CloudPortal\Services\Proxmox\ProxmoxFirewallManager;

final class FirewallController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function vmState(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.view');
        $vm = $this->portalVm((int) $request->param('id'), (int) $user['id']);
        return Response::json(['data' => $this->manager()->vmState((int) $vm['connection_id'], (string) $vm['node_name'], (int) $vm['vmid'])]);
    }

    public function vmOptions(Request $request): Response
    {
        $user = $this->portalMutation($request);
        $vm = $this->portalVm((int) $request->param('id'), (int) $user['id']);
        $data = $this->manager()->updateVmOptions((int) $vm['connection_id'], (string) $vm['node_name'], (int) $vm['vmid'], $request->all());
        $this->audit($request, 'vm.firewall.options.update', 'virtual_machine', (int) $vm['id']);
        return Response::json(['data' => $data]);
    }

    public function vmRuleCreate(Request $request): Response
    {
        $user = $this->portalMutation($request);
        $vm = $this->portalVm((int) $request->param('id'), (int) $user['id']);
        $data = $this->manager()->createVmRule((int) $vm['connection_id'], (string) $vm['node_name'], (int) $vm['vmid'], $request->all());
        $this->audit($request, 'vm.firewall.rule.create', 'virtual_machine', (int) $vm['id']);
        return Response::json(['data' => $data], 201);
    }

    public function vmRuleUpdate(Request $request): Response
    {
        $user = $this->portalMutation($request);
        $vm = $this->portalVm((int) $request->param('id'), (int) $user['id']);
        $position = $this->position($request->param('position'));
        $data = $this->manager()->updateVmRule((int) $vm['connection_id'], (string) $vm['node_name'], (int) $vm['vmid'], $position, $request->all());
        $this->audit($request, 'vm.firewall.rule.update', 'virtual_machine', (int) $vm['id'], ['position' => $position]);
        return Response::json(['data' => $data]);
    }

    public function vmRuleDelete(Request $request): Response
    {
        $user = $this->portalMutation($request);
        $vm = $this->portalVm((int) $request->param('id'), (int) $user['id']);
        $position = $this->position($request->param('position'));
        $data = $this->manager()->deleteVmRule((int) $vm['connection_id'], (string) $vm['node_name'], (int) $vm['vmid'], $position);
        $this->audit($request, 'vm.firewall.rule.delete', 'virtual_machine', (int) $vm['id'], ['position' => $position]);
        return Response::json(['data' => $data]);
    }

    public function liveState(Request $request): Response
    {
        $this->admin();
        [$connectionId, $node, $vmid] = $this->liveTarget($request);
        return Response::json(['data' => $this->manager()->vmState($connectionId, $node, $vmid)]);
    }

    public function liveOptions(Request $request): Response
    {
        $this->adminMutation($request);
        [$connectionId, $node, $vmid] = $this->liveTarget($request);
        $data = $this->manager()->updateVmOptions($connectionId, $node, $vmid, $request->all());
        $this->auditLive($request, 'admin.proxmox_vm.firewall.options.update', $connectionId, $node, $vmid);
        return Response::json(['data' => $data]);
    }

    public function liveRuleCreate(Request $request): Response
    {
        $this->adminMutation($request);
        [$connectionId, $node, $vmid] = $this->liveTarget($request);
        $data = $this->manager()->createVmRule($connectionId, $node, $vmid, $request->all());
        $this->auditLive($request, 'admin.proxmox_vm.firewall.rule.create', $connectionId, $node, $vmid);
        return Response::json(['data' => $data], 201);
    }

    public function liveRuleUpdate(Request $request): Response
    {
        $this->adminMutation($request);
        [$connectionId, $node, $vmid] = $this->liveTarget($request);
        $position = $this->position($request->param('position'));
        $data = $this->manager()->updateVmRule($connectionId, $node, $vmid, $position, $request->all());
        $this->auditLive($request, 'admin.proxmox_vm.firewall.rule.update', $connectionId, $node, $vmid, ['position' => $position]);
        return Response::json(['data' => $data]);
    }

    public function liveRuleDelete(Request $request): Response
    {
        $this->adminMutation($request);
        [$connectionId, $node, $vmid] = $this->liveTarget($request);
        $position = $this->position($request->param('position'));
        $data = $this->manager()->deleteVmRule($connectionId, $node, $vmid, $position);
        $this->auditLive($request, 'admin.proxmox_vm.firewall.rule.delete', $connectionId, $node, $vmid, ['position' => $position]);
        return Response::json(['data' => $data]);
    }

    public function connections(Request $request): Response
    {
        $this->admin();
        $rows = $this->app->pdo()->query("SELECT id,name,hostname,port,status,last_checked_at FROM proxmox_connections WHERE status <> 'disabled' ORDER BY name")->fetchAll();
        return Response::json(['data' => $rows]);
    }

    public function clusterState(Request $request): Response
    {
        $this->admin();
        return Response::json(['data' => $this->manager()->clusterState($this->connectionId($request))]);
    }

    public function aliasCreate(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $this->manager()->createAlias($connectionId, $request->all());
        $this->auditCluster($request, 'admin.firewall.alias.create', $connectionId, ['name' => $request->input('name')]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)], 201);
    }

    public function aliasUpdate(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $this->manager()->updateAlias($connectionId, $name, $request->all());
        $this->auditCluster($request, 'admin.firewall.alias.update', $connectionId, ['name' => $name]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)]);
    }

    public function aliasDelete(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $this->manager()->deleteAlias($connectionId, $name);
        $this->auditCluster($request, 'admin.firewall.alias.delete', $connectionId, ['name' => $name]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)]);
    }

    public function ipSetCreate(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $this->manager()->createIpSet($connectionId, $request->all());
        $this->auditCluster($request, 'admin.firewall.ipset.create', $connectionId, ['name' => $request->input('name')]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)], 201);
    }

    public function ipSetDelete(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $this->manager()->deleteIpSet($connectionId, $name);
        $this->auditCluster($request, 'admin.firewall.ipset.delete', $connectionId, ['name' => $name]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)]);
    }

    public function ipSetEntryCreate(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $this->manager()->createIpSetEntry($connectionId, $name, $request->all());
        $this->auditCluster($request, 'admin.firewall.ipset.entry.create', $connectionId, ['name' => $name, 'cidr' => $request->input('cidr')]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)], 201);
    }

    public function ipSetEntryUpdate(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $this->manager()->updateIpSetEntry($connectionId, $name, $request->all());
        $this->auditCluster($request, 'admin.firewall.ipset.entry.update', $connectionId, ['name' => $name, 'cidr' => $request->input('cidr')]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)]);
    }

    public function ipSetEntryDelete(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $this->manager()->deleteIpSetEntry($connectionId, $name, $request->all());
        $this->auditCluster($request, 'admin.firewall.ipset.entry.delete', $connectionId, ['name' => $name, 'cidr' => $request->input('cidr')]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)]);
    }

    public function groupCreate(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $this->manager()->createGroup($connectionId, $request->all());
        $this->auditCluster($request, 'admin.firewall.group.create', $connectionId, ['group' => $request->input('group', $request->input('name'))]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)], 201);
    }

    public function groupDelete(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $this->manager()->deleteGroup($connectionId, $name);
        $this->auditCluster($request, 'admin.firewall.group.delete', $connectionId, ['group' => $name]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)]);
    }

    public function groupRuleCreate(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $this->manager()->createGroupRule($connectionId, $name, $request->all());
        $this->auditCluster($request, 'admin.firewall.group.rule.create', $connectionId, ['group' => $name]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)], 201);
    }

    public function groupRuleUpdate(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $position = $this->position($request->param('position'));
        $this->manager()->updateGroupRule($connectionId, $name, $position, $request->all());
        $this->auditCluster($request, 'admin.firewall.group.rule.update', $connectionId, ['group' => $name, 'position' => $position]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)]);
    }

    public function groupRuleDelete(Request $request): Response
    {
        $this->adminMutation($request);
        $connectionId = $this->connectionId($request);
        $name = $request->param('name');
        $position = $this->position($request->param('position'));
        $this->manager()->deleteGroupRule($connectionId, $name, $position);
        $this->auditCluster($request, 'admin.firewall.group.rule.delete', $connectionId, ['group' => $name, 'position' => $position]);
        return Response::json(['data' => $this->manager()->clusterState($connectionId)]);
    }

    private function manager(): ProxmoxFirewallManager
    {
        return new ProxmoxFirewallManager(new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()));
    }

    /** @return array<string,mixed> */
    private function portalVm(int $vmId, int $userId): array
    {
        return (new VmOperationService(new Database($this->app->config)))->accessibleVm($vmId, $userId, $this->app->auth()->isAdmin());
    }

    /** @return array<string,mixed> */
    private function portalMutation(Request $request): array
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.modify');
        return $user;
    }

    private function admin(): void
    {
        $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('admin.access');
    }

    private function adminMutation(Request $request): void
    {
        $this->app->csrf->verify($request);
        $this->admin();
    }

    /** @return array{int,string,int} */
    private function liveTarget(Request $request): array
    {
        $connectionId = $this->connectionId($request);
        $node = trim($request->param('node'));
        $vmid = filter_var($request->param('vmid'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => 999999999]]);
        if ($vmid === false || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) throw new HttpException(422, 'Invalid Proxmox VM target.');
        return [$connectionId, $node, (int) $vmid];
    }

    private function connectionId(Request $request): int
    {
        $value = filter_var($request->param('connectionId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) throw new HttpException(422, 'Invalid Proxmox connection id.');
        return (int) $value;
    }

    private function position(string $value): int
    {
        $position = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99999]]);
        if ($position === false) throw new HttpException(422, 'Invalid firewall rule position.');
        return (int) $position;
    }

    /** @param array<string,mixed> $metadata */
    private function audit(Request $request, string $action, string $resourceType, string|int $resourceId, array $metadata = []): void
    {
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), $action, 'success', $resourceType, $resourceId, $metadata);
    }

    /** @param array<string,mixed> $metadata */
    private function auditLive(Request $request, string $action, int $connectionId, string $node, int $vmid, array $metadata = []): void
    {
        $this->audit($request, $action, 'proxmox_connection', $connectionId, ['node' => $node, 'vmid' => $vmid, ...$metadata]);
    }

    /** @param array<string,mixed> $metadata */
    private function auditCluster(Request $request, string $action, int $connectionId, array $metadata = []): void
    {
        $this->audit($request, $action, 'proxmox_connection', $connectionId, $metadata);
    }
}
