<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Controllers\AdminResourceDetailsPageController;
use CloudPortal\Controllers\AuthController;
use CloudPortal\Controllers\PortalController;
use CloudPortal\Controllers\ProjectDetailsPageController;
use CloudPortal\Controllers\VmDetailsPageController;
use CloudPortal\Http\Router;
use CloudPortal\Installer\Controllers\InstallerController;
use CloudPortal\Installer\Controllers\ProxmoxSetupController;

return static function (Router $router, Application $app): void {
    $installer = new InstallerController($app);
    $proxmoxSetup = new ProxmoxSetupController($app);
    $auth = new AuthController($app);
    $portal = new PortalController($app);
    $adminResourceDetails = new AdminResourceDetailsPageController($app);
    $projectDetails = new ProjectDetailsPageController($app);
    $vmDetails = new VmDetailsPageController($app);

    $router->add('GET', '/install', [$installer, 'show']);
    $router->add('POST', '/install', [$installer, 'submit']);
    $router->add('POST', '/install/test/database', [$installer, 'testDatabase']);
    $router->add('POST', '/install/proxmox', [$proxmoxSetup, 'save']);
    $router->add('POST', '/install/test/proxmox', [$proxmoxSetup, 'test']);
    $router->add('POST', '/install/requirements', [$installer, 'recheckRequirements']);
    $router->add('POST', '/install/finalize', [$installer, 'finalize']);
    $router->add('GET', '/install/finish', [$installer, 'finish']);
    $router->add('GET', '/login', [$auth, 'loginPage']);
    $router->add('POST', '/login', [$auth, 'login']);
    $router->add('POST', '/logout', [$auth, 'logout']);
    $router->add('GET', '/', [$portal, 'home']);
    $router->add('GET', '/admin/{resource}/{id}', [$adminResourceDetails, 'show']);
    $router->add('GET', '/projects/{id}', [$projectDetails, 'show']);
    $router->add('GET', '/vms/{id}', [$vmDetails, 'portal']);
    $router->add('GET', '/infrastructure/vms/{connectionId}/{node}/{vmid}', [$vmDetails, 'live']);
    $router->add('GET', '/{page}', [$portal, 'page']);
};
