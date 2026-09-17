<?php

declare(strict_types=1);
namespace CloudPortal\Services\Infrastructure;
use CloudPortal\Http\HttpException;

final class BackendConfiguration
{
    public function __construct(private readonly string $root) {}
    public function load(): array
    {
        $path = $this->root . '/config/backend.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
        if (getenv('CP_BACKEND_URL')) $data['url'] = getenv('CP_BACKEND_URL');
        if (getenv('CP_BACKEND_TOKEN')) $data['token'] = getenv('CP_BACKEND_TOKEN');
        if (getenv('CP_BACKEND_CA_FILE')) $data['ca_file'] = getenv('CP_BACKEND_CA_FILE');
        return ['url'=>'', 'token'=>'', 'timeout'=>30, 'verify_tls'=>true, ...$data];
    }
    public function configured(): bool { return $this->load()['url'] !== ''; }
    public static function assertLocalExecutionAllowed(): void
    {
        if ((new self(dirname(__DIR__, 3)))->configured()) {
            throw new \RuntimeException('Infrastructure operations must be sent through Cloudportal-backed. Local execution is disabled.');
        }
    }
    public function save(array $data): void
    {
        new InfrastructureBackendClient($data);
        if ($data['timeout'] < 1 || $data['timeout'] > 120) throw new HttpException(422, 'Timeout: 1–120 sekund.');
        $file = tempnam($this->root . '/config', '.backend-');
        if ($file === false) throw new HttpException(500, 'Nie można zapisać konfiguracji.');
        chmod($file, 0600);
        try {
            if (file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false || !rename($file, $this->root.'/config/backend.json')) throw new HttpException(500, 'Nie można zapisać konfiguracji.');
        } finally { if (is_file($file)) unlink($file); }
    }
    public function verifySetupKey(string $key): bool
    {
        $file = $this->root . '/storage/backend-setup.token';
        return is_file($file) && hash_equals(trim((string) file_get_contents($file)), hash('sha256', $key));
    }
}
