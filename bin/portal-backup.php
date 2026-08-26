#!/usr/bin/env php
<?php

declare(strict_types=1);

use CloudPortal\Application;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';

$app = new Application($root);
$command = $argv[1] ?? 'help';
$options = parseOptions(array_slice($argv, 2));

try {
    match ($command) {
        'create' => createCommand($app, $root, $options),
        'verify' => verifyCommand($options),
        'restore' => restoreCommand($app, $root, $options),
        default => usage(),
    };
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @param list<string> $arguments @return array<string,string|bool> */
function parseOptions(array $arguments): array
{
    $result = [];
    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }
        $value = substr($argument, 2);
        if (str_contains($value, '=')) {
            [$key, $optionValue] = explode('=', $value, 2);
            $result[$key] = $optionValue;
        } else {
            $result[$value] = true;
        }
    }
    return $result;
}

function usage(): never
{
    fwrite(STDOUT, <<<TXT
Algen Cloud Portal backup / disaster recovery

Usage:
  php bin/portal-backup.php create [--output=/secure/path/portal-backup.tar.gz]
  php bin/portal-backup.php verify --archive=/secure/path/portal-backup.tar.gz
  php bin/portal-backup.php restore --archive=/secure/path/portal-backup.tar.gz --force

The archive contains database credentials and encryption keys. Store it as a secret.
TXT);
    exit(0);
}

/** @param array<string,string|bool> $options */
function createCommand(Application $app, string $root, array $options): never
{
    assertInstalledFiles($root);
    $defaultDir = $root . '/storage/backups/portal';
    ensureDirectory($defaultDir);
    $output = isset($options['output']) && is_string($options['output']) && trim($options['output']) !== ''
        ? absolutePath($options['output'])
        : $defaultDir . '/cloudportal-' . gmdate('Ymd-His') . '.tar.gz';
    ensureDirectory(dirname($output));
    createArchive($app, $root, $output);
    fwrite(STDOUT, $output . PHP_EOL);
    exit(0);
}

/** @param array<string,string|bool> $options */
function verifyCommand(array $options): never
{
    $archive = requiredArchive($options);
    $temp = extractAndVerify($archive);
    removeTree($temp);
    fwrite(STDOUT, "Backup verified: {$archive}" . PHP_EOL);
    exit(0);
}

