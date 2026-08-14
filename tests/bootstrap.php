<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require is_file($root . '/vendor/autoload.php') ? $root . '/vendor/autoload.php' : $root . '/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
