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
use CloudPortal\Services\Proxmox\ProxmoxVmManager;

final class VmController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.view');
        $sql = "SELECT vm.id, vm.connection_id, vm.project_id, vm.name, vm.vmid, vm.node_name, vm.status, vm.vcpu, vm.ram_mb,
                       vm.disk_gb, vm.created_at, p.name AS project_name, u.username AS owner_name, ip.address AS ip_address,
                       vp.status AS provisioning_status, vp.current_step AS provisioning_step, vp.current_step_name AS provisioning_step_name,
                       vp.fqdn AS fqdn
                FROM virtual_machines vm JOIN projects p ON p.id = vm.project_id
                JOIN users u ON u.id = vm.owner_user_id LEFT JOIN ip_addresses ip ON ip.virtual_machine_id = vm.id
                LEFT JOIN vm_provisioning vp ON vp.virtual_machine_id = vm.id
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
        $provisioning = $this->app->pdo()->prepare('SELECT status,current_step,current_step_name,hostname,fqdn,ip_address,last_error,ready_at,created_at,updated_at FROM vm_provisioning WHERE virtual_machine_id=:vm ORDER BY id DESC LIMIT 1');
        $provisioning->execute(['vm' => $vm['id']]);
        $provisioningData = $provisioning->fetch();
        return Response::json(['data' => [
            'vm' => $vm,
            'provisioning' => is_array($provisioningData) ? $provisioningData : null,
            'snapshots' => $snapshots->fetchAll(),
            'jobs' => $jobs->fetchAll(),
        ]]);
    }

    public function create(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.create');
        $input = $this->withHostnamePrefix($request->all());
        $job = (new ProvisioningRequestService(new Database($this->app->config), $this->app->config))->createVm((int) $user['id'], $this->app->auth()->isAdmin(), $input);
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
        $connection = $this->app->pdo()->prepare('SELECT hostname, port FROM proxmox_connections WHERE id = :id');
        $connection->execute(['id' => $vm['connection_id']]);
        $connectionData = $connection->fetch();
        if (!is_array($connectionData)) {
            throw new \RuntimeException('Nie znaleziono połączenia Proxmox dla tej VM.');
        }

        $content = (new ProxmoxVmManager(new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto())))->console(
            (int) $vm['connection_id'],
            (string) $vm['node_name'],
            (int) $vm['vmid'],
            (string) $connectionData['hostname'],
            (int) $connectionData['port'],
        );

        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.console', 'success', 'virtual_machine', $vm['id']);
        return new Response($content, 200, [
            'Content-Type' => 'application/x-virt-viewer',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $vm['name']) . '.vv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function withHostnamePrefix(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $prefix = $this->hostnamePrefix();
        if ($name !== '' && $prefix !== '' && !str_starts_with(strtolower($name), $prefix)) {
            $input['name'] = $prefix . $name;
        }
        return $input;
    }

    private function hostnamePrefix(): string
    {
        $statement = $this->app->pdo()->prepare("SELECT value FROM settings WHERE setting_key='hostname_generator.prefix' LIMIT 1");
        $statement->execute();
        $raw = $statement->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') return '';
        try {
            $value = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
        if (!is_string($value)) return '';
        $prefix = strtolower(trim($value));
        $prefix = preg_replace('/[^a-z0-9-]+/', '-', $prefix) ?? '';
        return substr(ltrim($prefix, '-'), 0, 32);
    }

    private function operations(): VmOperationService
    {
        return new VmOperationService(new Database($this->app->config));
    }
}