/** @param array<string,string|bool> $options */
function restoreCommand(Application $app, string $root, array $options): never
{
    if (($options['force'] ?? false) !== true) {
        throw new RuntimeException('Restore is destructive. Re-run with --force.');
    }
    assertInstalledFiles($root);
    $archive = requiredArchive($options);
    $temp = extractAndVerify($archive);

    $maintenance = $root . '/storage/maintenance.json';
    ensureDirectory(dirname($maintenance));
    file_put_contents($maintenance, json_encode([
        'reason' => 'disaster_recovery_restore',
        'started_at' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod($maintenance, 0600);

    $safetyDir = $root . '/storage/backups/portal';
    ensureDirectory($safetyDir);
    $safetyBackup = $safetyDir . '/pre-restore-' . gmdate('Ymd-His') . '.tar.gz';

    try {
        createArchive($app, $root, $safetyBackup);
        importDatabase($app, $temp . '/database.sql');
        restoreRuntimeKeepingCurrentDatabase($root, $temp . '/runtime.php');
        copyRequired($temp . '/installed.lock', $root . '/storage/installed.lock');
        @chmod($root . '/config/runtime.php', 0600);
        @chmod($root . '/storage/installed.lock', 0600);
        @unlink($maintenance);
        removeTree($temp);
        fwrite(STDOUT, "Restore completed. Pre-restore backup: {$safetyBackup}" . PHP_EOL);
        exit(0);
    } catch (Throwable $exception) {
        removeTree($temp);
        throw new RuntimeException(
            $exception->getMessage() . ' Maintenance mode remains active. Pre-restore backup: ' . $safetyBackup,
            0,
            $exception,
        );
    }
}

function createArchive(Application $app, string $root, string $output): void
{
    assertInstalledFiles($root);
    $temp = tempDirectory('algen-backup-create-');
    try {
        dumpDatabase($app, $temp . '/database.sql');
        copyRequired($root . '/config/runtime.php', $temp . '/runtime.php');
        copyRequired($root . '/storage/installed.lock', $temp . '/installed.lock');

        $manifest = [
            'format' => 1,
            'created_at' => gmdate(DATE_ATOM),
            'application_version' => Application::VERSION,
            'files' => [],
        ];
        foreach (['database.sql', 'runtime.php', 'installed.lock'] as $file) {
            $manifest['files'][$file] = [
                'sha256' => hash_file('sha256', $temp . '/' . $file),
                'size' => filesize($temp . '/' . $file),
            ];
        }
        file_put_contents(
            $temp . '/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX,
        );

        if (is_file($output) && !@unlink($output)) {
            throw new RuntimeException('Cannot replace existing backup archive: ' . $output);
        }
        runProcess([
            'tar', '-C', $temp, '-czf', $output,
            'database.sql', 'runtime.php', 'installed.lock', 'manifest.json',
        ]);
        @chmod($output, 0600);
    } finally {
        removeTree($temp);
    }
}

function dumpDatabase(Application $app, string $target): void
{
    $defaults = mysqlDefaultsFile($app);
    try {
        runProcess([
            'mysqldump',
            '--defaults-extra-file=' . $defaults,
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--set-gtid-purged=OFF',
            '--result-file=' . $target,
            (string) $app->config->get('database.name'),
        ]);
        if (!is_file($target) || filesize($target) <= 0) {
            throw new RuntimeException('mysqldump produced an empty database dump.');
        }
        @chmod($target, 0600);
    } finally {
        @unlink($defaults);
    }
}

function importDatabase(Application $app, string $dump): void
{
    if (!is_file($dump) || filesize($dump) <= 0) {
        throw new RuntimeException('Backup database.sql is missing or empty.');
    }
    $defaults = mysqlDefaultsFile($app);
    try {
        runProcess([
            'mysql',
            '--defaults-extra-file=' . $defaults,
            (string) $app->config->get('database.name'),
        ], $dump);
    } finally {
        @unlink($defaults);
    }
}

function mysqlDefaultsFile(Application $app): string
{
    $path = tempnam(sys_get_temp_dir(), 'algen-mysql-');
    if ($path === false) {
        throw new RuntimeException('Cannot create temporary MySQL client configuration.');
    }
    $escape = static fn (string $value): string => addcslashes($value, "\\\"\n\r\t");
    $content = "[client]\n"
        . 'host="' . $escape((string) $app->config->get('database.host')) . "\"\n"
        . 'port=' . (int) $app->config->get('database.port') . "\n"
        . 'user="' . $escape((string) $app->config->get('database.user')) . "\"\n"
        . 'password="' . $escape((string) $app->config->get('database.password')) . "\"\n"
        . "default-character-set=utf8mb4\n";
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        @unlink($path);
        throw new RuntimeException('Cannot write temporary MySQL client configuration.');
    }
    @chmod($path, 0600);
    return $path;
}

function extractAndVerify(string $archive): string
{
    if (!is_file($archive) || !is_readable($archive)) {
        throw new RuntimeException('Backup archive is not readable: ' . $archive);
    }
    $temp = tempDirectory('algen-backup-verify-');
    try {
        runProcess(['tar', '-xzf', $archive, '-C', $temp]);
        $manifestPath = $temp . '/manifest.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('Backup manifest.json is missing.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        if ((int) ($manifest['format'] ?? 0) !== 1 || !is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Unsupported or invalid backup manifest.');
        }
        foreach (['database.sql', 'runtime.php', 'installed.lock'] as $file) {
            $path = $temp . '/' . $file;
            $expected = (string) ($manifest['files'][$file]['sha256'] ?? '');
            if (!is_file($path) || $expected === '' || !hash_equals($expected, (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Backup integrity check failed for ' . $file . '.');
            }
        }
        return $temp;
    } catch (Throwable $exception) {
        removeTree($temp);
        throw $exception;
    }
}

function restoreRuntimeKeepingCurrentDatabase(string $root, string $backupRuntime): void
{
    $currentPath = $root . '/config/runtime.php';
    $current = require $currentPath;
    $restored = require $backupRuntime;
    if (!is_array($current) || !is_array($restored) || !is_array($current['database'] ?? null)) {
        throw new RuntimeException('Runtime configuration is invalid.');
    }

    // Restore encryption/application keys and portal settings, but keep the
    // database endpoint/credentials of the recovery target.
    $restored['database'] = $current['database'];
    $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($restored, true) . ";\n";
    $temporary = $currentPath . '.restore-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write restored runtime configuration.');
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $currentPath)) {
        @unlink($temporary);
        throw new RuntimeException('Cannot atomically replace runtime configuration.');
    }
}

function assertInstalledFiles(string $root): void
{
    foreach ([$root . '/config/runtime.php', $root . '/storage/installed.lock'] as $path) {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Required installed portal file is missing: ' . $path);
        }
    }
}

/** @param array<string,string|bool> $options */
function requiredArchive(array $options): string
{
    $value = $options['archive'] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('--archive is required.');
    }
    return absolutePath($value);
}

function absolutePath(string $path): string
{
    if ($path === '') {
        throw new RuntimeException('Path cannot be empty.');
    }
    if ($path[0] === '/') {
        return $path;
    }
    return getcwd() . '/' . $path;
}

function copyRequired(string $source, string $destination): void
{
    if (!is_file($source) || !@copy($source, $destination)) {
        throw new RuntimeException('Cannot copy required backup file: ' . $source);
    }
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create directory: ' . $path);
    }
}

function tempDirectory(string $prefix): string
{
    $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(8));
    ensureDirectory($path);
    @chmod($path, 0700);
    return $path;
}

/** @param list<string> $command */
function runProcess(array $command, ?string $stdinFile = null): void
{
    $descriptors = [
        0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot execute required command: ' . $command[0]);
    }
    if ($stdinFile === null && isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[1]) && is_resource($pipes[1])) fclose($pipes[1]);
    if (isset($pipes[2]) && is_resource($pipes[2])) fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        $detail = trim((string) $stderr);
        if ($detail === '') $detail = trim((string) $stdout);
        throw new RuntimeException($command[0] . ' failed with exit code ' . $exit . ($detail === '' ? '.' : ': ' . mb_substr($detail, 0, 1000)));
    }
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $target = $path . '/' . $item;
        if (is_dir($target) && !is_link($target)) removeTree($target);
        else @unlink($target);
    }
    @rmdir($path);
}
