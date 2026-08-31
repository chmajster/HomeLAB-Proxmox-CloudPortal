<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Cloud\PrivateCloudArchitecture;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class CloudArchitectureController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function capabilities(Request $request): Response
    {
        $this->app->auth()->requireUser();

        return Response::json(['data' => PrivateCloudArchitecture::describe()]);
    }
}
