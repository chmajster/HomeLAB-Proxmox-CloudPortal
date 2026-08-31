<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Controllers\VmBlueprintController;
use CloudPortal\Http\Router;

return static function (Router $router, Application $app): void {
    $blueprints = new VmBlueprintController($app);

    $router->add('GET', '/api/v1/blueprints', [$blueprints, 'index']);
    $router->add('POST', '/api/v1/blueprints/{id}/deploy', [$blueprints, 'deploy']);

    $router->add('GET', '/api/v1/admin/blueprints', [$blueprints, 'adminIndex']);
    $router->add('POST', '/api/v1/admin/blueprints', [$blueprints, 'create']);
    $router->add('PATCH', '/api/v1/admin/blueprints/{id}', [$blueprints, 'update']);
};
