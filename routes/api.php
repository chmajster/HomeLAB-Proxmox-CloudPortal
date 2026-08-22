<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Controllers\AdvancedVmController;
use CloudPortal\Controllers\AdminController;
use CloudPortal\Controllers\AuthController;
use CloudPortal\Controllers\CatalogController;
use CloudPortal\Controllers\DashboardController;
use CloudPortal\Controllers\JobController;
use CloudPortal\Controllers\ResourceController;
use CloudPortal\Controllers\SystemController;
use CloudPortal\Controllers\TemplateAdminController;
use CloudPortal\Controllers\VmController;
use CloudPortal\Controllers\WebhookAdminController;
use CloudPortal\Http\Router;

return static function (Router $router, Application $app): void {
    $auth = new AuthController($app);
    $dashboard = new DashboardController($app);
    $vms = new VmController($app);
    $advancedVms = new AdvancedVmController($app);
    $jobs = new JobController($app);
    $catalog = new CatalogController($app);
    $resources = new ResourceController($app);
    $admin = new AdminController($app);
    $templateAdmin = new TemplateAdminController($app);
    $system = new SystemController($app);
    $webhooks = new WebhookAdminController($app);

    $router->add('GET', '/healthz', [$system, 'health']);
    $router->add('GET', '/readyz', [$system, 'ready']);

    $router->add('GET', '/api/v1/me', [$auth, 'me']);
    $router->add('POST', '/api/v1/logout', [$auth, 'logout']);
    $router->add('GET', '/api/v1/dashboard', [$dashboard, 'index']);
    $router->add('GET', '/api/v1/catalog', [$catalog, 'index']);
    $router->add('GET', '/api/v1/vms', [$vms, 'index']);
    $router->add('POST', '/api/v1/vms', [$vms, 'create']);
    $router->add('GET', '/api/v1/vms/{id}', [$vms, 'show']);
    $router->add('DELETE', '/api/v1/vms/{id}', [$vms, 'delete']);
    $router->add('POST', '/api/v1/vms/{id}/snapshots', [$vms, 'snapshot']);
    $router->add('DELETE', '/api/v1/vms/{id}/snapshots/{snapshotId}', [$vms, 'deleteSnapshot']);
    $router->add('POST', '/api/v1/vms/{id}/snapshots/{snapshotName}/rollback', [$advancedVms, 'rollbackSnapshot']);
    $router->add('POST', '/api/v1/vms/{id}/clone', [$advancedVms, 'cloneVm']);
    $router->add('POST', '/api/v1/vms/{id}/resize', [$vms, 'resize']);
    $router->add('PATCH', '/api/v1/vms/{id}/configuration', [$advancedVms, 'reconfigure']);
    $router->add('POST', '/api/v1/vms/{id}/disks', [$advancedVms, 'attachDisk']);
    $router->add('DELETE', '/api/v1/vms/{id}/disks/{device}', [$advancedVms, 'detachDisk']);
    $router->add('PUT', '/api/v1/vms/{id}/nics/{device}', [$advancedVms, 'upsertNic']);
    $router->add('DELETE', '/api/v1/vms/{id}/nics/{device}', [$advancedVms, 'deleteNic']);
    $router->add('POST', '/api/v1/vms/{id}/migrate', [$advancedVms, 'migrate']);
    $router->add('GET', '/api/v1/vms/{id}/backups', [$advancedVms, 'backups']);
    $router->add('POST', '/api/v1/vms/{id}/backups', [$advancedVms, 'createBackup']);
    $router->add('POST', '/api/v1/backups/{backupId}/restore', [$advancedVms, 'restoreBackup']);
    $router->add('PATCH', '/api/v1/vms/{id}/assignment', [$vms, 'assign']);
    $router->add('POST', '/api/v1/vms/{id}/console', [$vms, 'console']);
    $router->add('POST', '/api/v1/vms/{id}/{action}', [$vms, 'power']);
    $router->add('GET', '/api/v1/jobs', [$jobs, 'index']);
    $router->add('GET', '/api/v1/jobs/{id}', [$jobs, 'show']);
    $router->add('GET', '/api/v1/{resource}', [$resources, 'index']);

    $router->add('GET', '/api/v1/admin/system/health', [$system, 'adminHealth']);
    $router->add('POST', '/api/v1/admin/jobs/{jobId}/retry', [$system, 'retryJob']);
    $router->add('GET', '/api/v1/admin/proxmox/{connectionId}/placement', [$system, 'placement']);
    $router->add('GET', '/api/v1/admin/webhooks', [$webhooks, 'index']);
    $router->add('POST', '/api/v1/admin/webhooks', [$webhooks, 'create']);
    $router->add('PATCH', '/api/v1/admin/webhooks/{id}', [$webhooks, 'update']);
    $router->add('DELETE', '/api/v1/admin/webhooks/{id}', [$webhooks, 'delete']);
    $router->add('GET', '/api/v1/admin/webhooks/{id}/deliveries', [$webhooks, 'deliveries']);

    $router->add('GET', '/api/v1/admin/{resource}', [$admin, 'index']);
    $router->add('GET', '/api/v1/admin/networks/discovery', [$admin, 'networkDiscovery']);
    $router->add('GET', '/api/v1/admin/storages/discovery', [$admin, 'storageDiscovery']);
    $router->add('GET', '/api/v1/admin/templates/discovery', [$admin, 'templateDiscovery']);
    $router->add('GET', '/api/v1/admin/template-builder/options', [$templateAdmin, 'options']);
    $router->add('POST', '/api/v1/admin/iso-uploads', [$templateAdmin, 'initializeIsoUpload']);
    $router->add('POST', '/api/v1/admin/iso-uploads/{uploadId}/chunks', [$templateAdmin, 'appendIsoChunk']);
    $router->add('POST', '/api/v1/admin/iso-uploads/{uploadId}/complete', [$templateAdmin, 'completeIsoUpload']);
    $router->add('DELETE', '/api/v1/admin/iso-uploads/{uploadId}', [$templateAdmin, 'cancelIsoUpload']);
    $router->add('POST', '/api/v1/admin/template-builder/vms', [$templateAdmin, 'createInstallationVm']);
    $router->add('POST', '/api/v1/admin/template-builder/convert', [$templateAdmin, 'convertVmToTemplate']);
    $router->add('GET', '/api/v1/admin/vms/discovery', [$admin, 'vmDiscovery']);
    $router->add('GET', '/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}', [$admin, 'liveVmDetails']);
    $router->add('POST', '/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/status/{action}', [$admin, 'liveVmPower']);
    $router->add('POST', '/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/snapshots', [$admin, 'liveVmSnapshot']);
    $router->add('DELETE', '/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/snapshots/{snapshotName}', [$admin, 'deleteLiveVmSnapshot']);
    $router->add('POST', '/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/console', [$admin, 'liveVmConsole']);
    $router->add('GET', '/api/v1/admin/projects/{id}', [$admin, 'project']);
    $router->add('POST', '/api/v1/admin/{resource}', [$admin, 'create']);
    $router->add('PATCH', '/api/v1/admin/{resource}/{id}', [$admin, 'update']);
    $router->add('POST', '/api/v1/admin/proxmox/{id}/sync', [$admin, 'syncProxmox']);
    $router->add('POST', '/api/v1/admin/projects/{id}/members', [$admin, 'projectMembership']);
    $router->add('POST', '/api/v1/admin/projects/{id}/access', [$admin, 'projectAccess']);
    $router->add('DELETE', '/api/v1/admin/projects/{id}/members/{userId}', [$admin, 'removeProjectMembership']);
    $router->add('DELETE', '/api/v1/admin/projects/{id}/access/{type}/{resourceId}', [$admin, 'removeProjectAccess']);
};
