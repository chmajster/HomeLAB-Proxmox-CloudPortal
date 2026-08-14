<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

final class InstallationLock
{
    public function __construct(private readonly string $path)
    {
    }

    public function create(string $installId, string $version): void
    {
        if (is_file($this->path)) {
            $lock = json_decode((string) file_get_contents($this->path), true);
            if (is_array($lock) && hash_equals($installId, (string) ($lock['install_id'] ?? ''))) {
                if (!SensitiveFilePermissions::areSafe($this->path)) throw new \RuntimeException('The installation lock has unsafe POSIX permissions. Set storage/installed.lock to 0600 and retry.');
                return;
            }
            throw new \RuntimeException('An installation lock already exists for another installation.');
        }
        $handle = @fopen($this->path, 'x');
        if ($handle === false) throw new \RuntimeException('Cannot create storage/installed.lock. Make the storage directory writable and retry.');
        $payload = json_encode(['install_id' => $installId, 'version' => $version, 'installed_at' => gmdate('c')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        $success = false;
        try {
            $success = flock($handle, LOCK_EX) && fwrite($handle, $payload) === strlen($payload) && fflush($handle) && SensitiveFilePermissions::protect($this->path);
        } finally {
            fclose($handle);
        }
        if (!$success) {
            @unlink($this->path);
            throw new \RuntimeException('Cannot safely persist the installation lock.');
        }
    }
}
