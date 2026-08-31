<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;

final class ProxmoxConsoleSessionService
{
    public function __construct(
        private readonly ProxmoxClientProviderInterface $clients,
        private readonly ConsoleToken $tokens,
    ) {
    }

    /** @return array{mode:string,token:string,password:string,expires_in:int} */
    public function create(int $connectionId, string $node, int $vmid, int $userId): array
    {
        if ($connectionId <= 0 || $userId <= 0 || $vmid < 100 || $vmid > 999999999 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) {
            throw new HttpException(422, 'Invalid Proxmox console target.');
        }

        $client = $this->clients->forConnection($connectionId);
        $path = '/nodes/' . rawurlencode($node) . '/qemu/' . $vmid;
        $config = $client->get($path . '/config');
        if (!is_array($config)) throw new \RuntimeException('Proxmox did not return the VM display configuration.');
        $display = strtolower(trim((string) ($config['vga'] ?? 'std')));
        if (preg_match('/^serial\d+(?:,|$)/', $display) === 1) {
            throw new HttpException(409, 'This VM uses a serial-only display. Use the SPICE/external console fallback.');
        }

        $proxy = $client->post($path . '/vncproxy', ['websocket' => 1, 'generate-password' => 1]);
        if (!is_array($proxy)) throw new \RuntimeException('Proxmox did not return noVNC proxy credentials.');
        $ticket = trim((string) ($proxy['ticket'] ?? ''));
        $password = (string) ($proxy['password'] ?? '');
        $port = filter_var($proxy['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($ticket === '' || $password === '' || $port === false) {
            throw new \RuntimeException('Proxmox returned incomplete noVNC proxy credentials.');
        }

        $ttl = 20;
        $token = $this->tokens->issue([
            'connection_id' => $connectionId,
            'node' => $node,
            'vmid' => $vmid,
            'user_id' => $userId,
            'port' => (int) $port,
            'ticket' => $ticket,
        ], $ttl);

        return ['mode' => 'novnc', 'token' => $token, 'password' => $password, 'expires_in' => $ttl];
    }
}
