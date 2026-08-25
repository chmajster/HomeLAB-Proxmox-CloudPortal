<?php

declare(strict_types=1);

namespace CloudPortal\Services\Provisioning;

use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use InvalidArgumentException;

final class CloudInitRuntime
{
    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    public function config(ProxmoxClientInterface $client, string $node, array $payload, array $base): array
    {
        $base['ciuser'] = (string) ($payload['cloud_init_user'] ?? 'clouduser');
        $base['agent'] = ($payload['qemu_guest_agent'] ?? true) ? 'enabled=1' : 'enabled=0';

        $keys = trim((string) ($payload['ssh_public_key'] ?? ''));
        if ($keys !== '') $base['sshkeys'] = $keys;

        $dns = trim((string) ($payload['dns_servers'] ?? ''));
        if ($dns !== '') $base['nameserver'] = str_replace([',', ';'], ' ', $dns);

        $searchDomain = trim((string) ($payload['search_domain'] ?? ''));
        if ($searchDomain !== '') $base['searchdomain'] = $searchDomain;

        $vendor = trim((string) ($payload['cicustom_vendor'] ?? ''));
        if ($vendor !== '') {
            $this->assertSnippetExists($client, $node, $vendor);
            $base['cicustom'] = 'vendor=' . $vendor;
        }
        return $base;
    }

    private function assertSnippetExists(ProxmoxClientInterface $client, string $node, string $volume): void
    {
        if (preg_match('#^(?<storage>[A-Za-z0-9][A-Za-z0-9._-]{0,99}):snippets/(?<file>[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.(?:ya?ml|cfg))$#i', $volume, $match) !== 1) {
            throw new InvalidArgumentException('Invalid Proxmox Cloud-Init snippet reference: ' . $volume . '.');
        }
        $items = $client->get('/nodes/' . rawurlencode($node) . '/storage/' . rawurlencode((string) $match['storage']) . '/content', ['content' => 'snippets']);
        if (!is_array($items)) {
            throw new InvalidArgumentException('Proxmox did not return a valid snippets inventory for storage ' . $match['storage'] . ' on node ' . $node . '.');
        }
        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['volid'] ?? '') === $volume) return;
        }
        throw new InvalidArgumentException(
            'Cloud-Init profile requires snippet ' . $volume . ' on node ' . $node . ', but the file is not present. '
            . 'Download Vendor YAML from the profile page, place it on a Proxmox storage with snippets content, then retry.'
        );
    }
}
