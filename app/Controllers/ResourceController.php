<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class ResourceController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $resource = $request->param('resource');
        $admin = $this->app->auth()->isAdmin();
        [$sql, $params] = match ($resource) {
            'projects' => $admin
                ? ["SELECT id, name, slug, description, status, created_at FROM projects ORDER BY name", []]
                : ["SELECT p.id, p.name, p.slug, p.description, p.status, pu.membership_role, p.created_at FROM projects p JOIN project_users pu ON pu.project_id=p.id WHERE pu.user_id=:user ORDER BY p.name", ['user' => $user['id']]],
            'networks' => $admin
                ? ["SELECT n.id,n.name,n.bridge,n.vlan_id,n.subnet,n.gateway,n.dns_servers,n.enabled,c.name AS connection_name FROM networks n JOIN proxmox_connections c ON c.id=n.connection_id ORDER BY n.name", []]
                : ["SELECT DISTINCT n.id,n.name,n.bridge,n.vlan_id,n.subnet,n.gateway,n.dns_servers,n.enabled FROM networks n JOIN project_networks pn ON pn.network_id=n.id JOIN project_users pu ON pu.project_id=pn.project_id WHERE pu.user_id=:user AND n.enabled=1 ORDER BY n.name", ['user' => $user['id']]],
            'templates' => $admin
                ? ["SELECT t.id,t.name,t.operating_system,t.description,t.node_name,t.vmid,t.enabled,c.name AS connection_name FROM vm_templates t JOIN proxmox_connections c ON c.id=t.connection_id ORDER BY t.name", []]
                : ["SELECT DISTINCT t.id,t.name,t.operating_system,t.description,t.node_name,t.vmid FROM vm_templates t JOIN networks n ON n.connection_id=t.connection_id AND (n.node_name IS NULL OR n.node_name=t.node_name) JOIN project_networks pn ON pn.network_id=n.id JOIN project_users pu ON pu.project_id=pn.project_id JOIN storages s ON s.connection_id=t.connection_id AND (s.node_name IS NULL OR s.node_name=t.node_name) JOIN project_storages ps ON ps.storage_id=s.id AND ps.project_id=pu.project_id WHERE pu.user_id=:user AND t.enabled=1 AND s.enabled=1 ORDER BY t.name", ['user' => $user['id']]],
            default => throw new HttpException(404, 'Resource not found.'),
        };
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        return Response::json(['data' => $statement->fetchAll()]);
    }
}
