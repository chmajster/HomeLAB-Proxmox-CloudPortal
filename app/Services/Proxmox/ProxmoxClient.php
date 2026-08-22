<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

final class ProxmoxClient implements ProxmoxClientInterface, ProxmoxFileUploadInterface
{
    public function __construct(
        private readonly string $hostname,
        private readonly int $port,
        private readonly string $realm,
        private readonly string $tokenId,
        private readonly string $tokenSecret,
        private readonly bool $verifySsl = true,
        private readonly int $connectTimeout = 10,
        private readonly int $requestTimeout = 60,
    ) {
        $candidate = preg_replace('#^https?://#i', '', rtrim($hostname, '/'));
        $bareCandidate = str_starts_with((string) $candidate, '[') && str_ends_with((string) $candidate, ']') ? substr((string) $candidate, 1, -1) : $candidate;
        $validIp = filter_var($bareCandidate, FILTER_VALIDATE_IP) !== false;
        $validHostname = preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/', (string) $candidate) === 1;
        if ($candidate === '' || preg_match('/[\r\n]/', (string) $candidate) || (!$validIp && !$validHostname)) {
            throw new \InvalidArgumentException('Invalid Proxmox hostname.');
        }
        if (preg_match('/[\r\n]/', $realm . $tokenId . $tokenSecret)) {
            throw new \InvalidArgumentException('Invalid Proxmox credential value.');
        }
        if ($port < 1 || $port > 65535 || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $realm) !== 1 || preg_match('/^[A-Za-z0-9._-]+(?:@[A-Za-z0-9._-]+)?![A-Za-z0-9._-]+$/', $tokenId) !== 1 || $tokenSecret === '') {
            throw new \InvalidArgumentException('Invalid Proxmox connection parameters.');
        }
    }

    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $data = []): mixed
    {
        return $this->request('POST', $path, $data);
    }

    public function put(string $path, array $data = []): mixed
    {
        return $this->request('PUT', $path, $data);
    }

    public function delete(string $path, array $data = []): mixed
    {
        return $this->request('DELETE', $path, $data);
    }

    public function uploadIso(string $node, string $storage, string $path, string $filename): mixed
    {
        if (!$this->validResourceId($node) || !$this->validResourceId($storage)) {
            throw new \InvalidArgumentException('Invalid Proxmox upload target.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.iso$/i', $filename) !== 1) {
            throw new \InvalidArgumentException('Invalid ISO filename.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('ISO upload source is not readable.');
        }

        $url = $this->baseUrl() . '/nodes/' . rawurlencode($node) . '/storage/' . rawurlencode($storage) . '/upload';
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ProxmoxException('Unable to initialize cURL.');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => max(3600, $this->requestTimeout),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: PVEAPIToken=' . $this->normalizedTokenId() . '=' . $this->tokenSecret,
            ],
            CURLOPT_POSTFIELDS => [
                'content' => 'iso',
                'filename' => new \CURLFile($path, 'application/octet-stream', $filename),
            ],
        ]);
        return $this->execute($curl);
    }

    public function waitForTask(string $node, string $upid, int $timeoutSeconds = 900): array
    {
        $deadline = time() + $timeoutSeconds;
        $path = '/nodes/' . rawurlencode($node) . '/tasks/' . rawurlencode($upid) . '/status';
        do {
            $status = $this->get($path);
            if (!is_array($status)) {
                throw new ProxmoxException('Unexpected Proxmox task status response.');
            }
            if (($status['status'] ?? null) === 'stopped') {
                if (($status['exitstatus'] ?? null) !== 'OK') {
                    throw new ProxmoxException('Proxmox task failed: ' . (string) ($status['exitstatus'] ?? 'unknown'), 0, $status);
                }
                return $status;
            }
            sleep(2);
        } while (time() < $deadline);

        throw new ProxmoxException('Timed out waiting for Proxmox task.');
    }

    private function request(string $method, string $path, array $data): mixed
    {
        if (!str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid Proxmox API path.');
        }
        $url = $this->baseUrl() . $path;
        if ($method === 'GET' && $data !== []) {
            $url .= '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        }
        $token = $this->normalizedTokenId();
        $curl = curl_init($url);
        if ($curl === false) {
            throw new ProxmoxException('Unable to initialize cURL.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: PVEAPIToken=' . $token . '=' . $this->tokenSecret,
            ],
        ];
        if ($method !== 'GET' && $data !== []) {
            $containsArray = false;
            foreach ($data as $value) {
                if (is_array($value)) {
                    $containsArray = true;
                    break;
                }
            }
            if ($containsArray) {
                $options[CURLOPT_POSTFIELDS] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            } else {
                $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
            }
        }
        curl_setopt_array($curl, $options);
        return $this->execute($curl);
    }

    /** @param \CurlHandle|resource $curl */
    private function execute(mixed $curl): mixed
    {
        $raw = curl_exec($curl);
        $curlCode = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false) {
            throw new ProxmoxException('Proxmox connection failed: ' . $error, 0, null, $curlCode);
        }
        try {
            $decoded = json_decode((string) $raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProxmoxException('Proxmox returned invalid JSON.', $status);
        }
        if ($status < 200 || $status >= 300) {
            $safeResponse = is_array($decoded) ? $this->redactCredentials($decoded) : null;
            $message = is_array($safeResponse) ? (string) ($safeResponse['message'] ?? 'Proxmox API request failed.') : 'Proxmox API request failed.';
            throw new ProxmoxException($message, $status, $safeResponse);
        }
        return is_array($decoded) ? ($decoded['data'] ?? null) : null;
    }

    private function baseUrl(): string
    {
        $host = preg_replace('#^https?://#i', '', rtrim(trim($this->hostname), '/'));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        return 'https://' . rtrim((string) $host, '/') . ':' . $this->port . '/api2/json';
    }

    private function validResourceId(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $value) === 1;
    }

    private function normalizedTokenId(): string
    {
        if (str_contains($this->tokenId, '@')) {
            return $this->tokenId;
        }
        if (str_contains($this->tokenId, '!')) {
            [$user, $token] = explode('!', $this->tokenId, 2);
            return $user . '@' . $this->realm . '!' . $token;
        }
        throw new \InvalidArgumentException('Token ID must use user!token or user@realm!token format.');
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function redactCredentials(array $response): array
    {
        array_walk_recursive($response, function (mixed &$value): void {
            if (!is_string($value)) return;
            $value = str_replace($this->tokenSecret, '[ukryto]', $value);
            $value = preg_replace('/PVEAPIToken=[^\s,;]+/i', 'PVEAPIToken=[ukryto]', $value) ?? '';
        });
        return $response;
    }
}
