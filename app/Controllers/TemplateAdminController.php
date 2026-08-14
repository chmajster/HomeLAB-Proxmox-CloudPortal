<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Proxmox\IsoUploadService;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;
use CloudPortal\Services\Proxmox\ProxmoxClientInterface;
use CloudPortal\Services\Proxmox\ProxmoxFailureMessage;
use CloudPortal\Services\Proxmox\ProxmoxFileUploadInterface;
use CloudPortal\Services\Proxmox\ProxmoxTemplateBuildDiscovery;
use CloudPortal\Services\Proxmox\ProxmoxTemplateBuilder;

final class TemplateAdminController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function options(Request $request): Response
    {
        $this->admin();
        $data = (new ProxmoxTemplateBuildDiscovery($this->factory()))->discover($this->discoverableConnections());
        $managed = [];
        foreach ($this->app->pdo()->query("SELECT connection_id, vmid FROM virtual_machines WHERE status <> 'deleted'")->fetchAll() as $vm) {
            $managed[(int) $vm['connection_id'] . ':' . (int) $vm['vmid']] = true;
        }
        foreach ($data['candidates'] as &$candidate) {
            $candidate['portal_managed'] = isset($managed[(int) $candidate['connection_id'] . ':' . (int) $candidate['vmid']]);
        }
        unset($candidate);
        $data['max_iso_bytes'] = (int) $this->app->config->get('uploads.max_iso_bytes', 17179869184);
        return Response::json(['data' => $data]);
    }

    public function initializeIsoUpload(Request $request): Response
    {
        $this->admin();
        $this->app->csrf->verify($request);
        $connectionId = $this->positiveInt($request->input('connection_id'), 1, PHP_INT_MAX, 'connection_id');
        $connection = $this->connection($connectionId);
        $node = $this->resourceId((string) $request->input('node'), 'węzła');
        $storage = $this->resourceId((string) $request->input('storage'), 'storage');
        try {
            $this->assertIsoUploadTarget($this->factory()->forConnection($connectionId), $node, $storage);
            $upload = $this->uploads()->initialize([
                ...$request->all(),
                'connection_id' => $connectionId,
                'node' => $node,
                'storage' => $storage,
            ], $this->userId());
            $this->audit($request, 'admin.iso_upload.initialize', $upload['id'], [
                'connection_id' => $connectionId, 'node' => $node, 'storage' => $storage,
                'filename' => $upload['filename'], 'size' => $upload['size'],
            ]);
            return Response::json(['data' => $upload], 201);
        } catch (\Throwable $exception) {
            throw $this->failure($exception, $connection, 'Rozpoczęcie uploadu ISO');
        }
    }

    public function appendIsoChunk(Request $request): Response
    {
        $this->admin();
        $this->app->csrf->verify($request);
        if (!str_starts_with(strtolower((string) $request->header('content-type', '')), 'application/octet-stream')) {
            throw new HttpException(415, 'Fragment ISO musi mieć Content-Type application/octet-stream.');
        }
        $offset = filter_var($request->header('x-upload-offset'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($offset === false) throw new HttpException(422, 'Brak prawidłowego nagłówka X-Upload-Offset.');
        $upload = $this->uploads()->append($request->param('uploadId'), $this->userId(), (int) $offset, $request->rawBody());
        return Response::json(['data' => $upload]);
    }

    public function completeIsoUpload(Request $request): Response
    {
        $this->admin();
        $this->app->csrf->verify($request);
        $service = $this->uploads();
        $metadata = $service->metadata($request->param('uploadId'), $this->userId());
        $connection = $this->connection((int) $metadata['connection_id']);
        try {
            @set_time_limit(3700);
            $client = $this->factory()->forConnection((int) $metadata['connection_id']);
            if (!$client instanceof ProxmoxFileUploadInterface) {
                throw new \RuntimeException('Configured Proxmox client does not support file uploads.');
            }
            $result = $service->complete($request->param('uploadId'), $this->userId(), function (array $upload, string $path) use ($client): array {
                $upid = $client->uploadIso((string) $upload['node'], (string) $upload['storage'], $path, (string) $upload['filename']);
                $task = is_string($upid) && str_starts_with($upid, 'UPID:')
                    ? $client->waitForTask((string) $upload['node'], $upid, 3600)
                    : null;
                return ['upid' => $upid, 'task' => $task];
            });
            $this->audit($request, 'admin.iso_upload.complete', $metadata['id'], [
                'connection_id' => $metadata['connection_id'], 'node' => $metadata['node'],
                'storage' => $metadata['storage'], 'filename' => $metadata['filename'],
            ]);
            return Response::json(['data' => $result], 201);
        } catch (\Throwable $exception) {
            throw $this->failure($exception, $connection, 'Upload ISO do Proxmox');
        }
    }

    public function cancelIsoUpload(Request $request): Response
    {
        $this->admin();
        $this->app->csrf->verify($request);
        $id = $request->param('uploadId');
        $this->uploads()->cancel($id, $this->userId());
        $this->audit($request, 'admin.iso_upload.cancel', $id);
        return Response::json(['data' => ['cancelled' => true]]);
    }

    public function createInstallationVm(Request $request): Response
    {
        $this->admin();
        $this->app->csrf->verify($request);
        $connectionId = $this->positiveInt($request->input('connection_id'), 1, PHP_INT_MAX, 'connection_id');
        $connection = $this->connection($connectionId);
        try {
            $result = (new ProxmoxTemplateBuilder($this->factory()))->createInstallationVm(
                $connectionId,
                (string) $request->input('node'),
                $request->all(),
            );
            $this->audit($request, 'admin.template_builder.vm.create', $connectionId . ':' . $result['vmid'], $result);
            return Response::json(['data' => $result], 202);
        } catch (\Throwable $exception) {
            throw $this->failure($exception, $connection, 'Tworzenie instalacyjnej VM');
        }
    }

    public function convertVmToTemplate(Request $request): Response
    {
        $this->admin();
        $this->app->csrf->verify($request);
        $connectionId = $this->positiveInt($request->input('connection_id'), 1, PHP_INT_MAX, 'connection_id');
        $vmid = $this->positiveInt($request->input('vmid'), 100, 999999999, 'vmid');
        $node = $this->resourceId((string) $request->input('node'), 'węzła');
        $connection = $this->connection($connectionId);
        $managed = $this->app->pdo()->prepare("SELECT 1 FROM virtual_machines WHERE connection_id = :connection AND vmid = :vmid AND status <> 'deleted' LIMIT 1");
        $managed->execute(['connection' => $connectionId, 'vmid' => $vmid]);
        if ($managed->fetchColumn()) {
            throw new HttpException(409, 'Ta VM jest zarządzana przez portal i nie może zostać bezpośrednio zamieniona w template.');
        }
        try {
            $result = (new ProxmoxTemplateBuilder($this->factory()))->convert($connectionId, $node, $vmid);
            $this->audit($request, 'admin.template_builder.convert', $connectionId . ':' . $vmid, $result);
            return Response::json(['data' => $result], 202);
        } catch (\Throwable $exception) {
            throw $this->failure($exception, $connection, 'Konwersja VM do template');
        }
    }

    private function assertIsoUploadTarget(ProxmoxClientInterface $client, string $node, string $storage): void
    {
        $config = $client->get('/storage/' . rawurlencode($storage));
        $status = $client->get('/nodes/' . rawurlencode($node) . '/storage/' . rawurlencode($storage) . '/status');
        $content = is_array($config) ? array_map('trim', explode(',', (string) ($config['content'] ?? ''))) : [];
        $inactive = !is_array($status) || in_array($status['active'] ?? 1, [false, 0, '0', 'no', 'off'], true) || in_array($status['enabled'] ?? 1, [false, 0, '0', 'no', 'off'], true);
        if (!is_array($config) || !in_array('iso', $content, true) || $inactive) {
            throw new HttpException(422, 'Wybrane storage nie jest aktywnym celem uploadu ISO na tym węźle.');
        }
    }

    /** @return list<array<string,mixed>> */
    private function discoverableConnections(): array
    {
        return $this->app->pdo()->query("SELECT id, name, hostname, port, status FROM proxmox_connections WHERE status <> 'disabled' ORDER BY name")->fetchAll();
    }

    /** @return array<string,mixed> */
    private function connection(int $id): array
    {
        $statement = $this->app->pdo()->prepare('SELECT id, name, hostname, port, status FROM proxmox_connections WHERE id = :id');
        $statement->execute(['id' => $id]);
        $connection = $statement->fetch();
        if (!is_array($connection)) throw new HttpException(404, 'Połączenie Proxmox nie istnieje.');
        if (($connection['status'] ?? null) === 'disabled') throw new HttpException(409, 'Połączenie Proxmox jest wyłączone.');
        return $connection;
    }

    private function factory(): ProxmoxClientFactory
    {
        return new ProxmoxClientFactory($this->app->pdo(), $this->app->crypto());
    }

    private function uploads(): IsoUploadService
    {
        return new IsoUploadService(
            $this->app->root . '/storage/uploads',
            (int) $this->app->config->get('uploads.max_iso_bytes', 17179869184),
        );
    }

    private function userId(): int
    {
        return $this->app->auth()->id() ?? throw new HttpException(401, 'Authentication required.');
    }

    /** @param array<string,mixed> $metadata */
    private function audit(Request $request, string $action, string|int $resourceId, array $metadata = []): void
    {
        $this->app->audit()->log($this->userId(), $request->ip(), $action, 'success', 'proxmox_template', $resourceId, $metadata);
    }

    /** @param array<string,mixed> $connection */
    private function failure(\Throwable $exception, array $connection, string $operation): HttpException
    {
        error_log($operation . ': ' . $exception->getMessage());
        return ProxmoxFailureMessage::asHttpException($exception, (string) $connection['hostname'], (int) $connection['port'], $operation);
    }

    private function positiveInt(mixed $value, int $min, int $max, string $label): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($number === false) throw new HttpException(422, 'Pole ' . $label . ' jest poza dozwolonym zakresem.');
        return (int) $number;
    }

    private function resourceId(string $value, string $label): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $value) !== 1) throw new HttpException(422, 'Nieprawidłowy identyfikator ' . $label . '.');
        return $value;
    }

    private function admin(): void
    {
        $this->app->auth()->requirePermission('admin.access');
    }
}
