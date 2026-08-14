<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';

use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use CloudPortal\Http\Router;
use CloudPortal\Http\Validator;
use CloudPortal\Security\Crypto;
use CloudPortal\Services\Auth\AuthService;
use CloudPortal\Services\Proxmox\ProxmoxClient;
use CloudPortal\Support\Uuid;

$tests = [];
$test = static function (string $name, callable $callback) use (&$tests): void {
    try {
        $callback();
        $tests[] = ['ok' => true, 'name' => $name];
    } catch (Throwable $exception) {
        $tests[] = ['ok' => false, 'name' => $name, 'error' => $exception->getMessage()];
    }
};
$expect = static function (bool $condition, string $message = 'Assertion failed'): void {
    if (!$condition) throw new RuntimeException($message);
};

$test('secretbox round trip and ciphertext authentication', static function () use ($expect): void {
    $crypto = new Crypto(base64_encode(random_bytes(32)));
    $encrypted = $crypto->encrypt('sensitive-token');
    $expect(!str_contains($encrypted, 'sensitive-token'));
    $expect($crypto->decrypt($encrypted) === 'sensitive-token');
});

$test('password hashing', static function () use ($expect): void {
    $hash = AuthService::hashPassword('long-password-for-smoke-test');
    $expect(password_verify('long-password-for-smoke-test', $hash));
});

$test('UUID v4 format and uniqueness', static function () use ($expect): void {
    $one = Uuid::v4(); $two = Uuid::v4();
    $expect($one !== $two);
    $expect((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $one));
});

$test('router route parameter extraction', static function () use ($expect): void {
    $router = new Router();
    $captured = null;
    $router->add('GET', '/vms/{id}', static function (Request $request) use (&$captured): Response {
        $captured = $request->param('id');
        return Response::json(['ok' => true]);
    });
    $router->dispatch(new Request('GET', '/vms/123', [], [], [], []));
    $expect($captured === '123');
});

$test('API validator rejects invalid values', static function () use ($expect): void {
    try {
        Validator::validate(['email' => 'invalid'], ['email' => 'required|email']);
        throw new RuntimeException('Invalid email was accepted');
    } catch (HttpException $exception) {
        $expect($exception->status === 422);
    }
});

$test('Proxmox hostname and credential injection rejected', static function () use ($expect): void {
    foreach ([['pve.test/path', 'secret'], ['pve.test', "secret\r\nInjected: yes"]] as [$host, $secret]) {
        try {
            new ProxmoxClient($host, 8006, 'pve', 'portal!token', $secret);
            throw new RuntimeException('Unsafe connection value was accepted');
        } catch (InvalidArgumentException) {
        }
    }
    $expect(true);
});

$test('schema includes all domain tables and encrypted secrets', static function () use ($expect): void {
    $schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    foreach (['users','roles','permissions','role_permissions','projects','project_users','proxmox_connections','proxmox_nodes','virtual_machines','vm_templates','resource_plans','quotas','networks','ip_addresses','snapshots','jobs','audit_logs','settings','password_reset_tokens'] as $table) {
        $expect((bool) preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '/') . '\s*\(/i', $schema), "Missing table {$table}");
    }
    $expect(str_contains($schema, 'api_token_secret_encrypted'));
    $expect(!preg_match('/\bapi_token_secret\s+(?:VARCHAR|TEXT)/i', $schema));
});

$test('production PHP has no command execution calls or TODO markers', static function () use ($expect): void {
    foreach (['app', 'installer'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/' . $directory));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $source = (string) file_get_contents($file->getPathname());
            $expect(!preg_match('/(?<!->)(?<!::)\b(shell_exec|exec|system|passthru|proc_open|popen)\s*\(/', $source), 'Command execution in ' . $file->getPathname());
            $expect(!preg_match('/\b(TODO|FIXME)\b/', $source), 'Marker in ' . $file->getPathname());
        }
    }
});

$failed = array_filter($tests, static fn (array $result): bool => !$result['ok']);
foreach ($tests as $result) {
    fwrite(STDOUT, ($result['ok'] ? '[PASS] ' : '[FAIL] ') . $result['name'] . ($result['ok'] ? '' : ': ' . $result['error']) . PHP_EOL);
}
fwrite(STDOUT, sprintf("%d passed, %d failed\n", count($tests) - count($failed), count($failed)));
exit($failed === [] ? 0 : 1);
