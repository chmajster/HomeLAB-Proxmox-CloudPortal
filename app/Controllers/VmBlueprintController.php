<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Provisioning\AnsiblePlaybookService;
use CloudPortal\Services\Provisioning\ProvisioningRequestService;
use CloudPortal\Services\Provisioning\VmBlueprintService;
use CloudPortal\Database\Database;

final class VmBlueprintController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.view');
        return Response::json(['data' => $this->service()->accessible((int) $user['id'], $this->app->auth()->isAdmin())]);
    }

    public function deploy(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.create');
        $id = (int) $request->param('id');
        if ($id <= 0) throw new HttpException(422, 'Invalid blueprint ID.');
        $blueprint = $this->service()->resolveForDeploy($id, (int) $user['id'], $this->app->auth()->isAdmin());
        $input = [
            'blueprint_id' => $id,
            'project_id' => (int) $blueprint['project_id'],
            'template_id' => (int) $blueprint['template_id'],
            'plan_id' => (int) $blueprint['plan_id'],
            'network_id' => (int) $blueprint['network_id'],
            'storage_id' => (int) $blueprint['storage_id'],
            'cloud_init_profile_id' => $blueprint['cloud_init_profile_id'],
            'managed_provisioning' => true,
            'initial_hardening_command' => $blueprint['initial_hardening_command'] === null ? '' : (string) $blueprint['initial_hardening_command'],
            'run_puppet' => (bool) $blueprint['run_puppet'],
            'reboot_before_ansible' => (bool) $blueprint['reboot_before_ansible'],
            'ansible_playbook' => $blueprint['ansible_playbook'],
            'ansible_extra_vars' => $blueprint['ansible_extra_vars'],
            'start_after_create' => true,
        ];
        $jobId = (new ProvisioningRequestService(new Database($this->app->config), $this->app->config))->createVm(
            (int) $user['id'],
            $this->app->auth()->isAdmin(),
            $input,
        );
        $this->app->audit()->log((int) $user['id'], $request->ip(), 'vm.blueprint.deploy', 'success', 'vm_blueprint', (string) $id, ['job_id' => $jobId]);
        return Response::json(['data' => ['job_id' => $jobId, 'blueprint_id' => $id]], 202);
    }

    public function adminIndex(Request $request): Response
    {
        $this->admin();
        $statement = $this->app->pdo()->query(
            "SELECT b.*,p.name AS project_name,t.name AS template_name,r.name AS plan_name,n.name AS network_name,s.storage_name,c.name AS cloud_init_profile_name
             FROM vm_blueprints b
             JOIN projects p ON p.id=b.project_id
             JOIN vm_templates t ON t.id=b.template_id
             JOIN resource_plans r ON r.id=b.plan_id
             JOIN networks n ON n.id=b.network_id
             JOIN storages s ON s.id=b.storage_id
             LEFT JOIN cloud_init_profiles c ON c.id=b.cloud_init_profile_id
             ORDER BY p.name,b.name"
        );
        return Response::json(['data' => $statement->fetchAll()]);
    }

    public function create(Request $request): Response
    {
        $this->mutating($request);
        $input = $request->all();
        $this->validatePlaybook($input);
        $id = $this->service()->create($input, $this->app->auth()->id());
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.blueprints.create', 'success', 'vm_blueprint', (string) $id);
        return Response::json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request): Response
    {
        $this->mutating($request);
        $id = (int) $request->param('id');
        if ($id <= 0) throw new HttpException(422, 'Invalid blueprint ID.');
        $input = $request->all();
        $this->validatePlaybook($input);
        $this->service()->update($id, $input);
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.blueprints.update', 'success', 'vm_blueprint', (string) $id);
        return Response::json(['data' => ['id' => $id, 'updated' => true]]);
    }

    /** @param array<string,mixed> $input */
    private function validatePlaybook(array &$input): void
    {
        $playbook = trim((string) ($input['ansible_playbook'] ?? ''));
        if ($playbook === '') return;
        try {
            $validated = AnsiblePlaybookService::fromConfig($this->app->config, $this->app->root)->validateSelection($playbook);
        } catch (\InvalidArgumentException | \RuntimeException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        $input['ansible_playbook'] = $validated;
    }

    private function admin(): void
    {
        $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('admin.access');
    }

    private function mutating(Request $request): void
    {
        $this->app->csrf->verify($request);
        $this->admin();
    }

    private function service(): VmBlueprintService
    {
        return new VmBlueprintService($this->app->pdo());
    }
}
