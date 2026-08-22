<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Database\Database;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Provisioning\VmIdentityService;

final class VmIdentityController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function rename(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('vm.modify');
        $user = $this->app->auth()->requireUser();
        $job = (new VmIdentityService(new Database($this->app->config)))->rename(
            (int) $request->param('id'),
            (int) $user['id'],
            $this->app->auth()->isAdmin(),
            (string) $request->input('name'),
        );
        return Response::json(['data' => ['job_id' => $job]], 202);
    }
}
