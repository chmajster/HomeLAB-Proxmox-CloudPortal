<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class VmDetailsPageController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function portal(Request $request): Response
    {
        return $this->render(false);
    }

    public function live(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        return $this->render(true);
    }

    private function render(bool $adminOnly): Response
    {
        $user = $this->app->auth()->user();
        if ($user === null) return Response::redirect($this->app->url('/login'));
        if ($adminOnly && !$this->app->auth()->isAdmin()) return Response::redirect($this->app->url('/dashboard'));

        return Response::html($this->app->view->render('pages/portal', [
            'user' => $user,
            'isAdmin' => $this->app->auth()->isAdmin(),
            'page' => 'vm-details',
            'csrf' => $this->app->csrf->token(),
            'appName' => $this->app->setting('portal.name', $this->app->config->get('app.name')),
            'firstRun' => null,
            'managedProvisioning' => false,
            'hostnamePattern' => (string) $this->app->config->get('hostname_generator.pattern', 'vm-{project}-{counter}'),
        ]));
    }
}
