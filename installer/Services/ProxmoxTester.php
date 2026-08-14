<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

use CloudPortal\Services\Proxmox\ProxmoxClient;
use CloudPortal\Services\Proxmox\ProxmoxException;
use CloudPortal\Services\Proxmox\ProxmoxFailureMessage;

final class ProxmoxTester
{
    /** @var \Closure(string,int,string,string,string,bool):object */
    private readonly \Closure $clientFactory;

    /** @param null|\Closure(string,int,string,string,string,bool):object $clientFactory */
    public function __construct(?\Closure $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? static fn (string $hostname, int $port, string $realm, string $tokenId, string $tokenSecret, bool $verifySsl): ProxmoxClient => new ProxmoxClient(
            $hostname, $port, $realm, $tokenId, $tokenSecret, $verifySsl, 5, 20,
        );
    }

    /** @param array<string,mixed> $config @return array{cluster:string,nodes:int,version:string,storages:int} */
    public function test(array $config): array
    {
        if (($config['skipped'] ?? false) === true) return ['cluster' => 'Skipped', 'nodes' => 0, 'version' => 'Not configured', 'storages' => 0];
        try {
            $client = ($this->clientFactory)(
                (string) $config['hostname'], (int) $config['port'], (string) $config['realm'],
                (string) $config['token_id'], (string) $config['token_secret'], (bool) $config['verify_ssl'],
            );
            $clusterStatus = $client->get('/cluster/status');
            $nodes = $client->get('/nodes');
            $version = $client->get('/version');
            $storages = $client->get('/storage');
            if (!is_array($clusterStatus) || !is_array($nodes) || !is_array($version) || !is_array($storages)) throw new \RuntimeException('Required API resources returned an unexpected response.');
            $clusterName = (string) $config['hostname'];
            foreach ($clusterStatus as $entry) {
                if (is_array($entry) && ($entry['type'] ?? null) === 'cluster' && !empty($entry['name'])) {
                    $clusterName = (string) $entry['name'];
                    break;
                }
            }
            return ['cluster' => mb_substr($clusterName, 0, 100), 'nodes' => count($nodes), 'version' => mb_substr((string) ($version['version'] ?? 'Unknown'), 0, 40), 'storages' => count($storages)];
        } catch (ProxmoxException $exception) {
            throw new \RuntimeException(ProxmoxFailureMessage::describe(
                $exception,
                (string) $config['hostname'],
                (int) $config['port'],
                [(string) $config['token_secret']],
            ), 0, $exception);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Test połączenia Proxmox nie powiódł się przed otrzymaniem statusu HTTP. Szczegóły techniczne zapisano w logu instalatora.', 0, $exception);
        }
    }
}
