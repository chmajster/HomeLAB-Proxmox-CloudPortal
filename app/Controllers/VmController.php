<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Database\Database;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Validator;
use CloudPortal\Services\Provisioning\ProvisioningRequestService;
use CloudPortal\Services\Provisioning\VmOperationService;
use CloudPortal\Services\Proxmox\InfrastructureService;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;

final class VmController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.view');
        $sql = "SELECT vm.id, vm.connection_id, vm.name, vm.vmid, vm.node_name, vm.status, vm.vcpu, vm.ram_mb,
                       vm.disk_gb, vm.created_at, p.name AS project_name, u.username AS owner_name, ip.address AS ip_address
                FROM virtual_machines vm JOIN projects p ON p.id = vm.project_id
                JOIN users u ON u.id = vm.owner_user_id LEFT JOIN ip_addresses ip ON ip.virtual_machine_id = vm.id
                WHERE vm.status <> 'deleted'";
        $params = [];
        if (!$this->app->auth()->isAdmin()) {
            $sql .= ' AND vm.owner_user_id = :user AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id = vm.project_id AND pu.user_id = :member)';
            $params = ['user' => $user['id'], 'member' => $user['id']];
        }
        $sql .= ' ORDER BY vm.created_at DESC';
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        $vms = $statement->fetchAll();
        $vms = (new InfrastructureService($this->app->pdo(), new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto())))->mergeLiveVmState($vms);
        return Response::json(['data' => $vms]);
    }

    public function show(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $vm = $this->operations()->accessibleVm((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin());
        $snapshots = $this->app->pdo()->prepare('SELECT id, name, description, status, created_at FROM snapshots WHERE virtual_machine_id = :vm ORDER BY created_at DESC');
        $snapshots->execute(['vm' => $vm['id']]);
        $jobs = $this->app->pdo()->prepare('SELECT public_id, type, status, error_message, created_at, finished_at FROM jobs WHERE virtual_machine_id = :vm ORDER BY created_at DESC LIMIT 20');
        $jobs->execute(['vm' => $vm['id']]);
        return Response::json(['data' => ['vm' => $vm, 'snapshots' => $snapshots->fetchAll(), 'jobs' => $jobs->fetchAll()]]);
    }

    public function create(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.create');
        $job = (new ProvisioningRequestService(new Database($this->app->config)))->createVm((int) $user['id'], $this->app->auth()->isAdmin(), $request->all());
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.create.requested', 'success', 'job', $job);
        return Response::json(['data' => ['job_id' => $job]], 202);
    }

    public function power(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $job = $this->operations()->power((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(), $request->param('action'));
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.' . $request->param('action') . '.requested', 'success', 'virtual_machine', $request->param('id'), ['job_id' => $job]);
        return Response::json(['data' => ['job_id' => $job]], 202);
    }

    public function delete(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.delete');
        $job = $this->operations()->delete((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin());
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.delete.requested', 'success', 'virtual_machine', $request->param('id'), ['job_id' => $job]);
        return Response::json(['data' => ['job_id' => $job]], 202);
    }

    public function snapshot(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.modify');
        $data = Validator::validate($request->all(), ['name' => 'required|string|max:40', 'description' => 'string|max:255']);
        $job = $this->operations()->snapshot(
            (int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(),
            (string) $data['name'], (string) ($data['description'] ?? '')
        );
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.snapshot.create.requested', 'success', 'virtual_machine', $request->param('id'), ['job_id' => $job, 'snapshot_name' => $data['name']]);
        return Response::json(['data' => ['job_id' => $job]], 202);
    }

    public function deleteSnapshot(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.modify');
        $job = $this->operations()->deleteSnapshot(
            (int) $request->param('id'), (int) $request->param('snapshotId'), (int) $user['id'], $this->app->auth()->isAdmin()
        );
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.snapshot.delete.requested', 'success', 'virtual_machine', $request->param('id'), ['job_id' => $job, 'snapshot_id' => $request->param('snapshotId')]);
        return Response::json(['data' => ['job_id' => $job]], 202);
    }

    public function resize(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.modify');
        $data = Validator::validate($request->all(), ['plan_id' => 'required|int|min:1']);
        $job = $this->operations()->resize((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(), (int) $data['plan_id']);
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.resize.requested', 'success', 'virtual_machine', $request->param('id'), ['job_id' => $job, 'plan_id' => (int) $data['plan_id']]);
        return Response::json(['data' => ['job_id' => $job]], 202);
    }

    public function assign(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        $data = Validator::validate($request->all(), [
            'project_id' => 'required|int|min:1',
            'owner_user_id' => 'required|int|min:1',
        ]);
        $vmId = (int) $request->param('id');
        $this->operations()->assign($vmId, (int) $data['project_id'], (int) $data['owner_user_id']);
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'vm.assignment.update', 'success', 'virtual_machine', $vmId, [
            'project_id' => (int) $data['project_id'], 'owner_user_id' => (int) $data['owner_user_id'],
        ]);
        return Response::json(['data' => ['updated' => true]]);
    }

    public function console(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $vm = $this->operations()->accessibleVm((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin());
        $client = (new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()))->forConnection((int) $vm['connection_id']);
        $connection = $this->app->pdo()->prepare('SELECT hostname FROM proxmox_connections WHERE id = :id');
        $connection->execute(['id' => $vm['connection_id']]);
        $proxyHost = $connection->fetchColumn();
        $config = $client->post('/nodes/' . rawurlencode((string) $vm['node_name']) . '/qemu/' . (int) $vm['vmid'] . '/spiceproxy', ['proxy' => $proxyHost]);
        if (!is_array($config)) {
            throw new \RuntimeException('Proxmox did not return a SPICE console configuration.');
        }
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.console', 'success', 'virtual_machine', $vm['id']);
        return new Response($this->spiceConfig($config), 200, [
            'Content-Type' => 'application/x-virt-viewer',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $vm['name']) . '.vv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function operations(): VmOperationService
    {
        return new VmOperationService(new Database($this->app->config));
    }

    /** @param array<string,mixed> $data */
    private function spiceConfig(array $data): string
    {
        $allowed = ['type', 'host', 'proxy', 'password', 'tls-port', 'ca', 'host-subject', 'title', 'release-cursor', 'toggle-fullscreen', 'secure-attention', 'delete-this-file'];
        $lines = ['[virt-viewer]'];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data) || (!is_scalar($data[$key]) && $data[$key] !== null)) {
                continue;
            }
            $value = str_replace(["\r", "\n"], ['', '\\n'], (string) $data[$key]);
            $lines[] = $key . '=' . $value;
        }
        if (!isset($data['delete-this-file'])) {
            $lines[] = 'delete-this-file=1';
        }
        return implode("\n", $lines) . "\n";
    }
}
