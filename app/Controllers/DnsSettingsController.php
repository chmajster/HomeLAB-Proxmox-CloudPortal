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

    public function upsertSafe(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        $key = trim((string) $request->input('key'));
        if (preg_match('/^[a-z][a-z0-9_.-]{1,99}$/', $key) !== 1) {
            throw new HttpException(422, 'Invalid setting key.');
        }
        if (str_starts_with($key, 'dns.') || $key === 'hostname_generator.pattern') {
            throw new HttpException(409, 'Ustawienia DNS i generatora hostname zmieniaj w dedykowanej sekcji Integracja DNS.');
        }
        $value = $request->input('value');
        if ($key === 'portal.name' && (!is_string($value) || trim($value) === '' || mb_strlen($value) > 100)) {
            throw new HttpException(422, 'portal.name must be a non-empty string of at most 100 characters.');
        }
        $statement = $this->app->pdo()->prepare(
            'INSERT INTO settings(setting_key,value,is_public,updated_by) VALUES(:key,:value,:public,:user) '
            . 'ON DUPLICATE KEY UPDATE value=VALUES(value),is_public=VALUES(is_public),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'public' => (int) filter_var($request->input('is_public', false), FILTER_VALIDATE_BOOL),
            'user' => $this->app->auth()->id(),
        ]);
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.settings.create', 'success', 'settings', $key);
        return Response::json(['data' => ['id' => $key]], 201);
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
