<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Provisioning\AnsibleInventoryService;
use CloudPortal\Services\Provisioning\AnsiblePlaybookService;
use CloudPortal\Services\Provisioning\JobRepository;

final class AnsibleController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function playbooks(Request $request): Response
    {
        $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.view');
        return Response::json(['data' => $this->playbookService()->playbooks()]);
    }

    public function inventories(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.view');
        $projectId = (int) $request->query('project_id', 0);
        return Response::json(['data' => $this->inventoryService()->inventories(
            (int) $user['id'],
            $this->app->auth()->isAdmin(),
            $projectId > 0 ? $projectId : null,
        )]);
    }

    public function createInventory(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $input = $request->all();
        $projectId = (int) ($input['project_id'] ?? 0);
        if ($projectId <= 0) throw new HttpException(422, 'project_id is required.');
        $inventory = $this->inventoryService()->create(
            $projectId,
            (int) $user['id'],
            $this->app->auth()->isAdmin(),
            (string) ($input['name'] ?? ''),
            (string) ($input['description'] ?? ''),
            $this->variables($input['variables'] ?? []),
        );
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'ansible.inventory.create', 'success', 'ansible_inventory', (string) $inventory['id'], ['project_id' => $projectId]);
        return Response::json(['data' => $inventory], 201);
    }

    public function showInventory(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.view');
        return Response::json(['data' => $this->inventoryService()->inventory(
            (int) $request->param('id'),
            (int) $user['id'],
            $this->app->auth()->isAdmin(),
        )]);
    }

    public function deleteInventory(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $id = (int) $request->param('id');
        $this->inventoryService()->delete($id, (int) $user['id'], $this->app->auth()->isAdmin());
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'ansible.inventory.delete', 'success', 'ansible_inventory', (string) $id);
        return Response::json(['data' => ['deleted' => true]]);
    }

    public function addHost(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $input = $request->all();
        $vmId = (int) ($input['virtual_machine_id'] ?? 0);
        if ($vmId <= 0) throw new HttpException(422, 'virtual_machine_id is required.');
        $inventoryId = (int) $request->param('id');
        $host = $this->inventoryService()->addHost(
            $inventoryId,
            $vmId,
            (int) $user['id'],
            $this->app->auth()->isAdmin(),
            (string) ($input['ansible_user'] ?? 'clouduser'),
            isset($input['host_alias']) ? (string) $input['host_alias'] : null,
            $this->variables($input['variables'] ?? []),
        );
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'ansible.inventory.host.add', 'success', 'ansible_inventory', (string) $inventoryId, ['virtual_machine_id' => $vmId]);
        return Response::json(['data' => $host], 201);
    }

    public function removeHost(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $inventoryId = (int) $request->param('id');
        $hostId = (int) $request->param('hostId');
        $this->inventoryService()->removeHost($inventoryId, $hostId, (int) $user['id'], $this->app->auth()->isAdmin());
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'ansible.inventory.host.remove', 'success', 'ansible_inventory', (string) $inventoryId, ['host_id' => $hostId]);
        return Response::json(['data' => ['deleted' => true]]);
    }

    public function launchInventory(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $input = $request->all();
        $inventoryId = (int) $request->param('id');
        $inventory = $this->inventoryService()->executionInventory($inventoryId, (int) $user['id'], $this->app->auth()->isAdmin());
        $playbook = $this->requiredPlaybook((string) ($input['playbook'] ?? ''));
        $limitVmId = (int) ($input['limit_vm_id'] ?? 0);
        if ($limitVmId > 0) {
            $found = false;
            foreach ($inventory['hosts'] as $host) if ((int) $host['virtual_machine_id'] === $limitVmId) $found = true;
            if (!$found) throw new HttpException(422, 'limit_vm_id is not an enabled host in this inventory.');
        }
        $job = (new JobRepository($this->app->pdo()))->enqueue(
            'ansible.inventory',
            (int) $user['id'],
            (int) $inventory['project_id'],
            null,
            [
                'inventory_id' => $inventoryId,
                'playbook' => $playbook,
                'limit_vm_id' => $limitVmId > 0 ? $limitVmId : null,
                'extra_vars' => $this->variables($input['extra_vars'] ?? []),
            ],
            null,
            null,
            3,
        );
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'ansible.inventory.launch', 'success', 'ansible_inventory', (string) $inventoryId, ['job_id' => $job, 'playbook' => $playbook]);
        return Response::json(['data' => ['job_id' => $job]], 202);
    }

    public function launchVm(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $input = $request->all();
        $vm = $this->accessibleVm((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin());
        if (trim((string) ($vm['ip_address'] ?? '')) === '') throw new HttpException(422, 'The VM does not have an allocated IP address.');
        $playbook = $this->requiredPlaybook((string) ($input['playbook'] ?? ''));
        $ansibleUser = trim((string) ($input['ansible_user'] ?? 'clouduser'));
        if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $ansibleUser) !== 1) throw new HttpException(422, 'Invalid Ansible username.');
        $job = (new JobRepository($this->app->pdo()))->enqueue(
            'vm.ansible',
            (int) $user['id'],
            (int) $vm['project_id'],
            (int) $vm['connection_id'],
            [
                'playbook' => $playbook,
                'cloud_init_user' => $ansibleUser,
                'extra_vars' => $this->variables($input['extra_vars'] ?? []),
            ],
            null,
            (int) $vm['id'],
            3,
        );
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.ansible.launch', 'success', 'virtual_machine', (string) $vm['id'], ['job_id' => $job, 'playbook' => $playbook]);
        return Response::json(['data' => ['job_id' => $job]], 202);
    }

    /** @return array<string,mixed> */
    private function accessibleVm(int $id, int $userId, bool $isAdmin): array
    {
        $sql = "SELECT vm.id,vm.project_id,vm.connection_id,vm.owner_user_id,vm.name,ip.address AS ip_address
                FROM virtual_machines vm LEFT JOIN ip_addresses ip ON ip.virtual_machine_id=vm.id
                WHERE vm.id=:id AND vm.status<>'deleted'";
        $params = ['id' => $id];
        if (!$isAdmin) {
            $sql .= ' AND vm.owner_user_id=:owner AND EXISTS (SELECT 1 FROM project_users pu WHERE pu.project_id=vm.project_id AND pu.user_id=:member)';
            $params['owner'] = $userId;
            $params['member'] = $userId;
        }
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        $vm = $statement->fetch();
        if (!is_array($vm)) throw new HttpException(404, 'Virtual machine not found.');
        return $vm;
    }

    private function requiredPlaybook(string $playbook): string
    {
        try {
            $validated = $this->playbookService()->validateSelection($playbook);
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        if ($validated === null) throw new HttpException(422, 'playbook is required.');
        return $validated;
    }

    /** @return array<string,mixed> */
    private function variables(mixed $value): array
    {
        if ($value === null || $value === '') return [];
        if (!is_array($value) || array_is_list($value)) throw new HttpException(422, 'Ansible variables must be a JSON object.');
        foreach ($value as $key => $_) {
            if (!is_string($key) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) throw new HttpException(422, 'Invalid Ansible variable name: ' . (string) $key);
        }
        return $value;
    }

    private function inventoryService(): AnsibleInventoryService
    {
        return new AnsibleInventoryService($this->app->pdo());
    }

    private function playbookService(): AnsiblePlaybookService
    {
        return AnsiblePlaybookService::fromConfig($this->app->config, $this->app->root);
    }
}
