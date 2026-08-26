<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Proxmox\ProxmoxCapabilityPreflight;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;

final class ProxmoxPreflightController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $id = (int) $request->param('connectionId');
        if ($id <= 0) {
            throw new HttpException(422, 'Invalid Proxmox connection ID.');
        }
        $statement = $this->app->pdo()->prepare('SELECT id,status FROM proxmox_connections WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $id]);
        $connection = $statement->fetch();
        if (!is_array($connection)) {
            throw new HttpException(404, 'Proxmox connection does not exist.');
        }
        if ((string) $connection['status'] === 'disabled') {
            throw new HttpException(409, 'Proxmox connection is disabled.');
        }

        $report = (new ProxmoxCapabilityPreflight(
            new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()),
        ))->check($id);
        $this->app->audit()->log(
            $this->app->auth()->id(),
            $request->ip(),
            'proxmox.preflight',
            $report['ready_for_provisioning'] ? 'success' : 'failure',
            'proxmox_connection',
            (string) $id,
            [
                'api_readiness' => $report['api_readiness'],
                'permission_readiness' => $report['permission_readiness'],
                'missing_privileges' => $report['missing_privileges'],
            ],
        );
        return Response::json(['data' => $report]);
    }
}
