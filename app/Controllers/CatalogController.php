<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class CatalogController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $projectId = (int) $request->query('project_id', 0);
        $pdo = $this->app->pdo();
        if ($projectId > 0 && !$this->app->auth()->isAdmin()) {
            $membership = $pdo->prepare('SELECT 1 FROM project_users WHERE project_id = :project AND user_id = :user');
            $membership->execute(['project' => $projectId, 'user' => $user['id']]);
            if (!$membership->fetchColumn()) {
                throw new HttpException(404, 'Project not found.');
            }
        }
        $projectSql = $this->app->auth()->isAdmin()
            ? "SELECT id, name FROM projects WHERE status = 'active' ORDER BY name"
            : "SELECT p.id, p.name FROM projects p JOIN project_users pu ON pu.project_id = p.id WHERE pu.user_id = :user AND p.status = 'active' ORDER BY p.name";
        $projects = $pdo->prepare($projectSql);
        $projects->execute($this->app->auth()->isAdmin() ? [] : ['user' => $user['id']]);
        $data = [
            'projects' => $projects->fetchAll(),
            'plans' => $pdo->query('SELECT id, name, vcpu, ram_mb, disk_gb FROM resource_plans WHERE enabled = 1 ORDER BY sort_order, name')->fetchAll(),
            'templates' => [],
            'networks' => [],
            'storages' => [],
        ];
        if ($projectId > 0) {
            $templates = $pdo->prepare(
                "SELECT DISTINCT t.id, t.connection_id, t.node_name, t.name, t.operating_system, t.description
                 FROM vm_templates t JOIN proxmox_connections c ON c.id = t.connection_id
                 JOIN networks n ON n.connection_id = c.id AND (n.node_name IS NULL OR n.node_name = t.node_name) JOIN project_networks pn ON pn.network_id = n.id
                 JOIN storages s ON s.connection_id = c.id AND (s.node_name IS NULL OR s.node_name = t.node_name) JOIN project_storages ps ON ps.storage_id = s.id
                 WHERE pn.project_id = :project AND ps.project_id = :project2 AND t.enabled = 1 AND c.status = 'active'"
            );
            $templates->execute(['project' => $projectId, 'project2' => $projectId]);
            $networks = $pdo->prepare(
                'SELECT n.id, n.connection_id, n.node_name, n.name, n.bridge, n.vlan_id, n.subnet FROM networks n JOIN project_networks pn ON pn.network_id = n.id WHERE pn.project_id = :project AND n.enabled = 1 ORDER BY n.name'
            );
            $networks->execute(['project' => $projectId]);
            $storages = $pdo->prepare(
                'SELECT s.id, s.connection_id, s.storage_name, s.node_name FROM storages s JOIN project_storages ps ON ps.storage_id = s.id WHERE ps.project_id = :project AND s.enabled = 1 ORDER BY s.storage_name'
            );
            $storages->execute(['project' => $projectId]);
            $data['templates'] = $templates->fetchAll();
            $data['networks'] = $networks->fetchAll();
            $data['storages'] = $storages->fetchAll();
        }
        return Response::json(['data' => $data]);
    }
}
