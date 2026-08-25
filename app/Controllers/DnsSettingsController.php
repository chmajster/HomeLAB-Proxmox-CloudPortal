<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\DNS\DnsApiException;
use CloudPortal\Services\DNS\DnsSettingsService;

final class DnsSettingsController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function listSafe(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $rows = $this->app->pdo()->query('SELECT setting_key,value,is_public,updated_at FROM settings ORDER BY setting_key')->fetchAll();
        foreach ($rows as &$row) {
            $key = strtolower((string) ($row['setting_key'] ?? ''));
            if (preg_match('/(?:password|secret|token|encrypted)/', $key) === 1) {
                $row['value'] = json_encode('***', JSON_THROW_ON_ERROR);
            }
        }
        unset($row);
        return Response::json(['data' => $rows]);
    }

    public function show(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        return Response::json(['data' => $this->service()->publicConfiguration()]);
    }

    public function update(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        try {
            $config = $this->service()->save($request->all(), (int) $this->app->auth()->id());
        } catch (\InvalidArgumentException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        $this->app->audit()->log(
            $this->app->auth()->id(),
            $request->ip(),
            'admin.dns.settings.update',
            'success',
            'settings',
            'dns',
            [
                'enabled' => $config['enabled'],
                'server_ip' => $config['server_ip'],
                'port' => $config['port'],
                'scheme' => $config['scheme'],
                'forward_zone' => $config['forward_zone'],
                'hostname_pattern' => $config['hostname_pattern'],
                'token_configured' => $config['token_configured'],
            ],
        );
        return Response::json(['data' => $config]);
    }

    public function test(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        try {
            $result = $this->service()->test($request->all());
        } catch (DnsApiException $exception) {
            throw new HttpException(
                422,
                'HomeLAB-DNS API zwróciło błąd: ' . $exception->getMessage(),
                ['dns_status' => $exception->httpStatus],
            );
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            throw new HttpException(422, $exception->getMessage());
        }
        $this->app->audit()->log(
            $this->app->auth()->id(),
            $request->ip(),
            'admin.dns.settings.test',
            'success',
            'settings',
            'dns',
            ['server_ip' => $result['server_ip'] ?? null, 'forward_zone' => $result['forward_zone'] ?? null],
        );
        return Response::json(['data' => $result]);
    }

    private function service(): DnsSettingsService
    {
        return new DnsSettingsService($this->app->pdo(), $this->app->crypto(), $this->app->config);
    }
}
