<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Dashboard\CloudDashboardService;
use CloudPortal\Services\Proxmox\InfrastructureService;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;

final class DashboardController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $pdo = $this->app->pdo();
        $service = new CloudDashboardService(
            $pdo,
            new InfrastructureService($pdo, new ProxmoxClientFactory($pdo, $this->app->crypto())),
        );

        return Response::json([
            'data' => $service->build($user, $this->app->auth()->isAdmin()),
        ]);
    }
}
