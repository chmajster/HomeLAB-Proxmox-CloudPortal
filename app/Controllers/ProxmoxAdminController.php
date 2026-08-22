<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Proxmox\InfrastructureService;
use CloudPortal\Services\Proxmox\ProxmoxCatalogDiscovery;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;
use CloudPortal\Services\Proxmox\ProxmoxFailureMessage;
use CloudPortal\Services\Proxmox\ProxmoxNetworkDiscovery;
use CloudPortal\Services\Proxmox\ProxmoxVmDiscovery;
use CloudPortal\Services\Proxmox\ProxmoxVmManager;

final class ProxmoxAdminController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function sync(Request $request): Response
    {
        $this->mutating($request);
        $id = (int) $request->param('id');
        $statement = $this->app->pdo()->prepare('SELECT hostname, port, status FROM proxmox_connections WHERE id = :id');
        $statement->execute(['id' => $id]);
        $connection = $statement->fetch();
        if (!is_array($connection)) throw new HttpException(404, 'Połączenie Proxmox nie istnieje.');
        if (($connection['status'] ?? null) === 'disabled') throw new HttpException(409, 'Połączenie Proxmox jest wyłączone. Włącz je przed synchronizacją.');
        $service = new InfrastructureService($this->app->pdo(), new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()));
        try {
            $data = $service->sync($id);
            $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'proxmox.sync', 'success', 'proxmox_connection', $id);
            return Response::json(['data' => $data]);
        } catch (\Throwable $exception) {
            $publicException = ProxmoxFailureMessage::asHttpException(
                $exception, (string) $connection['hostname'], (int) $connection['port'], 'Synchronizacja Proxmox',
            );
            try {
                $this->app->pdo()->prepare("UPDATE proxmox_connections SET status = 'error', last_checked_at = CURRENT_TIMESTAMP, last_error = :error WHERE id = :id")
                    ->execute(['error' => mb_substr($publicException->getMessage(), 0, 1000), 'id' => $id]);
                $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'proxmox.sync', 'failure', 'proxmox_connection', $id, [
                    'error' => $publicException->getMessage(), 'type' => get_debug_type($exception),
                ]);
            } catch (\Throwable $recordingException) {
                error_log('Unable to record Proxmox sync failure: ' . $recordingException->getMessage());
            }
            throw $publicException;
        }
    }

    public function networkDiscovery(Request $request): Response
    {
        $this->admin();
        $discovery = new ProxmoxNetworkDiscovery(new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()));
        return Response::json(['data' => $discovery->discover($this->discoverableConnections())]);
    }

    public function storageDiscovery(Request $request): Response
    {
        $this->admin();
        $discovery = new ProxmoxCatalogDiscovery(new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()));
        return Response::json(['data' => $discovery->storages($this->discoverableConnections())]);
    }

    public function templateDiscovery(Request $request): Response
    {
        $this->admin();
        $discovery = new ProxmoxCatalogDiscovery(new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()));
        return Response::json(['data' => $discovery->templates($this->discoverableConnections())]);
    }

    public function vmDiscovery(Request $request): Response
    {
        $this->admin();
        $inventory = (new ProxmoxVmDiscovery(new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto())))
            ->discover($this->discoverableConnections());
        $portalVms = $this->app->pdo()->query(
            "SELECT vm.id AS portal_id, vm.connection_id, c.name AS connection_name, vm.vmid, vm.node_name, vm.name,
                    vm.status, vm.vcpu, vm.ram_mb, vm.disk_gb, vm.created_at, p.name AS project_name,
                    u.username AS owner_name, ip.address AS ip_address
             FROM virtual_machines vm JOIN proxmox_connections c ON c.id = vm.connection_id
             JOIN projects p ON p.id = vm.project_id JOIN users u ON u.id = vm.owner_user_id
             LEFT JOIN ip_addresses ip ON ip.virtual_machine_id = vm.id
             WHERE vm.status <> 'deleted' ORDER BY vm.created_at DESC"
        )->fetchAll();
        $byTarget = [];
        foreach ($portalVms as $portalVm) $byTarget[$portalVm['connection_id'] . ':' . $portalVm['vmid']] = $portalVm;
        $reconcile = $this->app->pdo()->prepare(
            "UPDATE virtual_machines SET node_name = :node,
             status = CASE WHEN status IN ('running','stopped','error') THEN :status ELSE status END
             WHERE id = :id"
        );

        foreach ($inventory['vms'] as &$liveVm) {
            $key = $liveVm['connection_id'] . ':' . $liveVm['vmid'];
            $portalVm = $byTarget[$key] ?? null;
            $liveVm['portal_managed'] = is_array($portalVm);
            $liveVm['live_missing'] = false;
            if (is_array($portalVm)) {
                foreach (['portal_id', 'project_name', 'owner_name', 'ip_address', 'created_at'] as $field) $liveVm[$field] = $portalVm[$field];
                if (in_array($liveVm['status'], ['running', 'stopped'], true)) {
                    $reconcile->execute(['node' => $liveVm['node_name'], 'status' => $liveVm['status'], 'id' => $portalVm['portal_id']]);
                }
                unset($byTarget[$key]);
            } else {
                $liveVm['portal_id'] = null;
                $liveVm['project_name'] = null;
                $liveVm['owner_name'] = null;
                $liveVm['ip_address'] = null;
                $liveVm['created_at'] = null;
            }
        }
        unset($liveVm);

        foreach ($byTarget as $portalVm) {
            $inventory['vms'][] = [
                ...$portalVm,
                'portal_managed' => true,
                'live_missing' => true,
                'cpu_usage' => null,
                'cpu_count' => (int) $portalVm['vcpu'],
                'memory_used' => null,
                'memory_total' => (int) $portalVm['ram_mb'] * 1024 * 1024,
                'disk_used' => null,
                'disk_total' => (int) $portalVm['disk_gb'] * 1024 * 1024 * 1024,
                'uptime' => null,
                'lock' => '',
                'tags' => '',
                'ha_state' => '',
            ];
        }
        return Response::json(['data' => $inventory]);
    }

    public function liveVmDetails(Request $request): Response
    {
        $this->admin();
        [$connectionId, $node, $vmid, $connection] = $this->liveVmTarget($request);
        try {
            $details = $this->liveVmManager()->details($connectionId, $node, $vmid);
            $portal = $this->portalVm($connectionId, $vmid);
            if (is_array($portal)) {
                $statement = $this->app->pdo()->prepare('SELECT id, name, status FROM snapshots WHERE virtual_machine_id = :vm');
                $statement->execute(['vm' => $portal['portal_id']]);
                $portalSnapshots = [];
                foreach ($statement->fetchAll() as $snapshot) $portalSnapshots[(string) $snapshot['name']] = $snapshot;
                foreach ($details['snapshots'] as &$snapshot) {
                    $known = $portalSnapshots[(string) $snapshot['name']] ?? null;
                    $snapshot['portal_snapshot_id'] = is_array($known) ? (int) $known['id'] : null;
                    $snapshot['portal_status'] = is_array($known) ? (string) $known['status'] : null;
                }
                unset($snapshot);
            }
            return Response::json(['data' => [...$details, 'portal' => $portal]]);
        } catch (\Throwable $exception) {
            throw $this->liveVmFailure($exception, $connection, 'Odczyt szczegółów VM');
        }
    }

    public function liveVmPower(Request $request): Response
    {
        $this->mutating($request);
        [$connectionId, $node, $vmid, $connection] = $this->liveVmTarget($request);
        $this->assertUnmanagedVm($connectionId, $vmid);
        $action = $request->param('action');
        try {
            $upid = $this->liveVmManager()->power($connectionId, $node, $vmid, $action);
            $this->auditLiveVm($request, 'admin.proxmox_vm.' . $action, $connectionId, $node, $vmid, ['upid' => $upid]);
            return Response::json(['data' => ['upid' => $upid, 'status' => 'queued']], 202);
        } catch (\Throwable $exception) {
            throw $this->liveVmFailure($exception, $connection, 'Zmiana stanu VM');
        }
    }

    public function liveVmSnapshot(Request $request): Response
    {
        $this->mutating($request);
        [$connectionId, $node, $vmid, $connection] = $this->liveVmTarget($request);
        $this->assertUnmanagedVm($connectionId, $vmid);
        $name = trim((string) $request->input('name'));
        $description = trim((string) $request->input('description', ''));
        try {
            $upid = $this->liveVmManager()->snapshot($connectionId, $node, $vmid, $name, $description);
            $this->auditLiveVm($request, 'admin.proxmox_vm.snapshot.create', $connectionId, $node, $vmid, ['snapshot_name' => $name, 'upid' => $upid]);
            return Response::json(['data' => ['upid' => $upid, 'status' => 'queued']], 202);
        } catch (\Throwable $exception) {
            throw $this->liveVmFailure($exception, $connection, 'Tworzenie snapshotu VM');
        }
    }

    public function deleteLiveVmSnapshot(Request $request): Response
    {
        $this->mutating($request);
        [$connectionId, $node, $vmid, $connection] = $this->liveVmTarget($request);
        $this->assertUnmanagedVm($connectionId, $vmid);
        $name = $request->param('snapshotName');
        try {
            $upid = $this->liveVmManager()->deleteSnapshot($connectionId, $node, $vmid, $name);
            $this->auditLiveVm($request, 'admin.proxmox_vm.snapshot.delete', $connectionId, $node, $vmid, ['snapshot_name' => $name, 'upid' => $upid]);
            return Response::json(['data' => ['upid' => $upid, 'status' => 'queued']], 202);
        } catch (\Throwable $exception) {
            throw $this->liveVmFailure($exception, $connection, 'Usuwanie snapshotu VM');
        }
    }

    public function liveVmConsole(Request $request): Response
    {
        $this->mutating($request);
        [$connectionId, $node, $vmid, $connection] = $this->liveVmTarget($request);
        try {
            $content = $this->liveVmManager()->console($connectionId, $node, $vmid, (string) $connection['hostname']);
            $this->auditLiveVm($request, 'admin.proxmox_vm.console', $connectionId, $node, $vmid);
            return new Response($content, 200, [
                'Content-Type' => 'application/x-virt-viewer',
                'Content-Disposition' => 'attachment; filename="proxmox-vm-' . $vmid . '.vv"',
                'Cache-Control' => 'no-store',
            ]);
        } catch (\Throwable $exception) {
            throw $this->liveVmFailure($exception, $connection, 'Konsola VM');
        }
    }

    /** @return list<array<string,mixed>> */
    private function discoverableConnections(): array
    {
        return $this->app->pdo()->query("SELECT id, name, hostname, port, status FROM proxmox_connections WHERE status <> 'disabled' ORDER BY name")->fetchAll();
    }

    private function liveVmManager(): ProxmoxVmManager
    {
        return new ProxmoxVmManager(new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()));
    }

    /** @return array{int,string,int,array<string,mixed>} */
    private function liveVmTarget(Request $request): array
    {
        $connectionId = $this->positiveInt($request->param('connectionId'), 1, PHP_INT_MAX);
        $vmid = $this->positiveInt($request->param('vmid'), 100, 999999999);
        $node = trim($request->param('node'));
        if ($node === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) {
            throw new HttpException(422, 'Invalid Proxmox node name.');
        }
        return [$connectionId, $node, $vmid, $this->proxmoxConnection($connectionId)];
    }

    /** @return array<string,mixed> */
    private function proxmoxConnection(int $connectionId): array
    {
        $statement = $this->app->pdo()->prepare('SELECT id, name, hostname, port, status FROM proxmox_connections WHERE id = :id');
        $statement->execute(['id' => $connectionId]);
        $connection = $statement->fetch();
        if (!is_array($connection)) throw new HttpException(404, 'Połączenie Proxmox nie istnieje.');
        if (($connection['status'] ?? null) === 'disabled') throw new HttpException(409, 'Połączenie Proxmox jest wyłączone.');
        return $connection;
    }

    /** @return array<string,mixed>|null */
    private function portalVm(int $connectionId, int $vmid): ?array
    {
        $statement = $this->app->pdo()->prepare(
            "SELECT vm.id AS portal_id, vm.name, vm.status, vm.project_id, vm.owner_user_id,
                    p.name AS project_name, u.username AS owner_name, ip.address AS ip_address
             FROM virtual_machines vm JOIN projects p ON p.id = vm.project_id
             JOIN users u ON u.id = vm.owner_user_id LEFT JOIN ip_addresses ip ON ip.virtual_machine_id = vm.id
             WHERE vm.connection_id = :connection AND vm.vmid = :vmid AND vm.status <> 'deleted' LIMIT 1"
        );
        $statement->execute(['connection' => $connectionId, 'vmid' => $vmid]);
        $vm = $statement->fetch();
        return is_array($vm) ? $vm : null;
    }

    private function assertUnmanagedVm(int $connectionId, int $vmid): void
    {
        if ($this->portalVm($connectionId, $vmid) !== null) {
            throw new HttpException(409, 'Ta maszyna jest zarządzana przez portal. Użyj operacji portalowej, aby zachować kolejkę zadań, quota i historię zmian.');
        }
    }

    /** @param array<string,mixed> $metadata */
    private function auditLiveVm(Request $request, string $action, int $connectionId, string $node, int $vmid, array $metadata = []): void
    {
        $this->app->audit()->log(
            $this->app->auth()->id(), $request->ip(), $action, 'success', 'proxmox_vm', $connectionId . ':' . $vmid,
            ['connection_id' => $connectionId, 'node' => $node, 'vmid' => $vmid, ...$metadata],
        );
    }

    /** @param array<string,mixed> $connection */
    private function liveVmFailure(\Throwable $exception, array $connection, string $operation): HttpException
    {
        return ProxmoxFailureMessage::asHttpException($exception, (string) $connection['hostname'], (int) $connection['port'], $operation);
    }

    private function positiveInt(mixed $value, int $min, int $max): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($int === false) throw new HttpException(422, 'Numeric value is outside the allowed range.');
        return (int) $int;
    }

    private function mutating(Request $request): void
    {
        $this->admin();
        $this->app->csrf->verify($request);
    }

    private function admin(): void
    {
        $this->app->auth()->requirePermission('admin.access');
    }
}
