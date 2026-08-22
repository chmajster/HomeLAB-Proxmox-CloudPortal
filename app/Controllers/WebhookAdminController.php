<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class WebhookAdminController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $rows = $this->app->pdo()->query('SELECT id,name,url,events,enabled,created_at,updated_at FROM webhooks ORDER BY name')->fetchAll();
        foreach ($rows as &$row) {
            $row['events'] = json_decode((string) $row['events'], true) ?: [];
            $row['enabled'] = (bool) $row['enabled'];
        }
        return Response::json(['data' => $rows]);
    }

    public function create(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        [$name, $url, $events, $secret] = $this->validated($request, true);
        $statement = $this->app->pdo()->prepare('INSERT INTO webhooks (name,url,secret_encrypted,events,enabled,created_by) VALUES (:name,:url,:secret,:events,:enabled,:user)');
        $statement->execute([
            'name' => $name, 'url' => $url, 'secret' => $this->app->crypto()->encrypt($secret),
            'events' => json_encode($events, JSON_THROW_ON_ERROR), 'enabled' => filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL) ? 1 : 0,
            'user' => $this->app->auth()->id(),
        ]);
        $id = (int) $this->app->pdo()->lastInsertId();
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'webhook.create', 'success', 'webhook', $id);
        return Response::json(['data' => ['id' => $id]], 201);
    }

    public function update(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        $id = (int) $request->param('id');
        [$name, $url, $events, $secret] = $this->validated($request, false);
        $fields = ['name=:name', 'url=:url', 'events=:events', 'enabled=:enabled'];
        $params = ['id' => $id, 'name' => $name, 'url' => $url, 'events' => json_encode($events, JSON_THROW_ON_ERROR), 'enabled' => filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL) ? 1 : 0];
        if ($secret !== '') {
            $fields[] = 'secret_encrypted=:secret';
            $params['secret'] = $this->app->crypto()->encrypt($secret);
        }
        $statement = $this->app->pdo()->prepare('UPDATE webhooks SET ' . implode(',', $fields) . ' WHERE id=:id');
        $statement->execute($params);
        if ($statement->rowCount() === 0) {
            $exists = $this->app->pdo()->prepare('SELECT 1 FROM webhooks WHERE id=:id');
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) throw new HttpException(404, 'Webhook not found.');
        }
        return Response::json(['data' => ['updated' => true]]);
    }

    public function delete(Request $request): Response
    {
        $this->app->csrf->verify($request);
        $this->app->auth()->requirePermission('admin.access');
        $id = (int) $request->param('id');
        $statement = $this->app->pdo()->prepare('DELETE FROM webhooks WHERE id=:id');
        $statement->execute(['id' => $id]);
        if ($statement->rowCount() !== 1) throw new HttpException(404, 'Webhook not found.');
        return Response::json(['data' => ['deleted' => true]]);
    }

    public function deliveries(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $statement = $this->app->pdo()->prepare('SELECT id,event_name,delivery_id,response_code,attempt,success,error_message,created_at FROM webhook_deliveries WHERE webhook_id=:id ORDER BY created_at DESC LIMIT 100');
        $statement->execute(['id' => (int) $request->param('id')]);
        return Response::json(['data' => $statement->fetchAll()]);
    }

    /** @return array{string,string,list<string>,string} */
    private function validated(Request $request, bool $secretRequired): array
    {
        $name = trim((string) $request->input('name'));
        $url = trim((string) $request->input('url'));
        $secret = trim((string) $request->input('secret', ''));
        $events = $request->input('events', ['*']);
        if ($name === '' || mb_strlen($name) > 100 || filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
            throw new HttpException(422, 'Invalid webhook name or URL.');
        }
        if ($secretRequired && strlen($secret) < 16) {
            throw new HttpException(422, 'Webhook signing secret must contain at least 16 characters.');
        }
        if (!is_array($events) || $events === []) {
            throw new HttpException(422, 'At least one webhook event is required.');
        }
        $events = array_values(array_unique(array_filter(array_map(static fn ($v): string => trim((string) $v), $events), static fn (string $v): bool => $v !== '' && strlen($v) <= 100)));
        if ($events === []) throw new HttpException(422, 'Webhook events are invalid.');
        return [$name, $url, $events, $secret];
    }
}
