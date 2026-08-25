<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;

final class ProxmoxVmManager
{
    public function __construct(private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /** @return array{status:array<string,mixed>,config:array<string,mixed>,snapshots:list<array<string,mixed>>,runtime_available:bool,runtime_note:?string} */
    public function details(int $connectionId, string $node, int $vmid): array
    {
        [$client, $path] = $this->target($connectionId, $node, $vmid);

        $config = $client->get($path . '/config');
        if (!is_array($config)) {
            throw new \RuntimeException('Proxmox returned an invalid virtual machine configuration response.');
        }

        $runtimeAvailable = true;
        $runtimeNote = null;
        try {
            $status = $client->get($path . '/status/current');
        } catch (ProxmoxException $exception) {
            if (!$this->isNotRunning($exception)) throw $exception;
            $runtimeAvailable = false;
            $runtimeNote = 'Maszyna jest zatrzymana. Proxmox nie udostępnia części danych runtime dla nieuruchomionej VM; konfiguracja pozostaje dostępna.';
            $status = [
                'name' => (string) ($config['name'] ?? ('VM ' . $vmid)),
                'status' => 'stopped',
                'vmid' => $vmid,
            ];
        }
        if (!is_array($status)) {
            throw new \RuntimeException('Proxmox returned an invalid virtual machine runtime status response.');
        }

        try {
            $snapshots = $client->get($path . '/snapshot');
        } catch (ProxmoxException $exception) {
            if (!$this->isNotRunning($exception)) throw $exception;
            $runtimeAvailable = false;
            $runtimeNote ??= 'Maszyna jest zatrzymana. Część danych zależnych od stanu runtime nie jest dostępna w Proxmox.';
            $snapshots = [];
        }
        if (!is_array($snapshots)) {
            throw new \RuntimeException('Proxmox returned an invalid virtual machine snapshot response.');
        }

        $safeStatus = $this->pick($status, ['name', 'status', 'qmpstatus', 'vmid', 'cpus', 'cpu', 'mem', 'maxmem', 'disk', 'maxdisk', 'uptime', 'lock', 'ha']);
        if (!$runtimeAvailable && !isset($safeStatus['status'])) $safeStatus['status'] = 'stopped';
        if (!$runtimeAvailable && !isset($safeStatus['vmid'])) $safeStatus['vmid'] = $vmid;

        $safeConfig = $this->pick($config, ['name', 'description', 'cores', 'sockets', 'memory', 'balloon', 'bios', 'machine', 'ostype', 'scsihw', 'boot', 'agent', 'onboot', 'protection', 'tags']);
        foreach ($config as $key => $value) {
            if (is_string($key) && preg_match('/^(?:scsi|sata|ide|virtio|net)\d+$/', $key) === 1 && (is_scalar($value) || $value === null)) {
                $safeConfig[$key] = $value;
            }
        }

        $safeSnapshots = [];
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot) || trim((string) ($snapshot['name'] ?? '')) === '' || ($snapshot['name'] ?? null) === 'current') continue;
            $safeSnapshots[] = $this->pick($snapshot, ['name', 'description', 'snaptime', 'parent', 'vmstate']);
        }
        usort($safeSnapshots, static fn (array $left, array $right): int => (int) ($right['snaptime'] ?? 0) <=> (int) ($left['snaptime'] ?? 0));

        return [
            'status' => $safeStatus,
            'config' => $safeConfig,
            'snapshots' => $safeSnapshots,
            'runtime_available' => $runtimeAvailable,
            'runtime_note' => $runtimeNote,
        ];
    }

    public function power(int $connectionId, string $node, int $vmid, string $action): string
    {
        if (!in_array($action, ['start', 'shutdown', 'stop', 'reboot', 'suspend', 'resume'], true)) {
            throw new HttpException(422, 'Unsupported Proxmox power action.');
        }
        [$client, $path] = $this->target($connectionId, $node, $vmid);
        return $this->requireUpid($client->post($path . '/status/' . $action));
    }

    public function snapshot(int $connectionId, string $node, int $vmid, string $name, string $description = ''): string
    {
        $this->snapshotName($name);
        [$client, $path] = $this->target($connectionId, $node, $vmid);
        return $this->requireUpid($client->post($path . '/snapshot', [
            'snapname' => $name,
            'description' => mb_substr($description, 0, 255),
        ]));
    }

    public function deleteSnapshot(int $connectionId, string $node, int $vmid, string $name): string
    {
        $this->snapshotName($name);
        [$client, $path] = $this->target($connectionId, $node, $vmid);
        return $this->requireUpid($client->delete($path . '/snapshot/' . rawurlencode($name)));
    }

    public function console(int $connectionId, string $node, int $vmid, string $proxyHost, int $proxyPort = 8006): string
    {
        [$client, $path] = $this->target($connectionId, $node, $vmid);
        $vmConfig = $client->get($path . '/config');
        if (!is_array($vmConfig)) {
            throw new \RuntimeException('Proxmox did not return the VM display configuration.');
        }

        $display = strtolower(trim((string) ($vmConfig['vga'] ?? 'std')));
        if (preg_match('/^qxl(?:\d+)?(?:,|$)/', $display) === 1) {
            try {
                $config = $client->post($path . '/spiceproxy', ['proxy' => $proxyHost]);
                if (is_array($config)) return $this->spiceConfig($config);
            } catch (ProxmoxException $exception) {
                if (!$this->isMissingSpicePort($exception)) throw $exception;
            }
        }

        $mode = preg_match('/^serial\d+(?:,|$)/', $display) === 1 ? 'xtermjs' : 'novnc';
        return $this->webConsoleHandoff(
            $proxyHost,
            $proxyPort,
            $node,
            $vmid,
            (string) ($vmConfig['name'] ?? ('VM ' . $vmid)),
            $mode,
        );
    }

    /** @param array<string,mixed> $config */
    private function spiceConfig(array $config): string
    {
        $allowed = ['type', 'host', 'proxy', 'password', 'tls-port', 'ca', 'host-subject', 'title', 'release-cursor', 'toggle-fullscreen', 'secure-attention', 'delete-this-file'];
        $lines = ['[virt-viewer]'];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $config) || (!is_scalar($config[$key]) && $config[$key] !== null)) continue;
            $lines[] = $key . '=' . str_replace(["\r", "\n"], ['', '\\n'], (string) $config[$key]);
        }
        if (!isset($config['delete-this-file'])) $lines[] = 'delete-this-file=1';
        return implode("\n", $lines) . "\n";
    }

    private function webConsoleHandoff(string $host, int $port, string $node, int $vmid, string $name, string $mode): string
    {
        $host = trim($host);
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $query = [
            'console' => 'kvm',
            $mode => 1,
            'vmid' => $vmid,
            'vmname' => $name,
            'node' => $node,
            'cmd' => '',
        ];
        if ($mode === 'novnc') $query['resize'] = 'off';

        $url = 'https://' . $host . ':' . max(1, min(65535, $port)) . '/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return 'CLOUDPORTAL_CONSOLE_URL=' . $url . "\n"
            . 'CLOUDPORTAL_CONSOLE_MODE=' . $mode . "\n";
    }

    /** @return array{ProxmoxClientInterface,string} */
    private function target(int $connectionId, string $node, int $vmid): array
    {
        if ($connectionId <= 0 || $vmid < 100 || $vmid > 999999999 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) {
            throw new HttpException(422, 'Invalid Proxmox virtual machine target.');
        }
        return [$this->clients->forConnection($connectionId), '/nodes/' . rawurlencode($node) . '/qemu/' . $vmid];
    }

    private function snapshotName(string $name): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$/', $name) !== 1) throw new HttpException(422, 'Invalid snapshot name.');
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

    private function isNotRunning(ProxmoxException $exception): bool
    {
        return $exception->httpStatus >= 400
            && preg_match('/\bVM\s+\d+\s+not\s+running\b/i', $exception->getMessage()) === 1;
    }

    private function isMissingSpicePort(ProxmoxException $exception): bool
    {
        return $exception->httpStatus >= 400
            && str_contains(mb_strtolower($exception->getMessage()), 'no spice port');
    }

    private function requireUpid(mixed $value): string
    {
        if (!is_string($value) || !str_starts_with($value, 'UPID:')) throw new \RuntimeException('Proxmox did not return a valid task UPID.');
        return $value;
    }
}
