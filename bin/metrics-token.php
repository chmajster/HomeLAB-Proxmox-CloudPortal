#!/usr/bin/env php
<?php

declare(strict_types=1);

use CloudPortal\Application;
use CloudPortal\Controllers\SystemController;

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';
$app = new Application($root);
if (!$app->installed()) {
    fwrite(STDERR, "Portal is not installed.\n");
    exit(1);
}
fwrite(STDOUT, SystemController::metricsToken($app) . PHP_EOL);
