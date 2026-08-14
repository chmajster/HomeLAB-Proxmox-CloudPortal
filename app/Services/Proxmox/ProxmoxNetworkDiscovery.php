<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

final class ProxmoxNetworkDiscovery
{
    public function __construct(private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /**
     * @param list<array{id:mixed,name:mixed,hostname:mixed,port:mixed,status:mixed}> $connections
     * @return array{networks:list<array<string,mixed>>,connections:list<array<string,mixed>>}
     */
    public function discover(array $connections): array
    {
        $networks = [];
        $statuses = [];

        foreach ($connections as $connection) {
            $connectionId = (int) ($connection['id'] ?? 0);
            $connectionName = trim((string) ($connection['name'] ?? 'Proxmox'));
            $status = [
                'id' => $connectionId,
                'name' => $connectionName,
                'status' => 'active',
                'network_count' => 0,
                'error' => null,
            ];

            try {
                $client = $this->clients->forConnection($connectionId);
                $nodes = $client->get('/nodes');
                if (!is_array($nodes)) {
                    throw new \RuntimeException('Proxmox returned an invalid nodes response.');
                }

                foreach ($nodes as $node) {
                    if (!is_array($node) || trim((string) ($node['node'] ?? '')) === '') {
                        continue;
                    }
                    $nodeName = trim((string) $node['node']);
                    $interfaces = $client->get('/nodes/' . rawurlencode($nodeName) . '/network');
                    if (!is_array($interfaces)) {
                        throw new \RuntimeException('Proxmox returned an invalid network response.');
                    }
                    foreach ($interfaces as $interface) {
                        if (!is_array($interface) || trim((string) ($interface['iface'] ?? '')) === '') {
                            continue;
                        }
                        $networks[] = $this->normalize($connectionId, $connectionName, $nodeName, $node, $interface);
                        $status['network_count']++;
                    }
                }
            } catch (\Throwable $exception) {
                $public = ProxmoxFailureMessage::asHttpException(
                    $exception,
                    (string) ($connection['hostname'] ?? 'unknown'),
                    (int) ($connection['port'] ?? 8006),
                    'Odczyt sieci Proxmox',
                );
                $status['status'] = 'error';
                $status['error'] = $public->getMessage();
                $status['details'] = $public->details;
            }
            $statuses[] = $status;
        }

        usort($networks, static fn (array $left, array $right): int => [
            $left['connection_name'], $left['node_name'], $left['iface'],
        ] <=> [
            $right['connection_name'], $right['node_name'], $right['iface'],
        ]);

        return ['networks' => $networks, 'connections' => $statuses];
    }

    /** @param array<string,mixed> $node @param array<string,mixed> $interface @return array<string,mixed> */
    private function normalize(int $connectionId, string $connectionName, string $nodeName, array $node, array $interface): array
    {
        $type = trim((string) ($interface['type'] ?? 'unknown'));
        return [
            'connection_id' => $connectionId,
            'connection_name' => $connectionName,
            'node_name' => $nodeName,
            'node_status' => (string) ($node['status'] ?? 'unknown'),
            'iface' => trim((string) $interface['iface']),
            'type' => $type,
            'active' => $this->optionalFlag($interface, 'active'),
            'autostart' => $this->optionalFlag($interface, 'autostart'),
            'vlan_aware' => $this->optionalFlag($interface, 'bridge_vlan_aware'),
            'cidr' => trim((string) ($interface['cidr'] ?? '')),
            'address' => trim((string) ($interface['address'] ?? '')),
            'netmask' => trim((string) ($interface['netmask'] ?? '')),
            'gateway' => trim((string) ($interface['gateway'] ?? '')),
            'bridge_ports' => trim((string) ($interface['bridge_ports'] ?? $interface['ovs_ports'] ?? '')),
            'comments' => trim((string) ($interface['comments'] ?? $interface['comments6'] ?? '')),
            'configurable' => in_array($type, ['bridge', 'OVSBridge'], true),
        ];
    }

    /** @param array<string,mixed> $row */
    private function optionalFlag(array $row, string $key): ?bool
    {
        if (!array_key_exists($key, $row)) {
            return null;
        }
        return in_array($row[$key], [true, 1, '1', 'yes', 'on'], true);
    }
}
