#!/usr/bin/env php
<?php

declare(strict_types=1);

use CloudPortal\Application;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';
$app = new Application($root);
if (!$app->installed()) {
    fwrite(STDERR, "Portal is not installed.\n");
    exit(1);
}

$command = (string) ($argv[1] ?? '');

if ($command === 'version') {
    fwrite(STDOUT, Application::VERSION . PHP_EOL);
    exit(0);
}

if ($command === 'database-name') {
    $name = trim((string) $app->config->get('database.name', ''));
    if ($name === '' || preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name) !== 1) {
        fwrite(STDERR, "Configured database name is invalid for the updater.\n");
        exit(2);
    }
    fwrite(STDOUT, $name . PHP_EOL);
    exit(0);
}

if ($command === 'pending-jobs') {
    $count = (int) $app->pdo()->query("SELECT COUNT(*) FROM jobs WHERE status IN ('queued','running')")->fetchColumn();
    fwrite(STDOUT, (string) $count . PHP_EOL);
    exit(0);
}

if ($command === 'mysql-config') {
    $target = (string) ($argv[2] ?? '');
    if ($target === '' || str_contains($target, "\0")) {
        fwrite(STDERR, "mysql-config requires a target path.\n");
        exit(2);
    }
    $directory = dirname($target);
    if (!is_dir($directory) || !is_writable($directory) || is_link($target) || file_exists($target)) {
        fwrite(STDERR, "Refusing unsafe MySQL client config target.\n");
        exit(2);
    }

    $escape = static function (mixed $value): string {
        return str_replace(
            ["\\", "\"", "\n", "\r", "\0"],
            ["\\\\", "\\\"", "\\n", "\\r", ""],
            (string) $value,
        );
    };
    $host = trim((string) $app->config->get('database.host', ''));
    $port = (int) $app->config->get('database.port', 3306);
    $user = (string) $app->config->get('database.user', '');
    $password = (string) $app->config->get('database.password', '');
    if ($host === '' || $port < 1 || $port > 65535 || $user === '') {
        fwrite(STDERR, "Database runtime configuration is incomplete.\n");
        exit(2);
    }

    $contents = "[client]\n"
        . 'host="' . $escape($host) . "\"\n"
        . 'port=' . $port . "\n"
        . 'user="' . $escape($user) . "\"\n"
        . 'password="' . $escape($password) . "\"\n"
        . "protocol=tcp\n";

    $handle = @fopen($target, 'x');
    if ($handle === false) {
        fwrite(STDERR, "Could not create MySQL client config.\n");
        exit(2);
    }
    $ok = false;
    try {
        $ok = flock($handle, LOCK_EX)
            && fwrite($handle, $contents) === strlen($contents)
            && fflush($handle);
    } finally {
        fclose($handle);
    }
    if (!$ok || (DIRECTORY_SEPARATOR !== '\\' && !@chmod($target, 0600))) {
        @unlink($target);
        fwrite(STDERR, "Could not protect MySQL client config.\n");
        exit(2);
    }
    exit(0);
}

fwrite(STDERR, "Usage: php bin/update-helper.php version|database-name|pending-jobs|mysql-config <path>\n");
exit(2);
