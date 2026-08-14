<?php

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = dirname(__DIR__) . '/public' . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
if ($path === '/api/v1/dashboard') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['data' => [
        'summary' => ['vms' => 7, 'running' => 5, 'stopped' => 2, 'vcpu' => 24, 'ram_mb' => 49152, 'storage_gb' => 620],
        'recent_vms' => [
            ['id' => 1, 'name' => 'web-production', 'vmid' => 101, 'status' => 'running', 'vcpu' => 4, 'ram_mb' => 8192, 'disk_gb' => 80, 'created_at' => '2026-08-13 19:30:00'],
            ['id' => 2, 'name' => 'database-01', 'vmid' => 102, 'status' => 'stopped', 'vcpu' => 8, 'ram_mb' => 16384, 'disk_gb' => 160, 'created_at' => '2026-08-12 11:10:00'],
        ],
        'recent_jobs' => [
            ['public_id' => 'job-1', 'type' => 'vm.create', 'status' => 'completed', 'vm_name' => 'web-production', 'created_at' => '2026-08-13 19:30:00'],
            ['public_id' => 'job-2', 'type' => 'vm.snapshot.create', 'status' => 'running', 'vm_name' => 'database-01', 'created_at' => '2026-08-13 19:35:00'],
        ],
        'admin_counts' => ['users' => 18, 'projects' => 6, 'proxmox_connections' => 2, 'proxmox_nodes' => 5],
        'admin_usage' => ['cpu_used' => 21.4, 'cpu_total' => 64, 'ram_used' => 137438953472, 'ram_total' => 274877906944, 'storage_used' => 4398046511104, 'storage_total' => 8796093022208],
        'infrastructure' => [[
            'connection' => ['id' => 1, 'name' => 'Production'], 'error' => null,
            'resources' => [['type' => 'node'], ['type' => 'node'], ['type' => 'qemu'], ['type' => 'qemu'], ['type' => 'lxc']], 'tasks' => [],
        ]],
        'proxmox_tasks' => [], 'recent_errors' => [],
    ]], JSON_THROW_ON_ERROR);
    return;
}
if ($path === '/__visual') {
    $root = dirname(__DIR__);
    require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';
    $view = new \CloudPortal\Support\View($root . '/resources/views', ['basePath' => '']);
    echo $view->render('pages/portal', [
        'user' => ['id' => 1, 'username' => 'administrator', 'locale' => 'pl'],
        'isAdmin' => true,
        'page' => 'dashboard',
        'csrf' => 'visual-test-token',
        'appName' => 'Algen Cloud Portal',
        'firstRun' => null,
    ]);
    return;
}
require dirname(__DIR__) . '/public/index.php';
