<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;

final class JobController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function show(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $sql = 'SELECT public_id, type, status, virtual_machine_id, proxmox_upid, result, error_message, created_at, started_at, finished_at FROM jobs WHERE public_id = :id';
        $params = ['id' => $request->param('id')];
        if (!$this->app->auth()->isAdmin()) {
            $sql .= ' AND user_id = :user';
            $params['user'] = $user['id'];
        }
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        $job = $statement->fetch();
        if (!is_array($job)) {
            throw new HttpException(404, 'Job not found.');
        }
        $job['result'] = $job['result'] ? json_decode((string) $job['result'], true) : null;
        return Response::json(['data' => $job]);
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $sql = 'SELECT public_id, type, status, virtual_machine_id, error_message, created_at, started_at, finished_at FROM jobs';
        $params = [];
        if (!$this->app->auth()->isAdmin()) {
            $sql .= ' WHERE user_id = :user';
            $params['user'] = $user['id'];
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 100';
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        return Response::json(['data' => $statement->fetchAll()]);
    }
}

