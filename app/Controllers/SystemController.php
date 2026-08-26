<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Observability\HealthService;
use CloudPortal\Services\Observability\PrometheusMetricsService;
use CloudPortal\Services\Placement\PlacementService;
use CloudPortal\Services\Provisioning\JobRepository;

final class SystemController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function health(Request $request): Response
    {
        $report = (new HealthService($this->app->pdo()))->report();
        return Response::json(['status' => $report['ok'] ? 'ok' : 'error', 'checked_at' => $report['checked_at']], $report['ok'] ? 200 : 503);
    }

    public function ready(Request $request): Response
    {
        $report = (new HealthService($this->app->pdo()))->report();
        return Response::json(['status' => $report['ready'] ? 'ready' : 'not_ready', 'schema_current' => $report['schema_current'], 'worker_healthy' => $report['worker_healthy']], $report['ready'] ? 200 : 503);
    }

    public function metrics(Request $request): Response
    {
        if (!$this->app->auth()->isAdmin()) {
            $authorization = trim((string) $request->header('authorization', ''));
            $presented = str_starts_with($authorization, 'Bearer ') ? trim(substr($authorization, 7)) : '';
            $expected = self::metricsToken($this->app);
            if ($presented === '' || !hash_equals($expected, $presented)) {
                throw new HttpException(401, 'Prometheus metrics authentication required.');
            }
        }
        $body = (new PrometheusMetricsService($this->app->pdo()))->render();
        return new Response($body, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public static function metricsToken(Application $app): string
    {
        $encoded = (string) $app->config->get('security.encryption_key', $app->config->get('app.key'));
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) < 32) {
            throw new \RuntimeException('Application encryption key cannot derive the metrics token.');
        }
        return hash_hmac('sha256', 'algen-cloudportal-prometheus-v1', $key);
    }

    public function adminHealth(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        return Response::json(['data' => (new HealthService($this->app->pdo()))->report()]);
    }

    public function retryJob(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        $id = $request->param('jobId');
        if (!(new JobRepository($this->app->pdo()))->manualRetry($id)) {
            throw new HttpException(409, 'Job is not in failed/dead-letter state or does not exist.');
        }
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'job.retry', 'success', 'job', $id);
        return Response::json(['data' => ['job_id' => $id, 'status' => 'queued']]);
    }

    public function placement(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $connection = (int) $request->param('connectionId');
        $required = trim((string) $request->query('required_node', ''));
        $exclude = trim((string) $request->query('exclude_node', ''));
        $node = (new PlacementService($this->app->pdo()))->recommend($connection, $required === '' ? null : $required, $exclude === '' ? [] : [$exclude]);
        return Response::json(['data' => ['node_name' => $node]]);
    }
}
