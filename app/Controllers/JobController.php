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
        $sql = 'SELECT j.id, j.public_id, j.correlation_id, j.type, j.status, j.virtual_machine_id, j.proxmox_upid, j.attempts, j.max_attempts, j.dead_letter_at, j.result, j.error_message, j.created_at, j.started_at, j.finished_at FROM jobs j WHERE j.public_id = :id';
        $params = ['id' => $request->param('id')];
        if (!$this->app->auth()->isAdmin()) {
            $sql .= ' AND j.user_id = :user';
            $params['user'] = $user['id'];
        }
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        $job = $statement->fetch();
        if (!is_array($job)) {
            throw new HttpException(404, 'Job not found.');
        }
        $job['result'] = $job['result'] ? json_decode((string) $job['result'], true) : null;

        $provisioning = $this->app->pdo()->prepare(
            'SELECT id,status,current_step,current_step_name,hostname,fqdn,ip_address,forward_zone,reverse_zone,last_error,ready_at,created_at,updated_at FROM vm_provisioning WHERE job_id=:job LIMIT 1'
        );
        $provisioning->execute(['job' => $job['id']]);
        $state = $provisioning->fetch();
        $events = [];
        if (is_array($state)) {
            $eventStatement = $this->app->pdo()->prepare('SELECT step,step_name,result,message,created_at FROM vm_provisioning_events WHERE provisioning_id=:id ORDER BY id');
            $eventStatement->execute(['id' => $state['id']]);
            $events = $eventStatement->fetchAll();
            unset($state['id']);
        }
        unset($job['id']);
        $job['provisioning'] = is_array($state) ? [...$state, 'events' => $events] : null;
        return Response::json(['data' => $job]);
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $sql = 'SELECT j.public_id, j.correlation_id, j.type, j.status, j.virtual_machine_id, j.proxmox_upid, j.attempts, j.max_attempts, j.dead_letter_at, j.error_message, j.created_at, j.started_at, j.finished_at,
                       vp.status AS provisioning_status, vp.current_step AS provisioning_step, vp.current_step_name AS provisioning_step_name,
                       vp.hostname, vp.fqdn, vp.ip_address
                FROM jobs j LEFT JOIN vm_provisioning vp ON vp.job_id=j.id';
        $params = [];
        if (!$this->app->auth()->isAdmin()) {
            $sql .= ' WHERE j.user_id = :user';
            $params['user'] = $user['id'];
        }
        $sql .= ' ORDER BY j.created_at DESC LIMIT 100';
        $statement = $this->app->pdo()->prepare($sql);
        $statement->execute($params);
        return Response::json(['data' => $statement->fetchAll()]);
    }
}
