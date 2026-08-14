<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

final class ProxmoxVmDiscovery
{
    public function __construct(private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /**
     * @param list<array{id:mixed,name:mixed,hostname:mixed,port:mixed,status:mixed}> $connections
     * @return array{vms:list<array<string,mixed>>,connections:list<array<string,mixed>>}
     */
    public function discover(array $connections): array
    {
        $vms = [];
        $statuses = [];
        foreach ($connections as $connection) {
            $connectionId = (int) ($connection['id'] ?? 0);
            $connectionName = trim((string) ($connection['name'] ?? 'Proxmox'));
            $status = ['id' => $connectionId, 'name' => $connectionName, 'status' => 'active', 'vm_count' => 0, 'error' => null];
            try {
                $resources = $this->clients->forConnection($connectionId)->get('/cluster/resources', ['type' => 'vm']);
                if (!is_array($resources)) throw new \RuntimeException('Proxmox returned an invalid virtual machine inventory response.');
                foreach ($resources as $resource) {
                    if (!is_array($resource) || ($resource['type'] ?? null) !== 'qemu' || $this->flag($resource['template'] ?? null)) continue;
                    $nodeName = trim((string) ($resource['node'] ?? ''));
                    $vmid = filter_var($resource['vmid'] ?? null, FILTER_VALIDATE_INT);
                    if ($nodeName === '' || $vmid === false) continue;
                    $vms[] = [
                        'connection_id' => $connectionId,
                        'connection_name' => $connectionName,
                        'node_name' => $nodeName,
                        'vmid' => (int) $vmid,
                        'name' => trim((string) ($resource['name'] ?? 'VM ' . $vmid)),
                        'status' => trim((string) ($resource['status'] ?? 'unknown')),
                        'cpu_usage' => isset($resource['cpu']) ? (float) $resource['cpu'] : null,
                        'cpu_count' => isset($resource['maxcpu']) ? (int) $resource['maxcpu'] : null,
                        'memory_used' => isset($resource['mem']) ? (int) $resource['mem'] : null,
                        'memory_total' => isset($resource['maxmem']) ? (int) $resource['maxmem'] : null,
                        'disk_used' => isset($resource['disk']) ? (int) $resource['disk'] : null,
                        'disk_total' => isset($resource['maxdisk']) ? (int) $resource['maxdisk'] : null,
                        'uptime' => isset($resource['uptime']) ? (int) $resource['uptime'] : null,
                        'lock' => trim((string) ($resource['lock'] ?? '')),
                        'tags' => trim((string) ($resource['tags'] ?? '')),
                        'ha_state' => trim((string) ($resource['hastate'] ?? '')),
                    ];
                    $status['vm_count']++;
                }
            } catch (\Throwable $exception) {
                $public = ProxmoxFailureMessage::asHttpException(
                    $exception,
                    (string) ($connection['hostname'] ?? 'unknown'),
                    (int) ($connection['port'] ?? 8006),
                    'Odczyt maszyn wirtualnych Proxmox',
                );
                $status['status'] = 'error';
                $status['error'] = $public->getMessage();
                $status['details'] = $public->details;
            }
            $statuses[] = $status;
        }

        usort($vms, static fn (array $left, array $right): int => [
            $left['connection_name'], $left['node_name'], $left['vmid'],
        ] <=> [
            $right['connection_name'], $right['node_name'], $right['vmid'],
        ]);
        return ['vms' => $vms, 'connections' => $statuses];
    }

    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'on'], true);
    }
}
