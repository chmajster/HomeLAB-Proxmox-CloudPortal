<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

final class ProxmoxCatalogDiscovery
{
    public function __construct(private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /**
     * @param list<array{id:mixed,name:mixed,hostname:mixed,port:mixed,status:mixed}> $connections
     * @return array{storages:list<array<string,mixed>>,connections:list<array<string,mixed>>}
     */
    public function storages(array $connections): array
    {
        $storages = [];
        $statuses = [];
        foreach ($connections as $connection) {
            $connectionId = (int) ($connection['id'] ?? 0);
            $connectionName = trim((string) ($connection['name'] ?? 'Proxmox'));
            $status = $this->status($connectionId, $connectionName);
            try {
                $client = $this->clients->forConnection($connectionId);
                $definitions = $client->get('/storage');
                $nodes = $client->get('/nodes');
                if (!is_array($definitions) || !is_array($nodes)) {
                    throw new \RuntimeException('Proxmox returned an invalid storage inventory response.');
                }

                $nodeStates = [];
                foreach ($nodes as $node) {
                    if (!is_array($node) || trim((string) ($node['node'] ?? '')) === '') continue;
                    $nodeName = trim((string) $node['node']);
                    $nodeStorages = $client->get('/nodes/' . rawurlencode($nodeName) . '/storage');
                    if (!is_array($nodeStorages)) {
                        throw new \RuntimeException('Proxmox returned an invalid node storage response.');
                    }
                    foreach ($nodeStorages as $nodeStorage) {
                        if (!is_array($nodeStorage) || trim((string) ($nodeStorage['storage'] ?? '')) === '') continue;
                        $nodeStates[(string) $nodeStorage['storage']][] = [
                            'node_name' => $nodeName,
                            'node_status' => (string) ($node['status'] ?? 'unknown'),
                            'active' => $this->optionalFlag($nodeStorage, 'active'),
                            'enabled' => $this->optionalFlag($nodeStorage, 'enabled'),
                            'total_bytes' => isset($nodeStorage['total']) ? (int) $nodeStorage['total'] : null,
                            'used_bytes' => isset($nodeStorage['used']) ? (int) $nodeStorage['used'] : null,
                            'available_bytes' => isset($nodeStorage['avail']) ? (int) $nodeStorage['avail'] : null,
                        ];
                    }
                }

                foreach ($definitions as $definition) {
                    if (!is_array($definition) || trim((string) ($definition['storage'] ?? '')) === '') continue;
                    $storageName = trim((string) $definition['storage']);
                    $content = trim((string) ($definition['content'] ?? ''));
                    $states = $nodeStates[$storageName] ?? [];
                    $availableNodes = array_values(array_filter($states, static fn (array $node): bool =>
                        $node['node_status'] === 'online' && $node['active'] !== false && $node['enabled'] !== false
                    ));
                    $storages[] = [
                        'connection_id' => $connectionId,
                        'connection_name' => $connectionName,
                        'storage_name' => $storageName,
                        'type' => trim((string) ($definition['type'] ?? 'unknown')),
                        'content_types' => $content,
                        'shared' => $this->optionalFlag($definition, 'shared'),
                        'enabled' => !in_array($definition['disable'] ?? null, [true, 1, '1', 'yes', 'on'], true),
                        'restricted_nodes' => trim((string) ($definition['nodes'] ?? '')),
                        'nodes' => $states,
                        'available_nodes' => array_column($availableNodes, 'node_name'),
                        'supports_images' => in_array('images', array_map('trim', explode(',', $content)), true),
                    ];
                    $status['resource_count']++;
                }
            } catch (\Throwable $exception) {
                $status = $this->failedStatus($status, $connection, $exception, 'Odczyt storage Proxmox');
            }
            $statuses[] = $status;
        }

        usort($storages, static fn (array $left, array $right): int => [
            $left['connection_name'], $left['storage_name'],
        ] <=> [
            $right['connection_name'], $right['storage_name'],
        ]);
        return ['storages' => $storages, 'connections' => $statuses];
    }

    /**
     * @param list<array{id:mixed,name:mixed,hostname:mixed,port:mixed,status:mixed}> $connections
     * @return array{templates:list<array<string,mixed>>,connections:list<array<string,mixed>>}
     */
    public function templates(array $connections): array
    {
        $templates = [];
        $statuses = [];
        foreach ($connections as $connection) {
            $connectionId = (int) ($connection['id'] ?? 0);
            $connectionName = trim((string) ($connection['name'] ?? 'Proxmox'));
            $status = $this->status($connectionId, $connectionName);
            try {
                $resources = $this->clients->forConnection($connectionId)->get('/cluster/resources', ['type' => 'vm']);
                if (!is_array($resources)) {
                    throw new \RuntimeException('Proxmox returned an invalid template inventory response.');
                }
                foreach ($resources as $resource) {
                    if (!is_array($resource) || ($resource['type'] ?? null) !== 'qemu' || !$this->flag($resource['template'] ?? null)) continue;
                    $nodeName = trim((string) ($resource['node'] ?? ''));
                    $vmid = filter_var($resource['vmid'] ?? null, FILTER_VALIDATE_INT);
                    if ($nodeName === '' || $vmid === false) continue;
                    $templates[] = [
                        'connection_id' => $connectionId,
                        'connection_name' => $connectionName,
                        'node_name' => $nodeName,
                        'vmid' => (int) $vmid,
                        'name' => trim((string) ($resource['name'] ?? 'Template ' . $vmid)),
                        'status' => trim((string) ($resource['status'] ?? 'unknown')),
                        'cpu_count' => isset($resource['maxcpu']) ? (int) $resource['maxcpu'] : null,
                        'memory_bytes' => isset($resource['maxmem']) ? (int) $resource['maxmem'] : null,
                        'disk_bytes' => isset($resource['maxdisk']) ? (int) $resource['maxdisk'] : null,
                        'tags' => trim((string) ($resource['tags'] ?? '')),
                    ];
                    $status['resource_count']++;
                }
            } catch (\Throwable $exception) {
                $status = $this->failedStatus($status, $connection, $exception, 'Odczyt template Proxmox');
            }
            $statuses[] = $status;
        }

        usort($templates, static fn (array $left, array $right): int => [
            $left['connection_name'], $left['node_name'], $left['vmid'],
        ] <=> [
            $right['connection_name'], $right['node_name'], $right['vmid'],
        ]);
        return ['templates' => $templates, 'connections' => $statuses];
    }

    /** @return array{id:int,name:string,status:string,resource_count:int,error:null} */
    private function status(int $connectionId, string $connectionName): array
    {
        return ['id' => $connectionId, 'name' => $connectionName, 'status' => 'active', 'resource_count' => 0, 'error' => null];
    }

    /** @param array<string,mixed> $status @param array<string,mixed> $connection @return array<string,mixed> */
    private function failedStatus(array $status, array $connection, \Throwable $exception, string $operation): array
    {
        $public = ProxmoxFailureMessage::asHttpException(
            $exception,
            (string) ($connection['hostname'] ?? 'unknown'),
            (int) ($connection['port'] ?? 8006),
            $operation,
        );
        $status['status'] = 'error';
        $status['error'] = $public->getMessage();
        $status['details'] = $public->details;
        return $status;
    }

    /** @param array<string,mixed> $row */
    private function optionalFlag(array $row, string $key): ?bool
    {
        return array_key_exists($key, $row) ? $this->flag($row[$key]) : null;
    }

    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'on'], true);
    }
}
