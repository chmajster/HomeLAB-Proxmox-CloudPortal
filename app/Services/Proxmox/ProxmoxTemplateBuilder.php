<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;

final class ProxmoxTemplateBuilder
{
    public function __construct(private readonly ProxmoxClientProviderInterface $clients)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createInstallationVm(int $connectionId, string $node, array $input): array
    {
        $node = $this->resourceId($node, 'węzła');
        $name = trim((string) ($input['name'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,62}$/', $name) !== 1) {
            throw new HttpException(422, 'Nazwa VM może zawierać litery, cyfry i myślniki (maksymalnie 63 znaki).');
        }
        $cores = $this->integer($input['cores'] ?? null, 1, 128, 'Liczba vCPU');
        $memory = $this->integer($input['memory_mb'] ?? null, 512, 1048576, 'Pamięć RAM');
        $disk = $this->integer($input['disk_gb'] ?? null, 4, 65536, 'Rozmiar dysku');
        $diskStorage = $this->resourceId((string) ($input['disk_storage'] ?? ''), 'storage dyskowego');
        $isoStorage = $this->resourceId((string) ($input['iso_storage'] ?? ''), 'storage ISO');
        $bridge = $this->resourceId((string) ($input['bridge'] ?? ''), 'bridge');
        $isoVolume = trim((string) ($input['iso_volume'] ?? ''));
        if (strlen($isoVolume) > 255 || preg_match('/[\r\n]/', $isoVolume) || str_contains($isoVolume, '..') || !str_starts_with($isoVolume, $isoStorage . ':iso/') || !str_ends_with(strtolower($isoVolume), '.iso')) {
            throw new HttpException(422, 'Wybrany wolumen ISO jest nieprawidłowy.');
        }
        $ostype = (string) ($input['ostype'] ?? 'l26');
        if (!in_array($ostype, ['l26', 'win10', 'win11', 'other'], true)) {
            throw new HttpException(422, 'Nieobsługiwany typ systemu operacyjnego.');
        }
        $vlan = $input['vlan_id'] ?? null;
        $vlan = $vlan === null || $vlan === '' ? null : $this->integer($vlan, 1, 4094, 'VLAN');
        $description = mb_substr(trim((string) ($input['description'] ?? '')), 0, 500);

        $client = $this->clients->forConnection($connectionId);
        $this->assertStorage($client, $node, $isoStorage, 'iso');
        $this->assertStorage($client, $node, $diskStorage, 'images');
        $this->assertIsoExists($client, $node, $isoStorage, $isoVolume);
        $this->assertBridge($client, $node, $bridge);
        $vmidInput = $input['vmid'] ?? null;
        if ($vmidInput === null || $vmidInput === '') {
            $next = $client->get('/cluster/nextid');
            $vmid = $this->integer($next, 100, 999999999, 'VMID zwrócony przez Proxmox');
        } else {
            $vmid = $this->integer($vmidInput, 100, 999999999, 'VMID');
        }
        $net = 'virtio,bridge=' . $bridge . ($vlan === null ? '' : ',tag=' . $vlan);
        $upid = $client->post('/nodes/' . rawurlencode($node) . '/qemu', [
            'vmid' => $vmid,
            'name' => $name,
            'cores' => $cores,
            'memory' => $memory,
            'scsihw' => 'virtio-scsi-single',
            'scsi0' => $diskStorage . ':' . $disk . ',discard=on,iothread=1',
            'ide0' => $diskStorage . ':cloudinit',
            'ide2' => $isoVolume . ',media=cdrom',
            'boot' => 'order=ide2;scsi0',
            'net0' => $net,
            'ostype' => $ostype,
            'agent' => 1,
            'onboot' => 0,
            'description' => $description !== '' ? $description : 'VM instalacyjna utworzona przez Algen Cloud Portal.',
        ]);
        return ['connection_id' => $connectionId, 'node' => $node, 'vmid' => $vmid, 'upid' => $this->upid($upid), 'status' => 'queued'];
    }

    /** @return array<string,mixed> */
    public function convert(int $connectionId, string $node, int $vmid): array
    {
        $node = $this->resourceId($node, 'węzła');
        if ($vmid < 100 || $vmid > 999999999) throw new HttpException(422, 'VMID jest poza dozwolonym zakresem.');
        $client = $this->clients->forConnection($connectionId);
        $status = $client->get('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/status/current');
        $config = $client->get('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/config');
        if (!is_array($status) || !is_array($config)) throw new \RuntimeException('Proxmox returned an invalid VM response.');
        if (($status['status'] ?? null) !== 'stopped') throw new HttpException(409, 'Przed konwersją VM musi być wyłączona.');
        if ((int) ($config['template'] ?? 0) === 1) throw new HttpException(409, 'Ta maszyna jest już template Proxmox.');
        if (!is_string($config['scsi0'] ?? null) || trim((string) $config['scsi0']) === '') {
            throw new HttpException(422, 'VM musi mieć główny dysk scsi0.');
        }
        $hasCloudInit = false;
        $installationMedia = [];
        foreach ($config as $key => $value) {
            if (!is_string($value)) continue;
            if (preg_match('/(?:^|[-_:])cloudinit(?:,|$)/i', $value) === 1) $hasCloudInit = true;
            if (preg_match('/^(?:ide|sata|scsi|virtio)\d+$/', (string) $key) === 1 && str_contains(strtolower($value), 'media=cdrom') && !str_contains(strtolower($value), 'cloudinit')) {
                $installationMedia[] = (string) $key;
            }
        }
        if (!$hasCloudInit) throw new HttpException(422, 'VM musi mieć dysk cloud-init przed konwersją do template.');
        $update = ['boot' => 'order=scsi0'];
        if ($installationMedia !== []) $update['delete'] = implode(',', $installationMedia);
        $client->put('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/config', $update);
        $upid = $client->post('/nodes/' . rawurlencode($node) . '/qemu/' . $vmid . '/template');
        return ['connection_id' => $connectionId, 'node' => $node, 'vmid' => $vmid, 'upid' => $this->upid($upid), 'status' => 'queued'];
    }

    private function assertStorage(ProxmoxClientInterface $client, string $node, string $storage, string $requiredContent): void
    {
        $config = $client->get('/storage/' . rawurlencode($storage));
        $status = $client->get('/nodes/' . rawurlencode($node) . '/storage/' . rawurlencode($storage) . '/status');
        $types = is_array($config) ? array_map('trim', explode(',', (string) ($config['content'] ?? ''))) : [];
        if (!is_array($config) || !is_array($status) || !in_array($requiredContent, $types, true) || $this->falseFlag($status['active'] ?? 1) || $this->falseFlag($status['enabled'] ?? 1)) {
            throw new HttpException(422, 'Storage ' . $storage . ' nie jest aktywne na węźle ' . $node . ' albo nie obsługuje typu ' . $requiredContent . '.');
        }
    }

    private function assertIsoExists(ProxmoxClientInterface $client, string $node, string $storage, string $volume): void
    {
        $volumes = $client->get('/nodes/' . rawurlencode($node) . '/storage/' . rawurlencode($storage) . '/content', ['content' => 'iso']);
        if (!is_array($volumes)) throw new \RuntimeException('Proxmox returned an invalid ISO inventory.');
        foreach ($volumes as $candidate) {
            if (is_array($candidate) && hash_equals((string) ($candidate['volid'] ?? ''), $volume)) return;
        }
        throw new HttpException(422, 'Wybrany obraz ISO nie istnieje już w Proxmox. Odśwież listę.');
    }

    private function assertBridge(ProxmoxClientInterface $client, string $node, string $bridge): void
    {
        $networks = $client->get('/nodes/' . rawurlencode($node) . '/network');
        if (!is_array($networks)) throw new \RuntimeException('Proxmox returned an invalid network inventory.');
        foreach ($networks as $network) {
            if (is_array($network) && ($network['iface'] ?? null) === $bridge && in_array($network['type'] ?? null, ['bridge', 'OVSBridge'], true) && !$this->falseFlag($network['active'] ?? 1)) return;
        }
        throw new HttpException(422, 'Bridge ' . $bridge . ' nie jest dostępny na węźle ' . $node . '.');
    }

    private function resourceId(string $value, string $label): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $value) !== 1) throw new HttpException(422, 'Nieprawidłowy identyfikator ' . $label . '.');
        return $value;
    }

    private function integer(mixed $value, int $min, int $max, string $label): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) throw new HttpException(422, $label . ' jest poza dozwolonym zakresem.');
        return (int) $number;
    }

    private function upid(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^UPID:[A-Za-z0-9._:-]+$/', $value) !== 1) throw new \RuntimeException('Proxmox did not return a valid task identifier.');
        return $value;
    }

    private function falseFlag(mixed $value): bool
    {
        return in_array($value, [false, 0, '0', 'no', 'off'], true);
    }
}
