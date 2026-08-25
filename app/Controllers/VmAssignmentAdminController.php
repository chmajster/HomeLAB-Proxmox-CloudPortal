<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class VmAssignmentAdminController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function options(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $vmId = (int) $request->param('id');
        if ($vmId <= 0) throw new HttpException(404, 'Virtual machine not found.');

        $vmStatement = $this->app->pdo()->prepare("SELECT id,project_id,owner_user_id,network_id,storage_id FROM virtual_machines WHERE id=:id AND status<>'deleted'");
        $vmStatement->execute(['id' => $vmId]);
        $vm = $vmStatement->fetch();
        if (!is_array($vm)) throw new HttpException(404, 'Virtual machine not found.');

        $targets = $this->app->pdo()->prepare(
            "SELECT p.id AS project_id,p.name AS project_name,u.id AS user_id,u.username,pu.membership_role
             FROM projects p
             JOIN project_users pu ON pu.project_id=p.id
             JOIN users u ON u.id=pu.user_id
             WHERE p.status='active' AND u.status='active'
               AND EXISTS (SELECT 1 FROM project_networks pn WHERE pn.project_id=p.id AND pn.network_id=:network)
               AND EXISTS (SELECT 1 FROM project_storages ps WHERE ps.project_id=p.id AND ps.storage_id=:storage)
             ORDER BY p.name,u.username"
        );
        $targets->execute(['network' => $vm['network_id'], 'storage' => $vm['storage_id']]);

        return Response::json(['data' => [
            'current' => ['project_id' => (int) $vm['project_id'], 'owner_user_id' => (int) $vm['owner_user_id']],
            'targets' => $targets->fetchAll(),
        ]]);
    }
}
