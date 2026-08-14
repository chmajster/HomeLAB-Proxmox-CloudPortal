<?php

declare(strict_types=1);

/**
 * Runtime autoloader shipped with every release ZIP.
 * Composer remains useful for development and tests, but is not needed by the
 * administrator who installs the portal through the browser.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'CloudPortal\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $base = str_starts_with($relative, 'Installer\\')
        ? __DIR__ . '/installer/'
        : __DIR__ . '/app/';
    if (str_starts_with($relative, 'Installer\\')) {
        $relative = substr($relative, strlen('Installer\\'));
    }

    $path = $base . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
