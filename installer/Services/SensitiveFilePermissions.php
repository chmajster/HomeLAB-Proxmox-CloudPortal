<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

final class SensitiveFilePermissions
{
    public static function protect(string $path): bool
    {
        @chmod($path, 0600);
        clearstatcache(true, $path);
        return self::areSafe($path);
    }

    public static function areSafe(string $path): bool
    {
        clearstatcache(true, $path);
        if (!is_file($path) || !is_readable($path)) return false;
        if (self::usesWindowsAcl($path)) return true;
        $permissions = @fileperms($path);
        return $permissions !== false && ($permissions & 0777) === 0600;
    }

    public static function usesWindowsAcl(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') return true;

        // WSL exposes Windows drives through DrvFS. chmod() may report success
        // there while POSIX mode bits remain 0777; access is governed by NTFS ACLs.
        $release = strtolower(php_uname('r'));
        $resolved = str_replace('\\', '/', realpath($path) ?: $path);
        return str_contains($release, 'microsoft')
            && preg_match('#^/mnt/[a-z](?:/|$)#i', $resolved) === 1;
    }
}
