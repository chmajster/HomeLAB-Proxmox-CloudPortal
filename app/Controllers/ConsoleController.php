<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Database\Database;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Provisioning\VmOperationService;
use CloudPortal\Services\Proxmox\ConsoleToken;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;
use CloudPortal\Services\Proxmox\ProxmoxConsoleSessionService;
use CloudPortal\Services\Proxmox\ProxmoxNoVncAssetProxy;

final class ConsoleController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function portalSession(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('vm.operate');
        $vm = (new VmOperationService(new Database($this->app->config)))->accessibleVm(
            (int) $request->param('id'),
            (int) $user['id'],
            $this->app->auth()->isAdmin(),
        );

        $session = $this->sessions()->create(
            (int) $vm['connection_id'],
            (string) $vm['node_name'],
            (int) $vm['vmid'],
            (int) $user['id'],
        );
        $this->app->audit()->log(
            (int) $user['id'],
            $request->ip(),
            'vm.console.novnc',
            'success',
            'virtual_machine',
            (int) $vm['id'],
            ['node' => (string) $vm['node_name'], 'vmid' => (int) $vm['vmid']],
        );

        return Response::json(['data' => $this->browserSession((int) $vm['connection_id'], $session)]);
    }

    public function liveSession(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $user = $this->app->auth()->requireUser();
        $this->app->auth()->requirePermission('admin.access');
        [$connectionId, $node, $vmid] = $this->liveTarget($request);
        $this->assertConnection($connectionId);

        $session = $this->sessions()->create($connectionId, $node, $vmid, (int) $user['id']);
        $this->app->audit()->log(
            (int) $user['id'],
            $request->ip(),
            'admin.proxmox_vm.console.novnc',
            'success',
            'proxmox_connection',
            $connectionId,
            ['node' => $node, 'vmid' => $vmid],
        );

        return Response::json(['data' => $this->browserSession($connectionId, $session)]);
    }

    public function asset(Request $request): Response
    {
        $this->app->auth()->requireUser();
        $connectionId = filter_var($request->param('connectionId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($connectionId === false) throw new HttpException(404, 'noVNC asset not found.');
        $this->assertConnection((int) $connectionId);

        $asset = rawurldecode($request->param('asset'));
        $proxied = (new ProxmoxNoVncAssetProxy($this->app->pdo(), $this->app->crypto()))->fetch((int) $connectionId, $asset);
        return new Response($proxied['body'], 200, [
            'Content-Type' => $proxied['content_type'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array{mode:string,token:string,password:string,expires_in:int} $session @return array<string,mixed> */
    private function browserSession(int $connectionId, array $session): array
    {
        return [
            'mode' => 'novnc',
            'ws_path' => '/console/ws/' . $session['token'],
            'rfb_module' => '/console/novnc/' . $connectionId . '/core/rfb.js',
            'password' => $session['password'],
            'expires_in' => $session['expires_in'],
        ];
    }

    private function sessions(): ProxmoxConsoleSessionService
    {
        return new ProxmoxConsoleSessionService(
            new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto()),
            new ConsoleToken($this->consoleSecret()),
        );
    }

    private function consoleSecret(): string
    {
        $secret = (string) $this->app->config->get(
            'security.encryption_key',
            $this->app->config->get('app.key', ''),
        );
        if ($secret === '') throw new \RuntimeException('Portal encryption key is not configured.');
        return $secret;
    }

    /** @return array{int,string,int} */
    private function liveTarget(Request $request): array
    {
        $connectionId = filter_var($request->param('connectionId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $vmid = filter_var($request->param('vmid'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 100, 'max_range' => 999999999]]);
        $node = trim($request->param('node'));
        if ($connectionId === false || $vmid === false || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $node) !== 1) {
            throw new HttpException(422, 'Invalid Proxmox console target.');
        }
        return [(int) $connectionId, $node, (int) $vmid];
    }

    private function assertConnection(int $connectionId): void
    {
        $statement = $this->app->pdo()->prepare("SELECT status FROM proxmox_connections WHERE id=:id LIMIT 1");
        $statement->execute(['id' => $connectionId]);
        $status = $statement->fetchColumn();
        if (!is_string($status)) throw new HttpException(404, 'Proxmox connection not found.');
        if ($status === 'disabled') throw new HttpException(409, 'Proxmox connection is disabled.');
    }
}
