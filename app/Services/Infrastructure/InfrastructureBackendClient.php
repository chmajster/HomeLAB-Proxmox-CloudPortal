<?php

declare(strict_types=1);
namespace CloudPortal\Services\Infrastructure;

use CloudPortal\Http\HttpException;

final class InfrastructureBackendClient
{
    public function __construct(private readonly array $config, private readonly ?string $token = null, private readonly ?\Closure $transport = null)
    {
        $url = parse_url((string) ($config['url'] ?? ''));
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || (isset($url['user']) || isset($url['pass'])) || isset($url['query']) || isset($url['fragment']) || !in_array($url['path'] ?? '', ['', '/'], true)) {
            throw new HttpException(422, 'Backend URL musi wskazywać adres HTTPS bez ścieżki i danych logowania.');
        }
    }

    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, bool $allowUnavailable = false): array
    {
        if (!preg_match('#^/[a-z0-9/_-]+(?:\?[a-zA-Z0-9_=&%.-]+)?$#D', $path) || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid backend API path.');
        }
        $requestId = $_SERVER['CLOUD_PORTAL_REQUEST_ID'] ?? self::uuid();
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'X-Request-ID: ' . $requestId];
        if ($this->token !== null) {
            if (!preg_match('/^cp_[A-Za-z0-9_-]{32,128}$/D', $this->token)) throw new HttpException(401, 'Nieprawidłowy token backendu.');
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        if ($idempotencyKey !== null) {
            if (!preg_match('/^[0-9a-f-]{36}$/D', $idempotencyKey)) throw new HttpException(422, 'Nieprawidłowy klucz idempotencji.');
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $url = rtrim($this->config['url'], '/') . '/api/v1' . $path;
        if ($this->transport !== null) {
            [$status, $raw] = ($this->transport)($method, $url, $headers, $body);
        } else {
            if (!function_exists('curl_init')) throw new HttpException(503, 'Wymagane rozszerzenie PHP cURL.');
            $curl = curl_init($url);
            $raw = '';
            $tooLarge = false;
            curl_setopt_array($curl, [
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => min(5, (int) ($this->config['timeout'] ?? 30)),
                CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 30),
                CURLOPT_SSL_VERIFYPEER => (bool) ($this->config['verify_tls'] ?? true),
                CURLOPT_SSL_VERIFYHOST => ($this->config['verify_tls'] ?? true) ? 2 : 0,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$raw, &$tooLarge): int {
                    if (strlen($raw) + strlen($chunk) > 4 * 1024 * 1024) { $tooLarge = true; return 0; }
                    $raw .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (!empty($this->config['ca_file'])) curl_setopt($curl, CURLOPT_CAINFO, $this->config['ca_file']);
            if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            $ok = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($ok === false || $tooLarge) throw new HttpException(503, 'Backend niedostępny, błąd TLS albo przekroczony limit odpowiedzi.');
        }
        try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpException(502, 'Backend zwrócił nieprawidłowy JSON.'); }
        if (!is_array($data)) throw new HttpException(502, 'Nieprawidłowa odpowiedź backendu.');
        if ($status < 200 || $status >= 300) {
            if ($allowUnavailable && $status === 503 && isset($data['checks'])) return $data;
            $detail = $data['detail'] ?? 'Błąd backendu.';
            if (is_array($detail)) $detail = implode('; ', array_map(static fn ($e) => implode('.', $e['loc'] ?? []) . ': ' . ($e['msg'] ?? 'Validation error'), $detail));
            throw new HttpException(in_array($status, [400,401,403,404,409,413,422,429,503], true) ? $status : 502, (string) $detail);
        }
        return $data;
    }

    public static function uuid(): string
    {
        $b = random_bytes(16); $b[6] = chr((ord($b[6]) & 15) | 64); $b[8] = chr((ord($b[8]) & 63) | 128);
        $h = bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    private function id(int|string $id): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', (string) $id)) throw new \InvalidArgumentException('Invalid resource ID.');
        return (string) $id;
    }
    public function login(string $username, string $password): array { return $this->request('POST', '/auth/login', compact('username','password')); }
    public function logout(): array { return $this->request('POST', '/auth/logout'); }
    public function refresh(string $refreshToken): array { return $this->request('POST', '/auth/refresh', ['refresh_token'=>$refreshToken]); }
    public function getCurrentUser(): array { return $this->request('GET', '/auth/me'); }
    public function getHealth(): array { return $this->request('GET', '/health', allowUnavailable:true); }
    public function getPermissions(): array { return $this->request('GET', '/permissions'); }
    public function assignRoles(int $id, array $roles): array { return $this->request('PUT', '/users/'.$this->id($id).'/roles', ['role_ids'=>$roles]); }
    public function getUserRoles(int $id): array { return $this->request('GET', '/users/'.$this->id($id).'/roles'); }
    public function getTemplates(): array { return $this->request('GET', '/templates'); }
    public function getPlaybooks(): array { return $this->request('GET', '/ansible/playbooks'); }
    public function testCredential(int $id): array { return $this->request('POST', '/credentials/'.$this->id($id).'/test'); }
    public function revokeToken(int $id): array { return $this->request('POST', '/tokens/'.$this->id($id).'/revoke'); }
    public function getJobLogs(string $id, int $after=0): array { return $this->request('GET', '/jobs/'.$this->id($id).'/logs?after='.max(0,$after).'&limit=200'); }
    public function cancelJob(string $id): array { return $this->request('POST', '/jobs/'.$this->id($id).'/cancel'); }
    public function destroyDeployment(string $id, string $key): array { return $this->request('POST', '/deployments/'.$this->id($id).'/destroy', [], $key); }
    public function getUsers(int $offset=0): array { return $this->request('GET', '/users?offset='.max(0,$offset).'&limit=100'); }
    public function getUser(int|string $id): array { return $this->request('GET', '/users/'.$this->id($id)); }
    public function createUser(array $data, string $key): array { return $this->request('POST', '/users', $data, $key); }
    public function updateUser(int $id, array $data): array { return $this->request('PUT', '/users/'.$this->id($id), $data); }
    public function deleteUser(int $id): array { return $this->request('DELETE', '/users/'.$this->id($id)); }
    public function getRoles(int $offset=0): array { return $this->request('GET', '/roles?offset='.max(0,$offset).'&limit=100'); }
    public function getRole(int|string $id): array { return $this->request('GET', '/roles/'.$this->id($id)); }
    public function createRole(array $data, string $key): array { return $this->request('POST', '/roles', $data, $key); }
    public function updateRole(int $id, array $data): array { return $this->request('PUT', '/roles/'.$this->id($id), $data); }
    public function deleteRole(int $id): array { return $this->request('DELETE', '/roles/'.$this->id($id)); }
    public function getTokens(int $offset=0): array { return $this->request('GET', '/tokens?offset='.max(0,$offset).'&limit=100'); }
    public function getToken(int|string $id): array { return $this->request('GET', '/tokens/'.$this->id($id)); }
    public function createToken(array $data, string $key): array { return $this->request('POST', '/tokens', $data, $key); }
    public function getCredentials(int $offset=0): array { return $this->request('GET', '/credentials?offset='.max(0,$offset).'&limit=100'); }
    public function getCredential(int|string $id): array { return $this->request('GET', '/credentials/'.$this->id($id)); }
    public function createCredential(array $data, string $key): array { return $this->request('POST', '/credentials', $data, $key); }
    public function updateCredential(int $id, array $data): array { return $this->request('PUT', '/credentials/'.$this->id($id), $data); }
    public function deleteCredential(int $id): array { return $this->request('DELETE', '/credentials/'.$this->id($id)); }
    public function getProviders(int $offset=0): array { return $this->request('GET', '/providers?offset='.max(0,$offset).'&limit=100'); }
    public function getProvider(int|string $id): array { return $this->request('GET', '/providers/'.$this->id($id)); }
    public function createProvider(array $data, string $key): array { return $this->request('POST', '/providers', $data, $key); }
    public function updateProvider(int $id, array $data): array { return $this->request('PUT', '/providers/'.$this->id($id), $data); }
    public function deleteProvider(int $id): array { return $this->request('DELETE', '/providers/'.$this->id($id)); }
    public function getDeployments(int $offset=0): array { return $this->request('GET', '/deployments?offset='.max(0,$offset).'&limit=100'); }
    public function getDeployment(int|string $id): array { return $this->request('GET', '/deployments/'.$this->id($id)); }
    public function createDeployment(array $data, string $key): array { return $this->request('POST', '/deployments', $data, $key); }
    public function getJobs(int $offset=0): array { return $this->request('GET', '/jobs?offset='.max(0,$offset).'&limit=100'); }
    public function getJob(int|string $id): array { return $this->request('GET', '/jobs/'.$this->id($id)); }
    public function createJob(array $data, string $key): array { return $this->request('POST', '/jobs', $data, $key); }
    public function getProviderNodes(int $id): array { return $this->request('GET', '/providers/'.$this->id($id).'/nodes'); }
    public function getProviderStorages(int $id): array { return $this->request('GET', '/providers/'.$this->id($id).'/storages'); }
    public function getProviderNetworks(int $id): array { return $this->request('GET', '/providers/'.$this->id($id).'/networks'); }
    public function getProviderTemplates(int $id): array { return $this->request('GET', '/providers/'.$this->id($id).'/templates'); }
    public function getProviderVms(int $id): array { return $this->request('GET', '/providers/'.$this->id($id).'/vms'); }
    public function getProviderPools(int $id): array { return $this->request('GET', '/providers/'.$this->id($id).'/pools'); }
}
