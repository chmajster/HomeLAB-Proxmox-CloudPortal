<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Database\Database;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Provisioning\AdvancedVmOperationService;
use CloudPortal\Services\Provisioning\VmOperationService;

final class AdvancedVmController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function rollbackSnapshot(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->rollbackSnapshot((int) $request->param('id'), $request->param('snapshotName'), (int) $user['id'], $this->app->auth()->isAdmin());
        return $this->accepted($job);
    }

    public function cloneVm(Request $request): Response
    {
        $this->mutating($request, 'vm.create');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->cloneVm((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(), $request->all());
        return $this->accepted($job);
    }

    public function reconfigure(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->reconfigure((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(), $request->all());
        return $this->accepted($job);
    }

    public function attachDisk(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->attachDisk(
            (int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(),
            trim((string) $request->input('device')), trim((string) $request->input('storage')), (int) $request->input('size_gb')
        );
        return $this->accepted($job);
    }

    public function detachDisk(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->detachDisk((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(), $request->param('device'));
        return $this->accepted($job);
    }

    public function upsertNic(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $vlan = $request->input('vlan_id');
        $job = $this->ops()->upsertNic(
            (int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(), $request->param('device'),
            trim((string) $request->input('bridge')), $vlan === null || $vlan === '' ? null : (int) $vlan
        );
        return $this->accepted($job);
    }

    public function deleteNic(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->deleteNic((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(), $request->param('device'));
        return $this->accepted($job);
    }

    public function migrate(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->migrate((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(), $request->input('target_node') === null ? null : (string) $request->input('target_node'));
        return $this->accepted($job);
    }

    public function backups(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.view');
        (new VmOperationService(new Database($this->app->config)))->accessibleVm((int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin());
        $statement = $this->app->pdo()->prepare('SELECT id,storage_name,volume_id,mode,compression,size_bytes,status,created_at,completed_at,last_error FROM backups WHERE virtual_machine_id=:vm ORDER BY created_at DESC');
        $statement->execute(['vm' => (int) $request->param('id')]);
        return Response::json(['data' => $statement->fetchAll()]);
    }

    public function createBackup(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->createBackup(
            (int) $request->param('id'), (int) $user['id'], $this->app->auth()->isAdmin(),
            trim((string) $request->input('storage')), (string) $request->input('mode', 'snapshot'), (string) $request->input('compression', 'zstd')
        );
        return $this->accepted($job);
    }

    public function restoreBackup(Request $request): Response
    {
        $this->mutating($request, 'vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = $this->ops()->restoreBackup((int) $request->param('backupId'), (int) $user['id'], $this->app->auth()->isAdmin(), $request->all());
        return $this->accepted($job);
    }

    private function mutating(Request $request, string $permission): void
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission($permission);
    }

    private function ops(): AdvancedVmOperationService
    {
        return new AdvancedVmOperationService(new Database($this->app->config));
    }

    private function accepted(string $job): Response
    {
        return Response::json(['data' => ['job_id' => $job]], 202);
    }
}
