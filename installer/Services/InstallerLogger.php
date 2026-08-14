<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

final class InstallerLogger
{
    public function __construct(private readonly string $path)
    {
    }

    /** @param list<string> $secrets */
    public function error(string $stage, \Throwable $exception, array $secrets = []): void
    {
        $message = $exception->getMessage();
        foreach ($secrets as $secret) {
            if ($secret !== '') $message = str_replace($secret, '[REDACTED]', $message);
        }
        $message = preg_replace('/(password|secret|token|app[_ -]?key)\s*[=:]\s*[^\s,;]+/i', '$1=[REDACTED]', $message) ?? 'Installer error';
        $line = sprintf("[%s] stage=%s error=%s\n", gmdate('c'), preg_replace('/[^a-z0-9_.-]/i', '', $stage), str_replace(["\r", "\n"], ' ', mb_substr($message, 0, 2000)));
        if (@file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX) !== false) @chmod($this->path, 0600);
    }
}
