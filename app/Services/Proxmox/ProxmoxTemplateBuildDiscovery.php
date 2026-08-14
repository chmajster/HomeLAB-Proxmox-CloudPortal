<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

final class ProxmoxTemplateBuildDiscovery
{
    public function __construct(private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /**
     * @param list<array<string,mixed>> $connections
     * @return array<string,list<array<string,mixed>>>
     */
    public function discover(array $connections): array
    {
        $result = ['iso_images' => [], 'upload_targets' => [], 'disk_targets' => [], 'bridges' => [], 'candidates' => [], 'connections' => []];
        foreach ($connections as $connection) {
            $connectionId = (int) ($connection['id'] ?? 0);
            $connectionName = trim((string) ($connection['name'] ?? 'Proxmox'));
            $status = ['id' => $connectionId, 'name' => $connectionName, 'status' => 'active', 'resource_count' => 0, 'error' => null];
            try {
                $client = $this->clients->forConnection($connectionId);
                $nodes = $client->get('/nodes');
                $storageDefinitions = $client->get('/storage');
                $resources = $client->get('/cluster/resources', ['type' => 'vm']);
                if (!is_array($nodes) || !is_array($storageDefinitions) || !is_array($resources)) {
                    throw new \RuntimeException('Proxmox returned an invalid template-builder inventory.');
                }
                $definitions = [];
                foreach ($storageDefinitions as $definition) {
                    if (!is_array($definition) || trim((string) ($definition['storage'] ?? '')) === '') continue;
                    $definitions[(string) $definition['storage']] = $definition;
                }

                foreach ($resources as $resource) {
                    if (!is_array($resource) || ($resource['type'] ?? null) !== 'qemu' || $this->flag($resource['template'] ?? null)) continue;
                    $node = trim((string) ($resource['node'] ?? ''));
                    $vmid = filter_var($resource['vmid'] ?? null, FILTER_VALIDATE_INT);
                    if ($node === '' || $vmid === false) continue;
                    $result['candidates'][] = [
                        'connection_id' => $connectionId,
                        'connection_name' => $connectionName,
                        'node' => $node,
                        'vmid' => (int) $vmid,
                        'name' => trim((string) ($resource['name'] ?? 'VM ' . $vmid)),
                        'status' => trim((string) ($resource['status'] ?? 'unknown')),
                    ];
                }

                foreach ($nodes as $nodeRow) {
                    if (!is_array($nodeRow) || trim((string) ($nodeRow['node'] ?? '')) === '' || ($nodeRow['status'] ?? 'online') !== 'online') continue;
                    $node = trim((string) $nodeRow['node']);
                    $nodeStorages = $client->get('/nodes/' . rawurlencode($node) . '/storage');
                    $networks = $client->get('/nodes/' . rawurlencode($node) . '/network');
                    if (!is_array($nodeStorages) || !is_array($networks)) {
                        throw new \RuntimeException('Proxmox returned an invalid node inventory.');
                    }
                    foreach ($networks as $network) {
                        if (!is_array($network) || !in_array($network['type'] ?? null, ['bridge', 'OVSBridge'], true)) continue;
                        $bridge = trim((string) ($network['iface'] ?? ''));
                        if ($bridge === '' || !$this->available($network)) continue;
                        $result['bridges'][] = [
                            'connection_id' => $connectionId,
                            'connection_name' => $connectionName,
                            'node' => $node,
                            'bridge' => $bridge,
                            'type' => (string) $network['type'],
                        ];
                    }
                    foreach ($nodeStorages as $nodeStorage) {
                        if (!is_array($nodeStorage) || !$this->available($nodeStorage)) continue;
                        $storage = trim((string) ($nodeStorage['storage'] ?? ''));
                        if ($storage === '') continue;
                        $definition = $definitions[$storage] ?? [];
                        if ($this->flag($definition['disable'] ?? null)) continue;
                        $content = $this->contentTypes((string) ($nodeStorage['content'] ?? $definition['content'] ?? ''));
                        $target = [
                            'connection_id' => $connectionId,
                            'connection_name' => $connectionName,
                            'node' => $node,
                            'storage' => $storage,
                            'type' => trim((string) ($definition['type'] ?? $nodeStorage['type'] ?? 'unknown')),
                            'available_bytes' => isset($nodeStorage['avail']) ? (int) $nodeStorage['avail'] : null,
                        ];
                        if (in_array('images', $content, true)) $result['disk_targets'][] = $target;
                        if (!in_array('iso', $content, true)) continue;
                        $result['upload_targets'][] = $target;
                        $volumes = $client->get('/nodes/' . rawurlencode($node) . '/storage/' . rawurlencode($storage) . '/content', ['content' => 'iso']);
                        if (!is_array($volumes)) throw new \RuntimeException('Proxmox returned an invalid ISO inventory.');
                        foreach ($volumes as $volume) {
                            if (!is_array($volume)) continue;
                            $volid = trim((string) ($volume['volid'] ?? ''));
                            if ($volid === '' || !str_ends_with(strtolower($volid), '.iso')) continue;
                            $result['iso_images'][] = [
                                ...$target,
                                'volid' => $volid,
                                'filename' => basename(str_replace('\\', '/', $volid)),
                                'size_bytes' => isset($volume['size']) ? (int) $volume['size'] : null,
                                'created_at' => isset($volume['ctime']) ? (int) $volume['ctime'] : null,
                            ];
                        }
                    }
                }
                $status['resource_count'] = count(array_filter($result['iso_images'], static fn (array $item): bool => $item['connection_id'] === $connectionId));
            } catch (\Throwable $exception) {
                error_log('Template builder discovery failed: ' . $exception->getMessage());
                $status['status'] = 'error';
                $status['error'] = ProxmoxFailureMessage::asHttpException(
                    $exception,
                    (string) ($connection['hostname'] ?? ''),
                    (int) ($connection['port'] ?? 8006),
                    'Odczyt zasobów kreatora template',
                )->getMessage();
            }
            $result['connections'][] = $status;
        }

        foreach (['iso_images', 'upload_targets', 'disk_targets', 'bridges', 'candidates'] as $key) {
            usort($result[$key], static fn (array $left, array $right): int => [
                $left['connection_name'] ?? '', $left['node'] ?? '', $left['storage'] ?? '', $left['filename'] ?? '', $left['vmid'] ?? 0,
            ] <=> [
                $right['connection_name'] ?? '', $right['node'] ?? '', $right['storage'] ?? '', $right['filename'] ?? '', $right['vmid'] ?? 0,
            ]);
        }
        return $result;
    }

    /** @return list<string> */
    private function contentTypes(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /** @param array<string,mixed> $row */
    private function available(array $row): bool
    {
        return !$this->falseFlag($row['active'] ?? 1) && !$this->falseFlag($row['enabled'] ?? 1);
    }

    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'yes', 'on'], true);
    }

    private function falseFlag(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'no', 'off'], true);
    }
}
