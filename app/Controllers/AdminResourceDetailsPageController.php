<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class AdminResourceDetailsPageController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $user = $this->app->auth()->user();
        if ($user === null) return Response::redirect($this->app->url('/login'));
        $this->app->auth()->requirePermission('admin.access');

        return Response::html($this->app->view->render('pages/portal', [
            'user' => $user,
            'isAdmin' => true,
            'page' => 'admin-resource-details',
            'csrf' => $this->app->csrf->token(),
            'appName' => $this->app->setting('portal.name', $this->app->config->get('app.name')),
            'firstRun' => null,
            'managedProvisioning' => false,
            'hostnamePattern' => (string) $this->app->config->get('hostname_generator.pattern', 'vm-{project}-{counter}'),
        ]));
    }
}
