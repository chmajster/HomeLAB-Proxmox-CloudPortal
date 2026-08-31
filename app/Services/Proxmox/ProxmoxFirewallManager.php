<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;

final class ProxmoxFirewallManager
{
    public function __construct(private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /** @return array{options:array<string,mixed>,rules:list<array<string,mixed>>} */
    public function vmState(int $connectionId, string $node, int $vmid): array
    {
        [$client, $path] = $this->vmTarget($connectionId, $node, $vmid);
        $options = $client->get($path . '/firewall/options');
        $rules = $client->get($path . '/firewall/rules');

        return [
            'options' => $this->safeOptions(is_array($options) ? $options : []),
            'rules' => $this->safeRules(is_array($rules) ? $rules : []),
        ];
    }

    /** @param array<string,mixed> $input @return array{options:array<string,mixed>,rules:list<array<string,mixed>>} */
    public function updateVmOptions(int $connectionId, string $node, int $vmid, array $input): array
    {
        [$client, $path] = $this->vmTarget($connectionId, $node, $vmid);
        $payload = [];
        foreach (['enable', 'dhcp', 'ipfilter', 'macfilter', 'ndp', 'radv'] as $key) {
            if (array_key_exists($key, $input)) $payload[$key] = $this->boolean($input[$key]) ? 1 : 0;
        }
        foreach (['policy_in', 'policy_out'] as $key) {
            if (!array_key_exists($key, $input)) continue;
            $value = strtoupper(trim((string) $input[$key]));
            if (!in_array($value, ['ACCEPT', 'DROP', 'REJECT'], true)) throw new HttpException(422, 'Invalid firewall policy.');
            $payload[$key] = $value;
        }
        foreach (['log_level_in', 'log_level_out'] as $key) {
            if (!array_key_exists($key, $input)) continue;
            $payload[$key] = $this->logLevel($input[$key]);
        }
        if ($payload === []) throw new HttpException(422, 'No supported firewall options were provided.');

        $client->put($path . '/firewall/options', $payload);
        return $this->vmState($connectionId, $node, $vmid);
    }

    /** @param array<string,mixed> $input @return array{options:array<string,mixed>,rules:list<array<string,mixed>>} */
    public function createVmRule(int $connectionId, string $node, int $vmid, array $input): array
    {
        [$client, $path] = $this->vmTarget($connectionId, $node, $vmid);
        $client->post($path . '/firewall/rules', $this->rulePayload($input, true));
        return $this->vmState($connectionId, $node, $vmid);
    }

    /** @param array<string,mixed> $input @return array{options:array<string,mixed>,rules:list<array<string,mixed>>} */
    public function updateVmRule(int $connectionId, string $node, int $vmid, int $position, array $input): array
    {
        [$client, $path] = $this->vmTarget($connectionId, $node, $vmid);
        $this->position($position);
        $client->put($path . '/firewall/rules/' . $position, $this->rulePayload($input, false));
        return $this->vmState($connectionId, $node, $vmid);
    }

    /** @return array{options:array<string,mixed>,rules:list<array<string,mixed>>} */
    public function deleteVmRule(int $connectionId, string $node, int $vmid, int $position): array
    {
        [$client, $path] = $this->vmTarget($connectionId, $node, $vmid);
        $this->position($position);
        $client->delete($path . '/firewall/rules/' . $position);
        return $this->vmState($connectionId, $node, $vmid);
    }

    /** @return array{aliases:list<array<string,mixed>>,ipsets:list<array<string,mixed>>,groups:list<array<string,mixed>>} */
    public function clusterState(int $connectionId): array
    {
        $client = $this->clusterClient($connectionId);
        $aliases = $client->get('/cluster/firewall/aliases');
        $ipsets = $client->get('/cluster/firewall/ipset');
        $groups = $client->get('/cluster/firewall/groups');

        $safeIpSets = [];
        foreach (is_array($ipsets) ? $ipsets : [] as $ipset) {
            if (!is_array($ipset)) continue;
            $name = trim((string) ($ipset['name'] ?? ''));
            if ($name === '') continue;
            $entries = $client->get('/cluster/firewall/ipset/' . rawurlencode($name));
            $safeIpSets[] = [
                ...$this->pick($ipset, ['name', 'comment', 'digest']),
                'entries' => $this->safeIpSetEntries(is_array($entries) ? $entries : []),
            ];
        }

        $safeGroups = [];
        foreach (is_array($groups) ? $groups : [] as $group) {
            if (!is_array($group)) continue;
            $name = trim((string) ($group['group'] ?? $group['name'] ?? ''));
            if ($name === '') continue;
            $rules = $client->get('/cluster/firewall/groups/' . rawurlencode($name));
            $safeGroups[] = [
                'group' => $name,
                'comment' => isset($group['comment']) ? (string) $group['comment'] : '',
                'digest' => isset($group['digest']) ? (string) $group['digest'] : null,
                'rules' => $this->safeRules(is_array($rules) ? $rules : []),
            ];
        }

        $safeAliases = [];
        foreach (is_array($aliases) ? $aliases : [] as $alias) {
            if (is_array($alias)) $safeAliases[] = $this->pick($alias, ['name', 'cidr', 'comment', 'digest']);
        }

        return ['aliases' => $safeAliases, 'ipsets' => $safeIpSets, 'groups' => $safeGroups];
    }

    /** @param array<string,mixed> $input */
    public function createAlias(int $connectionId, array $input): void
    {
        $name = $this->resourceName($input['name'] ?? null);
        $this->clusterClient($connectionId)->post('/cluster/firewall/aliases', [
            'name' => $name,
            'cidr' => $this->cidr($input['cidr'] ?? null),
            'comment' => $this->comment($input['comment'] ?? ''),
        ]);
    }

    /** @param array<string,mixed> $input */
    public function updateAlias(int $connectionId, string $name, array $input): void
    {
        $name = $this->resourceName($name);
        $payload = [];
        if (array_key_exists('cidr', $input)) $payload['cidr'] = $this->cidr($input['cidr']);
        if (array_key_exists('comment', $input)) $payload['comment'] = $this->comment($input['comment']);
        if ($payload === []) throw new HttpException(422, 'No alias changes were provided.');
        $this->clusterClient($connectionId)->put('/cluster/firewall/aliases/' . rawurlencode($name), $payload);
    }

    public function deleteAlias(int $connectionId, string $name): void
    {
        $name = $this->resourceName($name);
        $this->clusterClient($connectionId)->delete('/cluster/firewall/aliases/' . rawurlencode($name));
    }

    /** @param array<string,mixed> $input */
    public function createIpSet(int $connectionId, array $input): void
    {
        $this->clusterClient($connectionId)->post('/cluster/firewall/ipset', [
            'name' => $this->resourceName($input['name'] ?? null),
            'comment' => $this->comment($input['comment'] ?? ''),
        ]);
    }

    public function deleteIpSet(int $connectionId, string $name): void
    {
        $name = $this->resourceName($name);
        $this->clusterClient($connectionId)->delete('/cluster/firewall/ipset/' . rawurlencode($name));
    }

    /** @param array<string,mixed> $input */
    public function createIpSetEntry(int $connectionId, string $name, array $input): void
    {
        $name = $this->resourceName($name);
        $this->clusterClient($connectionId)->post('/cluster/firewall/ipset/' . rawurlencode($name), [
            'cidr' => $this->cidr($input['cidr'] ?? null),
            'nomatch' => $this->boolean($input['nomatch'] ?? false) ? 1 : 0,
            'comment' => $this->comment($input['comment'] ?? ''),
        ]);
    }

    /** @param array<string,mixed> $input */
    public function updateIpSetEntry(int $connectionId, string $name, array $input): void
    {
        $name = $this->resourceName($name);
        $oldCidr = $this->cidr($input['old_cidr'] ?? $input['cidr'] ?? null);
        $payload = [
            'cidr' => $this->cidr($input['cidr'] ?? $oldCidr),
            'nomatch' => $this->boolean($input['nomatch'] ?? false) ? 1 : 0,
            'comment' => $this->comment($input['comment'] ?? ''),
        ];
        $this->clusterClient($connectionId)->put('/cluster/firewall/ipset/' . rawurlencode($name) . '/' . rawurlencode($oldCidr), $payload);
    }

    /** @param array<string,mixed> $input */
    public function deleteIpSetEntry(int $connectionId, string $name, array $input): void
    {
        $name = $this->resourceName($name);
        $cidr = $this->cidr($input['cidr'] ?? null);
        $this->clusterClient($connectionId)->delete('/cluster/firewall/ipset/' . rawurlencode($name) . '/' . rawurlencode($cidr));
    }

    /** @param array<string,mixed> $input */
    public function createGroup(int $connectionId, array $input): void
    {
        $this->clusterClient($connectionId)->post('/cluster/firewall/groups', [
            'group' => $this->resourceName($input['group'] ?? $input['name'] ?? null),
            'comment' => $this->comment($input['comment'] ?? ''),
        ]);
    }

    public function deleteGroup(int $connectionId, string $name): void
    {
        $name = $this->resourceName($name);
        $this->clusterClient($connectionId)->delete('/cluster/firewall/groups/' . rawurlencode($name));
    }

    /** @param array<string,mixed> $input */
    public function createGroupRule(int $connectionId, string $name, array $input): void
    {
        $name = $this->resourceName($name);
        $payload = $this->rulePayload($input, true);
        if (($payload['type'] ?? null) === 'group') throw new HttpException(422, 'Nested security groups are not supported.');
        $this->clusterClient($connectionId)->post('/cluster/firewall/groups/' . rawurlencode($name), $payload);
    }

    /** @param array<string,mixed> $input */
    public function updateGroupRule(int $connectionId, string $name, int $position, array $input): void
    {
        $name = $this->resourceName($name);
        $this->position($position);
        $payload = $this->rulePayload($input, false);
        if (($payload['type'] ?? null) === 'group') throw new HttpException(422, 'Nested security groups are not supported.');
        $this->clusterClient($connectionId)->put('/cluster/firewall/groups/' . rawurlencode($name) . '/' . $position, $payload);
    }

    public function deleteGroupRule(int $connectionId, string $name, int $position): void
    {
        $name = $this->resourceName($name);
        $this->position($position);
        $this->clusterClient($connectionId)->delete('/cluster/firewall/groups/' . rawurlencode($name) . '/' . $position);
    }

    /** @return array{ProxmoxClientInterface,string} */
    private function vmTarget(int $connectionId, string $node, int $vmid): array
    {
        if ($connectionId <= 0 || $vmid < 100 || $vmid > 999999999 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) {
            throw new HttpException(422, 'Invalid Proxmox virtual machine target.');
        }
        return [$this->clients->forConnection($connectionId), '/nodes/' . rawurlencode($node) . '/qemu/' . $vmid];
    }

    private function clusterClient(int $connectionId): ProxmoxClientInterface
    {
        if ($connectionId <= 0) throw new HttpException(422, 'Invalid Proxmox connection.');
        return $this->clients->forConnection($connectionId);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function rulePayload(array $input, bool $creating): array
    {
        $payload = [];
        if ($creating || array_key_exists('type', $input)) {
            $type = strtolower(trim((string) ($input['type'] ?? '')));
            if (!in_array($type, ['in', 'out', 'group'], true)) throw new HttpException(422, 'Invalid firewall rule type.');
            $payload['type'] = $type;
        }

        $type = (string) ($payload['type'] ?? strtolower(trim((string) ($input['type'] ?? ''))));
        if ($creating || array_key_exists('action', $input)) {
            $action = trim((string) ($input['action'] ?? ''));
            if ($type === 'group') {
                $payload['action'] = $this->resourceName($action);
            } else {
                $action = strtoupper($action);
                if (!in_array($action, ['ACCEPT', 'DROP', 'REJECT'], true)) throw new HttpException(422, 'Invalid firewall rule action.');
                $payload['action'] = $action;
            }
        }

        if (array_key_exists('enable', $input) || $creating) $payload['enable'] = $this->boolean($input['enable'] ?? true) ? 1 : 0;
        foreach (['source', 'dest', 'iface', 'macro'] as $key) {
            if (!array_key_exists($key, $input)) continue;
            $payload[$key] = $this->text($input[$key], $key === 'macro' ? 64 : 255);
        }
        if (array_key_exists('proto', $input)) {
            $proto = strtolower($this->text($input['proto'], 16));
            if ($proto !== '' && !in_array($proto, ['tcp', 'udp', 'icmp', 'icmpv6', 'esp', 'gre'], true)) throw new HttpException(422, 'Invalid firewall protocol.');
            $payload['proto'] = $proto;
        }
        foreach (['sport', 'dport'] as $key) {
            if (!array_key_exists($key, $input)) continue;
            $value = $this->text($input[$key], 128);
            if ($value !== '' && preg_match('/^[A-Za-z0-9,:._-]+$/', $value) !== 1) throw new HttpException(422, 'Invalid firewall port expression.');
            $payload[$key] = $value;
        }
        if (array_key_exists('log', $input)) $payload['log'] = $this->logLevel($input['log']);
        if (array_key_exists('comment', $input)) $payload['comment'] = $this->comment($input['comment']);
        if (array_key_exists('pos', $input)) {
            $position = (int) $input['pos'];
            $this->position($position);
            $payload['pos'] = $position;
        }
        if ($payload === []) throw new HttpException(422, 'No firewall rule changes were provided.');
        return $payload;
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    private function safeOptions(array $options): array
    {
        return $this->pick($options, ['enable', 'policy_in', 'policy_out', 'log_level_in', 'log_level_out', 'dhcp', 'ipfilter', 'macfilter', 'ndp', 'radv', 'digest']);
    }

    /** @param array<mixed> $rules @return list<array<string,mixed>> */
    private function safeRules(array $rules): array
    {
        $result = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            $result[] = $this->pick($rule, ['pos', 'type', 'action', 'enable', 'source', 'dest', 'iface', 'macro', 'proto', 'sport', 'dport', 'log', 'comment', 'digest']);
        }
        return $result;
    }

    /** @param array<mixed> $entries @return list<array<string,mixed>> */
    private function safeIpSetEntries(array $entries): array
    {
        $result = [];
        foreach ($entries as $entry) {
            if (is_array($entry)) $result[] = $this->pick($entry, ['cidr', 'nomatch', 'comment', 'digest']);
        }
        return $result;
    }

    /** @param array<string,mixed> $source @param list<string> $keys @return array<string,mixed> */
    private function pick(array $source, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $source) && (is_scalar($source[$key]) || $source[$key] === null)) $result[$key] = $source[$key];
        }
        return $result;
    }

    private function resourceName(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $value) !== 1) throw new HttpException(422, 'Invalid firewall object name.');
        return $value;
    }

    private function cidr(mixed $value): string
    {
        $value = trim((string) $value);
        $parts = explode('/', $value, 2);
        $ip = $parts[0] ?? '';
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) throw new HttpException(422, 'Invalid IP/CIDR value.');
        if (count($parts) === 1) return $value;
        if ($parts[1] === '' || preg_match('/^\d{1,3}$/', $parts[1]) !== 1) throw new HttpException(422, 'Invalid CIDR prefix.');
        $prefix = (int) $parts[1];
        $max = str_contains($ip, ':') ? 128 : 32;
        if ($prefix < 0 || $prefix > $max) throw new HttpException(422, 'Invalid CIDR prefix.');
        return $value;
    }

    private function comment(mixed $value): string
    {
        return $this->text($value, 255);
    }

    private function text(mixed $value, int $max): string
    {
        $value = trim((string) $value);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) throw new HttpException(422, 'Invalid firewall text value.');
        return mb_substr($value, 0, $max);
    }

    private function logLevel(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, ['nolog', 'emerg', 'alert', 'crit', 'err', 'warning', 'notice', 'info', 'debug'], true)) {
            throw new HttpException(422, 'Invalid firewall log level.');
        }
        return $value;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_int($value)) return $value !== 0;
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsed === null) throw new HttpException(422, 'Invalid boolean value.');
        return $parsed;
    }

    private function position(int $position): void
    {
        if ($position < 0 || $position > 99999) throw new HttpException(422, 'Invalid firewall rule position.');
    }
}
