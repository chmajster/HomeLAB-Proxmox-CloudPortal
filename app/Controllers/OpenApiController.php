<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class OpenApiController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function spec(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $paths = [];
        foreach ($this->routes() as [$method, $path, $tag, $summary]) {
            $mutating = in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
            $operation = [
                'tags' => [$tag],
                'summary' => $summary,
                'operationId' => $this->operationId($method, $path),
                'security' => $mutating
                    ? [['cookieAuth' => [], 'csrfHeader' => []], ['bearerAuth' => []]]
                    : [['cookieAuth' => []], ['bearerAuth' => []]],
                'responses' => [
                    '200' => ['description' => 'Successful response'],
                    '400' => ['description' => 'Invalid request'],
                    '401' => ['$ref' => '#/components/responses/Unauthorized'],
                    '403' => ['$ref' => '#/components/responses/Forbidden'],
                    '409' => ['description' => 'Conflict or idempotency collision'],
                    '422' => ['$ref' => '#/components/responses/ValidationError'],
                    '429' => ['description' => 'Rate limited'],
                ],
            ];
            $parameters = $this->pathParameters($path);
            $parameters[] = $this->correlationParameter();
            if ($mutating && str_starts_with($path, '/api/')) {
                $parameters[] = $this->idempotencyParameter();
            }
            $operation['parameters'] = $parameters;
            $paths[$path][strtolower($method)] = $operation;
        }

        $this->decorate($paths);
        return Response::json([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Algen Proxmox Cloud Portal API',
                'version' => Application::VERSION,
                'description' => 'REST API Self-Service IaaS dla Proxmox VE. Obsługuje sesję WWW + CSRF oraz ograniczone tokeny Bearer. Mutacje API mogą używać Idempotency-Key; każda odpowiedź zwraca X-Correlation-ID.',
            ],
            'servers' => [['url' => $this->app->basePath() === '' ? '/' : $this->app->basePath(), 'description' => 'Current installation']],
            'tags' => array_map(static fn (string $name): array => ['name' => $name], ['System','Auth','Security','Catalog','VM','Jobs','Cloud-Init','SSH','Audit','Reconciliation','Administration']),
            'paths' => $paths,
            'components' => $this->components(),
        ]);
    }

    public function docs(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('admin.access');
        $base = htmlspecialchars($this->app->basePath(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $csrf = htmlspecialchars($this->app->csrf->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = htmlspecialchars((string) $this->app->setting('portal.name', 'Algen Cloud Portal'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $username = htmlspecialchars((string) $user['username'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="pl" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>API Docs — ' . $title . '</title><link rel="stylesheet" href="' . $base . '/assets/css/app.css"></head>'
            . '<body data-base-path="' . $base . '" data-csrf="' . $csrf . '"><main class="container-fluid p-3 p-md-5"><header class="d-flex flex-wrap align-items-center gap-3 mb-4"><div><p class="eyebrow mb-0">OpenAPI 3.1</p><h1 class="h2 mb-1">' . $title . ' API</h1><p class="text-secondary mb-0">Zalogowano jako ' . $username . '. Dokumentacja korzysta wyłącznie z zasobów lokalnych portalu.</p></div><a class="btn btn-outline-primary ms-auto" href="' . $base . '/dashboard">Portal</a><a class="btn btn-primary" href="' . $base . '/api/openapi.json">openapi.json</a></header><div id="openApiDocs"><div class="loading-panel"><span class="spinner-border" aria-hidden="true"></span><span>Ładowanie specyfikacji…</span></div></div></main><script src="' . $base . '/assets/js/openapi-docs.js" defer></script></body></html>';
        return Response::html($html);
    }

    /** @return list<array{0:string,1:string,2:string,3:string}> */
    private function routes(): array
    {
        return [
            ['GET','/healthz','System','Liveness probe'], ['GET','/readyz','System','Readiness probe'],
            ['GET','/api/v1/me','Auth','Current user'], ['POST','/api/v1/logout','Auth','End current session'],
            ['GET','/api/v1/me/security','Security','Account security status'], ['POST','/api/v1/me/mfa/setup','Security','Begin TOTP MFA setup'], ['POST','/api/v1/me/mfa/enable','Security','Enable TOTP MFA'], ['DELETE','/api/v1/me/mfa','Security','Disable MFA'], ['POST','/api/v1/me/password','Security','Change password'],
            ['GET','/api/v1/me/api-tokens','Security','List API tokens and allowed scopes'], ['POST','/api/v1/me/api-tokens','Security','Create scoped API token'], ['DELETE','/api/v1/me/api-tokens/{id}','Security','Revoke API token'],
            ['GET','/api/v1/me/sessions','Security','List server-side sessions'], ['DELETE','/api/v1/me/sessions/{id}','Security','Revoke session'], ['POST','/api/v1/me/sessions/revoke-others','Security','Revoke all other sessions'],
            ['POST','/api/v1/auth/password-reset/request','Security','Request password reset'], ['POST','/api/v1/auth/password-reset/complete','Security','Complete password reset'],
            ['GET','/api/v1/dashboard','Catalog','Dashboard summary'], ['GET','/api/v1/catalog','Catalog','Provisioning catalog'],
            ['GET','/api/v1/ssh-keys','SSH','List current user SSH keys'], ['POST','/api/v1/ssh-keys','SSH','Add current user SSH key'], ['DELETE','/api/v1/ssh-keys/{id}','SSH','Delete current user SSH key'],
            ['GET','/api/v1/cloud-init-profiles','Cloud-Init','List Cloud-Init profiles available to current user'], ['POST','/api/v1/cloud-init-profiles','Cloud-Init','Create Cloud-Init profile'], ['PATCH','/api/v1/cloud-init-profiles/{id}','Cloud-Init','Update Cloud-Init profile'], ['DELETE','/api/v1/cloud-init-profiles/{id}','Cloud-Init','Delete Cloud-Init profile'], ['GET','/api/v1/cloud-init-profiles/{id}/vendor-data','Cloud-Init','Download generated cloud-init vendor-data'],
            ['GET','/api/v1/vms','VM','List accessible virtual machines'], ['POST','/api/v1/vms','VM','Queue VM creation'], ['GET','/api/v1/vms/{id}','VM','VM details'], ['DELETE','/api/v1/vms/{id}','VM','Queue VM deletion'],
            ['POST','/api/v1/vms/{id}/snapshots','VM','Queue snapshot creation'], ['DELETE','/api/v1/vms/{id}/snapshots/{snapshotId}','VM','Queue snapshot deletion'], ['POST','/api/v1/vms/{id}/snapshots/{snapshotName}/rollback','VM','Queue snapshot rollback'],
            ['POST','/api/v1/vms/{id}/clone','VM','Queue VM clone'], ['POST','/api/v1/vms/{id}/resize','VM','Queue VM resize'], ['PATCH','/api/v1/vms/{id}/configuration','VM','Queue VM reconfiguration'], ['PATCH','/api/v1/vms/{id}/name','VM','Rename VM'],
            ['POST','/api/v1/vms/{id}/disks','VM','Attach VM disk'], ['DELETE','/api/v1/vms/{id}/disks/{device}','VM','Detach VM disk'], ['PUT','/api/v1/vms/{id}/nics/{device}','VM','Create or update VM NIC'], ['DELETE','/api/v1/vms/{id}/nics/{device}','VM','Delete VM NIC'],
            ['POST','/api/v1/vms/{id}/migrate','VM','Queue VM migration'], ['GET','/api/v1/vms/{id}/backups','VM','List VM backups'], ['POST','/api/v1/vms/{id}/backups','VM','Queue VM backup'], ['POST','/api/v1/backups/{backupId}/restore','VM','Queue backup restore'], ['PATCH','/api/v1/vms/{id}/assignment','VM','Assign VM to project and owner'], ['POST','/api/v1/vms/{id}/console','VM','Download SPICE console file'], ['POST','/api/v1/vms/{id}/{action}','VM','Queue VM power action'],
            ['GET','/api/v1/jobs','Jobs','List jobs'], ['GET','/api/v1/jobs/{id}','Jobs','Job details'],
            ['GET','/api/v1/admin/audit/search','Audit','Search central audit log'], ['GET','/api/v1/admin/audit/export','Audit','Export filtered audit log'],
            ['GET','/api/v1/admin/system/health','Administration','Administrative health status'], ['POST','/api/v1/admin/jobs/{jobId}/retry','Administration','Retry failed/dead-letter job'],
            ['GET','/api/v1/admin/proxmox/{connectionId}/preflight','Administration','Validate Proxmox API capabilities and privileges'],
            ['POST','/api/v1/admin/users/{id}/password-reset-token','Security','Issue one-time password reset token'],
            ['POST','/api/v1/admin/reconciliation/scan','Reconciliation','Scan portal, Proxmox and retained local state'], ['GET','/api/v1/admin/reconciliation/incidents','Reconciliation','List reconciliation incidents'], ['POST','/api/v1/admin/reconciliation/incidents/{id}','Reconciliation','Resolve or ignore reconciliation incident'],
            ['GET','/api/v1/admin/proxmox/{connectionId}/nodes/placement','Administration','List node placement settings'], ['PATCH','/api/v1/admin/proxmox/{connectionId}/nodes/{node}/placement','Administration','Update node placement settings'],
            ['GET','/api/v1/admin/webhooks','Administration','List webhooks'], ['POST','/api/v1/admin/webhooks','Administration','Create webhook'], ['PATCH','/api/v1/admin/webhooks/{id}','Administration','Update webhook'], ['DELETE','/api/v1/admin/webhooks/{id}','Administration','Delete webhook'], ['GET','/api/v1/admin/webhooks/{id}/deliveries','Administration','Webhook delivery history'],
            ['GET','/api/v1/admin/quota-template-limits','Administration','List template quota limits'], ['POST','/api/v1/admin/quotas','Administration','Create or update quota'], ['POST','/api/v1/admin/quota-template-limits','Administration','Create or update template quota limit'], ['DELETE','/api/v1/admin/quota-template-limits/{id}','Administration','Delete template quota limit'],
            ['GET','/api/v1/admin/networks/discovery','Administration','Discover Proxmox networks'], ['GET','/api/v1/admin/storages/discovery','Administration','Discover Proxmox storages'], ['GET','/api/v1/admin/templates/discovery','Administration','Discover Proxmox templates'], ['GET','/api/v1/admin/template-builder/options','Administration','Template builder options'],
            ['POST','/api/v1/admin/iso-uploads','Administration','Initialize chunked ISO upload'], ['POST','/api/v1/admin/iso-uploads/{uploadId}/chunks','Administration','Append ISO upload chunk'], ['POST','/api/v1/admin/iso-uploads/{uploadId}/complete','Administration','Complete ISO upload'], ['DELETE','/api/v1/admin/iso-uploads/{uploadId}','Administration','Cancel ISO upload'],
            ['POST','/api/v1/admin/template-builder/vms','Administration','Create installation VM'], ['POST','/api/v1/admin/template-builder/convert','Administration','Convert VM to template'], ['GET','/api/v1/admin/vms/discovery','Administration','Discover Proxmox VMs'],
            ['GET','/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}','Administration','Read live Proxmox VM details'], ['POST','/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/status/{action}','Administration','Live VM power action'], ['POST','/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/snapshots','Administration','Create live VM snapshot'], ['DELETE','/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/snapshots/{snapshotName}','Administration','Delete live VM snapshot'], ['POST','/api/v1/admin/proxmox-vms/{connectionId}/{node}/{vmid}/console','Administration','Download live VM console'],
            ['GET','/api/v1/admin/projects/{id}','Administration','Project details'], ['POST','/api/v1/admin/projects','Administration','Create project'], ['POST','/api/v1/admin/{resource}','Administration','Create administrative resource'], ['PATCH','/api/v1/admin/{resource}/{id}','Administration','Update administrative resource'], ['POST','/api/v1/admin/proxmox/{id}/sync','Administration','Synchronize Proxmox connection'], ['POST','/api/v1/admin/projects/{id}/members','Administration','Add project member'], ['POST','/api/v1/admin/projects/{id}/access','Administration','Assign project network/storage access'], ['DELETE','/api/v1/admin/projects/{id}/members/{userId}','Administration','Remove project member'], ['DELETE','/api/v1/admin/projects/{id}/access/{type}/{resourceId}','Administration','Remove project resource access'],
        ];
    }

    /** @param array<string,array<string,array<string,mixed>>> $paths */
    private function decorate(array &$paths): void
    {
        $paths['/api/v1/ssh-keys']['post']['requestBody'] = $this->jsonBody('SshKeyCreateRequest');
        $paths['/api/v1/cloud-init-profiles']['post']['requestBody'] = $this->jsonBody('CloudInitProfileWrite');
        $paths['/api/v1/cloud-init-profiles/{id}']['patch']['requestBody'] = $this->jsonBody('CloudInitProfileWrite');
        $paths['/api/v1/vms']['post']['requestBody'] = $this->jsonBody('VmCreateRequest');
        $paths['/api/v1/vms']['post']['responses']['202'] = ['description' => 'VM creation queued', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/JobReference']]]];
        $paths['/api/v1/admin/audit/search']['get']['parameters'] = [...$this->auditParameters(), $this->correlationParameter()];
        $paths['/api/v1/admin/audit/export']['get']['parameters'] = [...$this->auditParameters(), ['name' => 'format','in' => 'query','schema' => ['type' => 'string','enum' => ['csv','json']]], $this->correlationParameter()];
        $paths['/api/v1/admin/audit/search']['get']['responses']['200'] = ['description' => 'Filtered audit results', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/AuditSearchResponse']]]];

        foreach (['/api/v1/me/api-tokens','/api/v1/me/sessions','/api/v1/me/sessions/revoke-others','/api/v1/me/mfa/setup','/api/v1/me/mfa/enable','/api/v1/me/mfa','/api/v1/me/password'] as $path) {
            foreach ($paths[$path] ?? [] as &$operation) {
                $operation['security'] = [['cookieAuth' => [], 'csrfHeader' => []]];
            }
        }
        $paths['/api/v1/me/api-tokens']['get']['security'] = [['cookieAuth' => []]];
        $paths['/api/v1/me/sessions']['get']['security'] = [['cookieAuth' => []]];
        $paths['/api/v1/me/security']['get']['security'] = [['cookieAuth' => []]];
        $paths['/api/v1/auth/password-reset/request']['post']['security'] = [['csrfHeader' => []]];
        $paths['/api/v1/auth/password-reset/complete']['post']['security'] = [['csrfHeader' => []]];
    }

    /** @return array<string,mixed> */
    private function components(): array
    {
        return [
            'securitySchemes' => [
                'cookieAuth' => ['type' => 'apiKey','in' => 'cookie','name' => (string) $this->app->config->get('session.name', 'cloud_portal_session')],
                'csrfHeader' => ['type' => 'apiKey','in' => 'header','name' => 'X-CSRF-Token'],
                'bearerAuth' => ['type' => 'http','scheme' => 'bearer','bearerFormat' => 'cp_<prefix>_<secret>','description' => 'Scoped API token. Effective privileges are role permissions intersected with token scopes.'],
            ],
            'responses' => [
                'Unauthorized' => ['description' => 'Authentication required','content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                'Forbidden' => ['description' => 'Permission denied','content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
                'ValidationError' => ['description' => 'Validation error','content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
            ],
            'schemas' => [
                'Error' => ['type' => 'object','properties' => ['error' => ['type' => 'object','properties' => ['message' => ['type' => 'string']]]]],
                'JobReference' => ['type' => 'object','properties' => ['data' => ['type' => 'object','required' => ['job_id'],'properties' => ['job_id' => ['type' => 'string','format' => 'uuid']]]]],
                'SshKeyCreateRequest' => ['type' => 'object','required' => ['name','public_key'],'properties' => ['name' => ['type' => 'string','maxLength' => 100,'example' => 'Laptop'], 'public_key' => ['type' => 'string','example' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA... user@laptop']]],
                'CloudInitProfileWrite' => ['type' => 'object','required' => ['name','system_user'],'properties' => [
                    'name' => ['type' => 'string','maxLength' => 100,'example' => 'Ubuntu Standard'], 'description' => ['type' => 'string','maxLength' => 1000], 'system_user' => ['type' => 'string','example' => 'clouduser'],
                    'dns_servers' => ['type' => 'string','example' => '10.0.0.53,1.1.1.1'], 'search_domain' => ['type' => 'string','example' => 'lab.example'], 'timezone' => ['type' => 'string','example' => 'Europe/Warsaw'],
                    'packages' => ['oneOf' => [['type' => 'array','items' => ['type' => 'string']],['type' => 'string']]], 'runcmd' => ['oneOf' => [['type' => 'array','items' => ['type' => 'string']],['type' => 'string']]],
                    'qemu_guest_agent' => ['type' => 'boolean','default' => true], 'custom_yaml' => ['type' => 'string'], 'snippet_volume' => ['type' => 'string','example' => 'local:snippets/ubuntu-standard.yaml'],
                    'ssh_key_ids' => ['type' => 'array','items' => ['type' => 'integer']], 'is_global' => ['type' => 'boolean','description' => 'Administrators only'], 'enabled' => ['type' => 'boolean','default' => true],
                ]],
                'VmCreateRequest' => ['type' => 'object','required' => ['project_id','template_id','plan_id','network_id','storage_id'],'properties' => [
                    'name' => ['type' => 'string','example' => 'app-dev-01'], 'project_id' => ['type' => 'integer'], 'template_id' => ['type' => 'integer'], 'plan_id' => ['type' => 'integer'], 'network_id' => ['type' => 'integer'], 'storage_id' => ['type' => 'integer'],
                    'cloud_init_profile_id' => ['type' => 'integer','nullable' => true], 'ssh_key_ids' => ['type' => 'array','items' => ['type' => 'integer']], 'ssh_public_key' => ['type' => 'string','description' => 'Optional one-off public key'], 'cloud_init_user' => ['type' => 'string','description' => 'Used when no profile is selected'], 'start_after_create' => ['type' => 'boolean','default' => true],
                ]],
                'AuditEntry' => ['type' => 'object','properties' => ['id' => ['type' => 'integer'], 'correlation_id' => ['type' => ['string','null'],'format' => 'uuid'], 'created_at' => ['type' => 'string'], 'username' => ['type' => ['string','null']], 'ip_address' => ['type' => 'string'], 'action' => ['type' => 'string'], 'result' => ['type' => 'string','enum' => ['success','failure']], 'project_id' => ['type' => ['integer','null']], 'project_name' => ['type' => ['string','null']], 'virtual_machine_id' => ['type' => ['integer','null']], 'vm_name' => ['type' => ['string','null']], 'job_id' => ['type' => ['integer','null']], 'job_public_id' => ['type' => ['string','null']], 'proxmox_upid' => ['type' => ['string','null']], 'metadata' => ['type' => ['object','null']]]],
                'AuditSearchResponse' => ['type' => 'object','properties' => ['data' => ['type' => 'object','properties' => ['items' => ['type' => 'array','items' => ['$ref' => '#/components/schemas/AuditEntry']], 'total' => ['type' => 'integer'], 'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'], 'pages' => ['type' => 'integer']]]]],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function pathParameters(string $path): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $path, $matches);
        return array_map(static fn (string $name): array => ['name' => $name,'in' => 'path','required' => true,'schema' => ['type' => 'string']], $matches[1] ?? []);
    }

    /** @return list<array<string,mixed>> */
    private function auditParameters(): array
    {
        return [
            ['name' => 'q','in' => 'query','schema' => ['type' => 'string']], ['name' => 'user_id','in' => 'query','schema' => ['type' => 'integer']], ['name' => 'project_id','in' => 'query','schema' => ['type' => 'integer']], ['name' => 'vm_id','in' => 'query','schema' => ['type' => 'integer']],
            ['name' => 'job','in' => 'query','schema' => ['type' => 'string']], ['name' => 'proxmox_upid','in' => 'query','schema' => ['type' => 'string']], ['name' => 'action','in' => 'query','schema' => ['type' => 'string']], ['name' => 'result','in' => 'query','schema' => ['type' => 'string','enum' => ['success','failure']]],
            ['name' => 'from','in' => 'query','schema' => ['type' => 'string','format' => 'date-time']], ['name' => 'to','in' => 'query','schema' => ['type' => 'string','format' => 'date-time']], ['name' => 'page','in' => 'query','schema' => ['type' => 'integer','minimum' => 1]], ['name' => 'per_page','in' => 'query','schema' => ['type' => 'integer','minimum' => 10,'maximum' => 200]],
        ];
    }

    /** @return array<string,mixed> */
    private function idempotencyParameter(): array
    {
        return ['name' => 'Idempotency-Key','in' => 'header','required' => false,'description' => '8-128 safe ASCII characters. Reusing the same key and payload replays the completed response without repeating the side effect.','schema' => ['type' => 'string','minLength' => 8,'maxLength' => 128]];
    }

    /** @return array<string,mixed> */
    private function correlationParameter(): array
    {
        return ['name' => 'X-Correlation-ID','in' => 'header','required' => false,'description' => 'Optional UUID propagated to the response and audit trail. A UUID is generated when omitted.','schema' => ['type' => 'string','format' => 'uuid']];
    }

    /** @return array<string,mixed> */
    private function jsonBody(string $schema): array
    {
        return ['required' => true,'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $schema]]]];
    }

    private function operationId(string $method, string $path): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]+/', '_', trim($path, '/')) ?? 'root';
        return strtolower($method) . '_' . trim($clean, '_');
    }
}
