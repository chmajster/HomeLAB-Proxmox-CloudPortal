<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use PDO;

final class AdminResourceDetailsController
{
    private const ALLOWED = ['users', 'proxmox', 'networks', 'templates', 'storages', 'plans'];

    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $resource = $request->param('resource');
        $id = (int) $request->param('id');
        if (!in_array($resource, self::ALLOWED, true) || $id <= 0) {
            throw new HttpException(404, 'Administration resource not found.');
        }

        $pdo = $this->app->pdo();
        [$record, $related] = match ($resource) {
            'users' => $this->user($pdo, $id),
            'proxmox' => $this->proxmox($pdo, $id),
            'networks' => $this->network($pdo, $id),
            'templates' => $this->template($pdo, $id),
            'storages' => $this->storage($pdo, $id),
            'plans' => $this->plan($pdo, $id),
        };

        if ($record === null) throw new HttpException(404, 'Administration resource not found.');
        return Response::json(['data' => ['resource' => $resource, 'record' => $record, 'related' => $related]]);
    }

    /** @return array{0:?array<string,mixed>,1:array<string,mixed>} */
    private function user(PDO $pdo, int $id): array
    {
        $record = $this->one($pdo, 'SELECT u.id,u.username,u.email,u.status,u.locale,u.session_version,u.last_login_at,u.created_at,u.updated_at,r.slug AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=:id', $id);
        if ($record === null) return [null, []];
        return [$record, [
            'projects' => $this->all($pdo, 'SELECT p.id,p.name,p.slug,pu.membership_role FROM project_users pu JOIN projects p ON p.id=pu.project_id WHERE pu.user_id=:id ORDER BY p.name', $id),
            'vms' => $this->all($pdo, "SELECT id,name,vmid,node_name,status,project_id FROM virtual_machines WHERE owner_user_id=:id AND status<>'deleted' ORDER BY created_at DESC LIMIT 100", $id),
        ]];
    }

    /** @return array{0:?array<string,mixed>,1:array<string,mixed>} */
    private function proxmox(PDO $pdo, int $id): array
    {
        $record = $this->one($pdo, 'SELECT id,name,hostname,port,realm,api_token_id,verify_ssl,status,cluster_name,last_checked_at,last_error,created_at,updated_at FROM proxmox_connections WHERE id=:id', $id);
        if ($record === null) return [null, []];
        return [$record, [
            'nodes' => $this->all($pdo, 'SELECT id,node_name,status,cpu_usage,memory_total,memory_used,last_seen_at FROM proxmox_nodes WHERE connection_id=:id ORDER BY node_name', $id),
            'networks' => $this->all($pdo, 'SELECT id,name,node_name,bridge,vlan_id,subnet,enabled FROM networks WHERE connection_id=:id ORDER BY name', $id),
            'storages' => $this->all($pdo, 'SELECT id,storage_name,node_name,content_types,enabled FROM storages WHERE connection_id=:id ORDER BY storage_name', $id),
            'templates' => $this->all($pdo, 'SELECT id,name,node_name,vmid,operating_system,enabled FROM vm_templates WHERE connection_id=:id ORDER BY name', $id),
        ]];
    }

    /** @return array{0:?array<string,mixed>,1:array<string,mixed>} */
    private function network(PDO $pdo, int $id): array
    {
        $record = $this->one($pdo, "SELECT n.id,n.name,n.connection_id,c.name AS connection_name,n.node_name,n.bridge,n.vlan_id,n.subnet,n.gateway,n.dns_servers,n.enabled,n.created_at,n.updated_at,COUNT(ip.id) AS address_count,SUM(ip.state='free') AS free_count,SUM(ip.state='reserved') AS reserved_count,SUM(ip.state='allocated') AS allocated_count FROM networks n JOIN proxmox_connections c ON c.id=n.connection_id LEFT JOIN ip_addresses ip ON ip.network_id=n.id WHERE n.id=:id GROUP BY n.id", $id);
        if ($record === null) return [null, []];
        return [$record, [
            'projects' => $this->all($pdo, 'SELECT p.id,p.name,p.slug FROM project_networks pn JOIN projects p ON p.id=pn.project_id WHERE pn.network_id=:id ORDER BY p.name', $id),
            'vms' => $this->all($pdo, "SELECT id,name,vmid,status,node_name,project_id FROM virtual_machines WHERE network_id=:id AND status<>'deleted' ORDER BY created_at DESC LIMIT 100", $id),
        ]];
    }

    /** @return array{0:?array<string,mixed>,1:array<string,mixed>} */
    private function template(PDO $pdo, int $id): array
    {
        $record = $this->one($pdo, 'SELECT t.id,t.name,t.connection_id,c.name AS connection_name,t.node_name,t.vmid,t.operating_system,t.description,t.enabled,t.created_at,t.updated_at FROM vm_templates t JOIN proxmox_connections c ON c.id=t.connection_id WHERE t.id=:id', $id);
        if ($record === null) return [null, []];
        return [$record, [
            'vms' => $this->all($pdo, "SELECT id,name,vmid,status,node_name,project_id FROM virtual_machines WHERE template_id=:id AND status<>'deleted' ORDER BY created_at DESC LIMIT 100", $id),
        ]];
    }

    /** @return array{0:?array<string,mixed>,1:array<string,mixed>} */
    private function storage(PDO $pdo, int $id): array
    {
        $record = $this->one($pdo, 'SELECT s.id,s.storage_name,s.connection_id,c.name AS connection_name,s.node_name,s.content_types,s.enabled,s.created_at,s.updated_at FROM storages s JOIN proxmox_connections c ON c.id=s.connection_id WHERE s.id=:id', $id);
        if ($record === null) return [null, []];
        return [$record, [
            'projects' => $this->all($pdo, 'SELECT p.id,p.name,p.slug FROM project_storages ps JOIN projects p ON p.id=ps.project_id WHERE ps.storage_id=:id ORDER BY p.name', $id),
            'vms' => $this->all($pdo, "SELECT id,name,vmid,status,node_name,project_id FROM virtual_machines WHERE storage_id=:id AND status<>'deleted' ORDER BY created_at DESC LIMIT 100", $id),
        ]];
    }

    /** @return array{0:?array<string,mixed>,1:array<string,mixed>} */
    private function plan(PDO $pdo, int $id): array
    {
        $record = $this->one($pdo, 'SELECT id,name,slug,vcpu,ram_mb,disk_gb,allow_resize,enabled,sort_order,created_at,updated_at FROM resource_plans WHERE id=:id', $id);
        if ($record === null) return [null, []];
        return [$record, [
            'vms' => $this->all($pdo, "SELECT id,name,vmid,status,node_name,project_id FROM virtual_machines WHERE resource_plan_id=:id AND status<>'deleted' ORDER BY created_at DESC LIMIT 100", $id),
        ]];
    }

    /** @return array<string,mixed>|null */
    private function one(PDO $pdo, string $sql, int $id): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function all(PDO $pdo, string $sql, int $id): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }
}
