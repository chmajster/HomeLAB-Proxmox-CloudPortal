<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

/**
 * Reuses the VM firewall validator while translating the QEMU guest endpoint
 * to the matching Proxmox LXC firewall endpoint.
 */
final class ProxmoxLxcFirewallManager
{
    private readonly ProxmoxFirewallManager $firewall;

    public function __construct(ProxmoxClientProviderInterface $clients)
    {
        $this->firewall = new ProxmoxFirewallManager(new class($clients) implements ProxmoxClientProviderInterface {
            public function __construct(private readonly ProxmoxClientProviderInterface $clients)
            {
            }

            public function forConnection(int $connectionId): ProxmoxClientInterface
            {
                $client = $this->clients->forConnection($connectionId);
                return new class($client) implements ProxmoxClientInterface {
                    public function __construct(private readonly ProxmoxClientInterface $client)
                    {
                    }

                    public function get(string $path, array $query = []): mixed
                    {
                        return $this->client->get($this->path($path), $query);
                    }

                    public function post(string $path, array $data = []): mixed
                    {
                        return $this->client->post($this->path($path), $data);
                    }

                    public function put(string $path, array $data = []): mixed
                    {
                        return $this->client->put($this->path($path), $data);
                    }

                    public function delete(string $path, array $data = []): mixed
                    {
                        return $this->client->delete($this->path($path), $data);
                    }

                    public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array
                    {
                        return $this->client->waitForTask($node, $upid, $timeoutSeconds);
                    }

                    private function path(string $path): string
                    {
                        return preg_replace('#(/nodes/[^/]+)/qemu/(\d+)#', '$1/lxc/$2', $path, 1) ?? $path;
                    }
                };
            }
        });
    }

    public function state(int $connectionId, string $node, int $vmid): array
    {
        return $this->firewall->vmState($connectionId, $node, $vmid);
    }

    public function updateOptions(int $connectionId, string $node, int $vmid, array $input): array
    {
        return $this->firewall->updateVmOptions($connectionId, $node, $vmid, $input);
    }

    public function createRule(int $connectionId, string $node, int $vmid, array $input): array
    {
        return $this->firewall->createVmRule($connectionId, $node, $vmid, $input);
    }

    public function updateRule(int $connectionId, string $node, int $vmid, int $position, array $input): array
    {
        return $this->firewall->updateVmRule($connectionId, $node, $vmid, $position, $input);
    }

    public function deleteRule(int $connectionId, string $node, int $vmid, int $position): array
    {
        return $this->firewall->deleteVmRule($connectionId, $node, $vmid, $position);
    }
}
