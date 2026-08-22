#!/usr/bin/env php
<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Database\MigrationService;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';
$app = new Application($root);
if (!$app->installed()) {
    fwrite(STDERR, "Portal is not installed.\n");
    exit(1);
}
$migrations = new MigrationService($app->pdo(), $root . '/database/migrations');
$applied = $migrations->apply();
printf("Schema version: %s\n", $migrations->currentVersion() ?? 'unknown');
if ($applied !== []) {
    printf("Applied: %s\n", implode(', ', $applied));
}
