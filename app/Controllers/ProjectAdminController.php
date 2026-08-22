<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class ProjectAdminController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $this->admin();
        $id = (int) $request->param('id');
        $pdo = $this->app->pdo();
        $project = $pdo->prepare('SELECT * FROM projects WHERE id=:id');
        $project->execute(['id' => $id]);
        $row = $project->fetch();
        if (!is_array($row)) {
            throw new HttpException(404, 'Project not found.');
        }
        $members = $pdo->prepare('SELECT u.id,u.username,u.email,u.status,pu.membership_role FROM project_users pu JOIN users u ON u.id=pu.user_id WHERE pu.project_id=:id ORDER BY u.username');
        $members->execute(['id' => $id]);
        $networks = $pdo->prepare('SELECT n.id,n.name,n.bridge,n.vlan_id,n.subnet FROM project_networks pn JOIN networks n ON n.id=pn.network_id WHERE pn.project_id=:id ORDER BY n.name');
        $networks->execute(['id' => $id]);
        $storages = $pdo->prepare('SELECT s.id,s.storage_name,s.node_name FROM project_storages ps JOIN storages s ON s.id=ps.storage_id WHERE ps.project_id=:id ORDER BY s.storage_name');
        $storages->execute(['id' => $id]);
        return Response::json(['data' => ['project' => $row, 'members' => $members->fetchAll(), 'networks' => $networks->fetchAll(), 'storages' => $storages->fetchAll()]]);
    }

    public function membership(Request $request): Response
    {
        $this->mutating($request);
        $projectId = (int) $request->param('id');
        $userId = filter_var($request->input('user_id'), FILTER_VALIDATE_INT);
        $role = (string) $request->input('membership_role', 'member');
        if ($userId === false || !in_array($role, ['owner', 'member'], true)) {
            throw new HttpException(422, 'Invalid project membership.');
        }
        $statement = $this->app->pdo()->prepare(
            'INSERT INTO project_users (project_id, user_id, membership_role) VALUES (:project, :user, :role)
             ON DUPLICATE KEY UPDATE membership_role = VALUES(membership_role)'
        );
        $statement->execute(['project' => $projectId, 'user' => $userId, 'role' => $role]);
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'project.membership.update', 'success', 'project', $projectId, ['user_id' => $userId, 'role' => $role]);
        return Response::json(['data' => ['updated' => true]]);
    }

    public function access(Request $request): Response
    {
        $this->mutating($request);
        $projectId = (int) $request->param('id');
        $networkId = $request->input('network_id');
        $storageId = $request->input('storage_id');
        if (($networkId === null || $networkId === '') && ($storageId === null || $storageId === '')) {
            throw new HttpException(422, 'A network_id or storage_id is required.');
        }
        $pdo = $this->app->pdo();
        if ($networkId !== null && $networkId !== '') {
            $id = $this->positiveInt($networkId, 1, PHP_INT_MAX);
            $pdo->prepare('INSERT IGNORE INTO project_networks (project_id, network_id) VALUES (:project, :resource)')->execute(['project' => $projectId, 'resource' => $id]);
        }
        if ($storageId !== null && $storageId !== '') {
            $id = $this->positiveInt($storageId, 1, PHP_INT_MAX);
            $pdo->prepare('INSERT IGNORE INTO project_storages (project_id, storage_id) VALUES (:project, :resource)')->execute(['project' => $projectId, 'resource' => $id]);
        }
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'project.access.update', 'success', 'project', $projectId, ['network_id' => $networkId, 'storage_id' => $storageId]);
        return Response::json(['data' => ['updated' => true]]);
    }

    public function removeMembership(Request $request): Response
    {
        $this->mutating($request);
        $projectId = (int) $request->param('id');
        $userId = (int) $request->param('userId');
        $owned = $this->app->pdo()->prepare("SELECT COUNT(*) FROM virtual_machines WHERE project_id=:project AND owner_user_id=:user AND status<>'deleted'");
        $owned->execute(['project' => $projectId, 'user' => $userId]);
        if ((int) $owned->fetchColumn() > 0) {
            throw new HttpException(409, 'Reassign or delete the user\'s project VMs before removing membership.');
        }
        $this->app->pdo()->prepare('DELETE FROM project_users WHERE project_id=:project AND user_id=:user')->execute(['project' => $projectId, 'user' => $userId]);
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'project.membership.delete', 'success', 'project', $projectId, ['user_id' => $userId]);
        return Response::json(['data' => ['deleted' => true]]);
    }

    public function removeAccess(Request $request): Response
    {
        $this->mutating($request);
        $projectId = (int) $request->param('id');
        $type = $request->param('type');
        $resourceId = (int) $request->param('resourceId');
        [$table, $column, $vmColumn] = match ($type) {
            'network' => ['project_networks', 'network_id', 'network_id'],
            'storage' => ['project_storages', 'storage_id', 'storage_id'],
            default => throw new HttpException(422, 'Invalid project access type.'),
        };
        $used = $this->app->pdo()->prepare("SELECT COUNT(*) FROM virtual_machines WHERE project_id=:project AND {$vmColumn}=:resource AND status<>'deleted'");
        $used->execute(['project' => $projectId, 'resource' => $resourceId]);
        if ((int) $used->fetchColumn() > 0) {
            throw new HttpException(409, 'Resource access is still used by an active VM.');
        }
        $this->app->pdo()->prepare("DELETE FROM {$table} WHERE project_id=:project AND {$column}=:resource")->execute(['project' => $projectId, 'resource' => $resourceId]);
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'project.access.delete', 'success', 'project', $projectId, ['type' => $type, 'resource_id' => $resourceId]);
        return Response::json(['data' => ['deleted' => true]]);
    }

    private function mutating(Request $request): void
    {
        $this->admin();
        $this->app->csrf->verify($request);
    }

    private function positiveInt(mixed $value, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) {
            throw new HttpException(422, 'Numeric value is outside the allowed range.');
        }
        return (int) $int;
    }

    private function admin(): void
    {
        $this->app->auth()->requirePermission('admin.access');
    }
}
