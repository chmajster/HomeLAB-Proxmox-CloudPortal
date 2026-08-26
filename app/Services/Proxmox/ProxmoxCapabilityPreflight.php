<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

final class ProxmoxCapabilityPreflight
{
    private const REQUIRED_PRIVILEGES = [
        'VM.Allocate',
        'VM.Clone',
        'VM.Config.CDROM',
        'VM.Config.CPU',
        'VM.Config.Cloudinit',
        'VM.Config.Disk',
        'VM.Config.Memory',
        'VM.Config.Network',
        'VM.PowerMgmt',
        'VM.Snapshot',
        'Datastore.AllocateSpace',
        'Datastore.Audit',
        'Sys.Audit',
    ];

    public function __construct(private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /** @return array<string,mixed> */
    public function check(int $connectionId): array
    {
        $client = $this->clients->forConnection($connectionId);
        $basic = [
            'version' => false,
            'cluster_status' => false,
            'nodes' => false,
            'storage' => false,
            'permissions_visible' => false,
        ];
        $errors = [];

        foreach ([
            'version' => ['/version', []],
            'cluster_status' => ['/cluster/status', []],
            'nodes' => ['/nodes', []],
            'storage' => ['/storage', []],
        ] as $name => [$path, $query]) {
            try {
                $client->get($path, $query);
                $basic[$name] = true;
            } catch (\Throwable $exception) {
                $errors[$name] = $exception->getMessage();
            }
        }

        $granted = [];
        try {
            $permissions = $client->get('/access/permissions');
            if (is_array($permissions)) {
                $basic['permissions_visible'] = true;
                foreach ($permissions as $path => $privileges) {
                    if (!is_array($privileges)) {
                        continue;
                    }
                    foreach ($privileges as $privilege => $value) {
                        if ((int) $value === 1 || $value === true) {
                            $granted[(string) $privilege] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $exception) {
            $errors['permissions'] = $exception->getMessage();
        }

        $required = [];
        foreach (self::REQUIRED_PRIVILEGES as $privilege) {
            $required[$privilege] = isset($granted[$privilege]);
        }
        $missing = array_keys(array_filter($required, static fn (bool $ok): bool => !$ok));
        $readiness = $basic['version'] && $basic['cluster_status'] && $basic['nodes'] && $basic['storage'];
        $permissionReadiness = $basic['permissions_visible'] ? $missing === [] : null;

        return [
            'connection_id' => $connectionId,
            'api_readiness' => $readiness,
            'permission_readiness' => $permissionReadiness,
            'ready_for_provisioning' => $readiness && $permissionReadiness === true,
            'checks' => $basic,
            'required_privileges' => $required,
            'missing_privileges' => $missing,
            'errors' => $errors,
            'note' => $basic['permissions_visible']
                ? null
                : 'The token can read core Proxmox endpoints, but /access/permissions is unavailable; mutating privileges cannot be proven.',
        ];
    }
}
