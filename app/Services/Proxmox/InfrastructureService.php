<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use PDO;

final class InfrastructureService
{
    public function __construct(private readonly PDO $pdo, private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /** @return list<array<string,mixed>> */
    public function clusterOverview(): array
    {
        $connections = $this->pdo->query("SELECT id, name, cluster_name FROM proxmox_connections WHERE status = 'active' ORDER BY name")->fetchAll();
        $overview = [];
        foreach ($connections as $connection) {
            try {
                $client = $this->clients->forConnection((int) $connection['id']);
                $resources = $client->get('/cluster/resources');
                $tasks = $client->get('/cluster/tasks', ['limit' => 10]);
                $overview[] = [
                    'connection' => $connection,
                    'resources' => is_array($resources) ? $resources : [],
                    'tasks' => is_array($tasks) ? $tasks : [],
                    'error' => null,
                ];
            } catch (\Throwable $exception) {
                $overview[] = ['connection' => $connection, 'resources' => [], 'tasks' => [], 'error' => $exception->getMessage()];
            }
        }
        return $overview;
    }

    /** @return array<string,mixed> */
    public function sync(int $connectionId): array
    {
        $client = $this->clients->forConnection($connectionId);
        $cluster = $client->get('/cluster/status');
        $nodes = $client->get('/nodes');
        $storage = $client->get('/storage');
        $resources = $client->get('/cluster/resources');
        $tasks = $client->get('/cluster/tasks', ['limit' => 100]);
        $networks = [];
        if (is_array($nodes)) {
            foreach ($nodes as $node) {
                if (!is_array($node) || empty($node['node'])) {
                    continue;
                }
                $nodeNetworks = $client->get('/nodes/' . rawurlencode((string) $node['node']) . '/network');
                foreach (is_array($nodeNetworks) ? $nodeNetworks : [] as $network) {
                    if (is_array($network)) {
                        $networks[] = ['node' => $node['node'], ...$network];
                    }
                }
            }
        }

        if (is_array($nodes)) {
            $statement = $this->pdo->prepare(
                'INSERT INTO proxmox_nodes (connection_id, node_name, status, cpu_usage, memory_total, memory_used, last_seen_at)
                 VALUES (:connection, :name, :status, :cpu, :memory_total, :memory_used, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), cpu_usage = VALUES(cpu_usage),
                   memory_total = VALUES(memory_total), memory_used = VALUES(memory_used), last_seen_at = CURRENT_TIMESTAMP'
            );
            foreach ($nodes as $node) {
                if (!is_array($node) || !isset($node['node'])) {
                    continue;
                }
                $statement->execute([
                    'connection' => $connectionId,
                    'name' => $node['node'],
                    'status' => $node['status'] ?? 'unknown',
                    'cpu' => $node['cpu'] ?? null,
                    'memory_total' => $node['maxmem'] ?? null,
                    'memory_used' => $node['mem'] ?? null,
                ]);
            }
        }
        $this->pdo->prepare("UPDATE proxmox_connections SET status = 'active', last_checked_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = :id")
            ->execute(['id' => $connectionId]);
        return [
            'cluster' => is_array($cluster) ? $cluster : [],
            'nodes' => is_array($nodes) ? $nodes : [],
            'storage' => is_array($storage) ? $storage : [],
            'resources' => is_array($resources) ? $resources : [],
            'virtual_machines' => array_values(array_filter(is_array($resources) ? $resources : [], static fn (mixed $resource): bool => is_array($resource) && ($resource['type'] ?? null) === 'qemu' && empty($resource['template']))),
            'containers' => array_values(array_filter(is_array($resources) ? $resources : [], static fn (mixed $resource): bool => is_array($resource) && ($resource['type'] ?? null) === 'lxc')),
            'templates' => array_values(array_filter(is_array($resources) ? $resources : [], static fn (mixed $resource): bool => is_array($resource) && ($resource['type'] ?? null) === 'qemu' && !empty($resource['template']))),
            'networks' => $networks,
            'tasks' => is_array($tasks) ? $tasks : [],
        ];
    }

    /** @param list<array<string,mixed>> $vms @return list<array<string,mixed>> */
    public function mergeLiveVmState(array $vms): array
    {
        $byConnection = [];
        foreach ($vms as $index => $vm) {
            $byConnection[(int) $vm['connection_id']][] = $index;
        }
        foreach ($byConnection as $connectionId => $indexes) {
            try {
                $resources = $this->clients->forConnection($connectionId)->get('/cluster/resources', ['type' => 'vm']);
                $map = [];
                foreach (is_array($resources) ? $resources : [] as $resource) {
                    if (is_array($resource) && ($resource['type'] ?? null) === 'qemu') {
                        $map[(int) $resource['vmid']] = $resource;
                    }
                }
                foreach ($indexes as $index) {
                    $live = $map[(int) $vms[$index]['vmid']] ?? null;
                    if (is_array($live)) {
                        $vms[$index]['status'] = $live['status'] ?? $vms[$index]['status'];
                        $vms[$index]['cpu_usage'] = $live['cpu'] ?? null;
                        $vms[$index]['memory_used'] = $live['mem'] ?? null;
                        $vms[$index]['uptime'] = $live['uptime'] ?? null;
                    }
                    $vms[$index]['live_unavailable'] = !is_array($live);
                }
            } catch (\Throwable) {
                foreach ($indexes as $index) {
                    $vms[$index]['live_unavailable'] = true;
                }
            }
        }
        return $vms;
    }
}
