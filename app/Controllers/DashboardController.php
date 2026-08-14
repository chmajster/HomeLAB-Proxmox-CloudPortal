<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Services\Proxmox\InfrastructureService;
use CloudPortal\Services\Proxmox\ProxmoxClientFactory;
use PDO;

final class DashboardController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->app->auth()->requireUser();
        $pdo = $this->app->pdo();
        $isAdmin = $this->app->auth()->isAdmin();
        $filter = $isAdmin ? '' : ' AND owner_user_id = :user';
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS vms,
                    COALESCE(SUM(status = 'running'),0) AS running,
                    COALESCE(SUM(status = 'stopped'),0) AS stopped,
                    COALESCE(SUM(vcpu),0) AS vcpu,
                    COALESCE(SUM(ram_mb),0) AS ram_mb,
                    COALESCE(SUM(disk_gb),0) AS storage_gb
             FROM virtual_machines WHERE status <> 'deleted'{$filter}"
        );
        $statement->execute($isAdmin ? [] : ['user' => $user['id']]);
        $summary = $statement->fetch() ?: [];
        $recentVms = $pdo->prepare(
            "SELECT id, name, vmid, status, vcpu, ram_mb, disk_gb, created_at
             FROM virtual_machines WHERE status <> 'deleted'{$filter} ORDER BY created_at DESC LIMIT 5"
        );
        $recentVms->execute($isAdmin ? [] : ['user' => $user['id']]);
        $jobsWhere = $isAdmin ? '' : ' WHERE j.user_id = :user';
        $recentJobs = $pdo->prepare(
            "SELECT j.public_id, j.type, j.status, j.error_message, j.created_at, vm.name AS vm_name
             FROM jobs j LEFT JOIN virtual_machines vm ON vm.id = j.virtual_machine_id{$jobsWhere}
             ORDER BY j.created_at DESC LIMIT 8"
        );
        $recentJobs->execute($isAdmin ? [] : ['user' => $user['id']]);

        $data = ['summary' => $summary, 'recent_vms' => $recentVms->fetchAll(), 'recent_jobs' => $recentJobs->fetchAll()];
        if ($isAdmin) {
            $counts = [];
            foreach (['users', 'projects', 'proxmox_connections', 'proxmox_nodes'] as $table) {
                $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            }
            $data['admin_counts'] = $counts;
            $data['infrastructure'] = (new InfrastructureService($pdo, new ProxmoxClientFactory($pdo, $this->app->crypto())))->clusterOverview();
            $usage = ['cpu_used' => 0.0, 'cpu_total' => 0, 'ram_used' => 0, 'ram_total' => 0, 'storage_used' => 0, 'storage_total' => 0];
            foreach ($data['infrastructure'] as $cluster) {
                foreach ($cluster['resources'] as $resource) {
                    if (($resource['type'] ?? null) === 'node') {
                        $usage['cpu_used'] += (float) ($resource['cpu'] ?? 0) * (int) ($resource['maxcpu'] ?? 0);
                        $usage['cpu_total'] += (int) ($resource['maxcpu'] ?? 0);
                        $usage['ram_used'] += (int) ($resource['mem'] ?? 0);
                        $usage['ram_total'] += (int) ($resource['maxmem'] ?? 0);
                    } elseif (($resource['type'] ?? null) === 'storage') {
                        $usage['storage_used'] += (int) ($resource['disk'] ?? 0);
                        $usage['storage_total'] += (int) ($resource['maxdisk'] ?? 0);
                    }
                }
            }
            $data['admin_usage'] = $usage;
            $proxmoxTasks = [];
            foreach ($data['infrastructure'] as $cluster) {
                foreach ($cluster['tasks'] as $task) {
                    if (is_array($task)) {
                        $proxmoxTasks[] = ['cluster' => $cluster['connection']['name'], ...$task];
                    }
                }
            }
            usort($proxmoxTasks, static fn (array $a, array $b): int => (int) ($b['starttime'] ?? 0) <=> (int) ($a['starttime'] ?? 0));
            $data['proxmox_tasks'] = array_slice($proxmoxTasks, 0, 10);
            $errors = $pdo->query("SELECT public_id,type,error_message,created_at FROM jobs WHERE status='failed' ORDER BY created_at DESC LIMIT 10");
            $data['recent_errors'] = $errors->fetchAll();
        } else {
            $quota = $pdo->prepare(
                'SELECT q.* FROM quotas q WHERE q.user_id = :user LIMIT 1'
            );
            $quota->execute(['user' => $user['id']]);
            $data['quota'] = $quota->fetch() ?: null;
        }
        return Response::json(['data' => $data]);
    }
}
