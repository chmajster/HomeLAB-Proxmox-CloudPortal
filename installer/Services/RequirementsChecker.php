<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

final class RequirementsChecker
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return list<array{name:string,required:string,detected:string,status:string}> */
    public function check(): array
    {
        $checks = [];
        $checks[] = $this->row('PHP', '>= 8.3', PHP_VERSION, version_compare(PHP_VERSION, '8.3.0', '>='));
        foreach (['pdo' => 'PDO', 'pdo_mysql' => 'PDO MySQL', 'curl' => 'cURL', 'json' => 'JSON', 'openssl' => 'OpenSSL', 'mbstring' => 'Multibyte String', 'session' => 'Sessions', 'filter' => 'Filter', 'fileinfo' => 'Fileinfo', 'sodium' => 'Sodium'] as $extension => $label) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->row($label, 'Yes', $loaded ? 'Loaded' : 'Missing', $loaded);
        }
        $software = (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');
        $checks[] = $this->row('Apache', 'Apache 2.4+', $software, str_contains(strtolower($software), 'apache'));
        if (function_exists('apache_get_modules')) {
            $rewrite = in_array('mod_rewrite', apache_get_modules(), true);
            $checks[] = $this->row('mod_rewrite', 'Enabled', $rewrite ? 'Enabled' : 'Disabled', $rewrite);
        } else {
            $checks[] = ['name' => 'mod_rewrite', 'required' => 'Enabled', 'detected' => 'Detection unavailable; verify Apache configuration', 'status' => 'warning'];
        }
        $composer = is_file($this->root . '/vendor/autoload.php');
        $bundled = is_file($this->root . '/autoload.php');
        $checks[] = $this->row('Composer dependencies', 'Bundled or installed', $composer ? 'Composer autoloader found' : ($bundled ? 'Bundled release autoloader found' : 'Missing'), $composer || $bundled);
        foreach (['config', 'storage', 'storage/logs', 'storage/cache'] as $directory) {
            $path = $this->root . '/' . $directory;
            $writable = is_dir($path) && is_writable($path);
            $checks[] = $this->row($directory . ' write', 'Writable', $writable ? 'Writable' : $path . ' is not writable', $writable);
        }
        return $checks;
    }

    /** @param list<array{name:string,required:string,detected:string,status:string}>|null $checks */
    public function hasErrors(?array $checks = null): bool
    {
        foreach ($checks ?? $this->check() as $check) {
            if ($check['status'] === 'error') return true;
        }
        return false;
    }

    /** @param list<array{name:string,required:string,detected:string,status:string}>|null $checks */
    public function allPassed(?array $checks = null): bool
    {
        foreach ($checks ?? $this->check() as $check) {
            if ($check['status'] !== 'pass') return false;
        }
        return true;
    }

    /** @return array{name:string,required:string,detected:string,status:string} */
    private function row(string $name, string $required, string $detected, bool $ok): array
    {
        return compact('name', 'required', 'detected') + ['status' => $ok ? 'pass' : 'error'];
    }
}
