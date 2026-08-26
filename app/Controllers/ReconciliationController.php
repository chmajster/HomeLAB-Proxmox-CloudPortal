<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;
use CloudPortal\Services\Reconciliation\ReconciliationService;

final class ReconciliationController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function scan(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        $service = $this->service();
        $result = $service->scan();
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'reconciliation.scan', 'success', null, null, $result);
        return Response::json(['data' => $result]);
    }

    public function incidents(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        return Response::json(['data' => $this->service()->incidents((string) $request->query('status', 'open'))]);
    }

    public function close(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        $status = strtolower(trim((string) $request->input('status', 'resolved')));
        if (!in_array($status, ['resolved','ignored'], true)) {
            throw new HttpException(422, 'Incident status must be resolved or ignored.');
        }
        $id = (int) $request->param('id');
        if (!$this->service()->close($id, $status)) {
            throw new HttpException(404, 'Open reconciliation incident was not found.');
        }
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'reconciliation.' . $status, 'success', 'reconciliation_incident', $id);
        return Response::json(['data' => ['id' => $id, 'status' => $status]]);
    }

    private function service(): ReconciliationService
    {
        return new ReconciliationService(
            $this->app->pdo(),
            new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()),
        );
    }
}
