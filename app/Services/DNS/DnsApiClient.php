<?php

declare(strict_types=1);

namespace CloudPortal\Services\DNS;

final class DnsApiClient implements DnsApiClientInterface
{
    public function __construct(
        private readonly string $serverIp,
        private readonly string $token,
        private readonly int $port = 81,
        private readonly string $scheme = 'http',
        private readonly int $connectTimeout = 5,
        private readonly int $requestTimeout = 15,
    ) {
        if (filter_var($serverIp, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('DNS server IP is invalid.');
        }
        if ($token === '' || preg_match('/[\r\n]/', $token)) {
            throw new \InvalidArgumentException('DNS API token is invalid.');
        }
        if ($port < 1 || $port > 65535 || !in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('DNS API endpoint is invalid.');
        }
    }

    public function ensureVmRecords(string $hostname, string $ipAddress, ?string $preferredForwardZone = null): array
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \RuntimeException('Managed DNS provisioning currently requires an IPv4 address.');
        }
        $forwardZone = $this->forwardZone($preferredForwardZone);
        $reverse = $this->reverseIdentity($ipAddress);
        $reverseZone = $this->reverseZone($reverse['fqdn']);
        $fqdn = strtolower($hostname . '.' . $forwardZone);

        $a = $this->ensureRecord($forwardZone, $hostname, 'A', $ipAddress);
        $ptrOwner = $this->relativeOwner($reverse['fqdn'], $reverseZone);
        $ptr = $this->ensureRecord($reverseZone, $ptrOwner, 'PTR', $fqdn . '.');

        return [
            'fqdn' => $fqdn,
            'forward_zone' => $forwardZone,
            'reverse_zone' => $reverseZone,
            'a_record_id' => $a,
            'ptr_record_id' => $ptr,
        ];
    }

    public function verifyVmRecords(string $fqdn, string $ipAddress): void
    {
        $a = $this->request('POST', '/tools/lookup', [
            'name' => $fqdn,
            'type' => 'A',
            'server' => $this->serverIp,
        ]);
        $records = is_array($a['records'] ?? null) ? $a['records'] : [];
        if (!in_array($ipAddress, $records, true)) {
            throw new \RuntimeException('Forward DNS verification failed for ' . $fqdn . '.');
        }

        $reverse = $this->reverseIdentity($ipAddress);
        $ptr = $this->request('POST', '/tools/lookup', [
            'name' => $reverse['fqdn'],
            'type' => 'PTR',
            'server' => $this->serverIp,
        ]);
        $ptrRecords = is_array($ptr['records'] ?? null) ? $ptr['records'] : [];
        $expected = rtrim(strtolower($fqdn), '.') . '.';
        $normalized = array_map(static fn (mixed $value): string => strtolower((string) $value), $ptrRecords);
        if (!in_array($expected, $normalized, true)) {
            throw new \RuntimeException('Reverse DNS verification failed for ' . $ipAddress . '.');
        }
    }

    public function deleteRecord(string $zone, int $recordId): void
    {
        if ($recordId <= 0 || $zone === '') {
            return;
        }
        try {
            $zoneData = $this->request('GET', '/zones/' . rawurlencode($zone));
            $version = (int) ($zoneData['version'] ?? 0);
            if ($version <= 0) {
                return;
            }
            $this->request('DELETE', '/zones/' . rawurlencode($zone) . '/records/' . $recordId, null, ['zone_version' => $version]);
        } catch (DnsApiException $exception) {
            if ($exception->httpStatus !== 404) {
                throw $exception;
            }
        }
    }

    private function forwardZone(?string $preferred): string
    {
        $zones = $this->zones();
        if ($preferred !== null && trim($preferred) !== '') {
            $needle = strtolower(rtrim(trim($preferred), '.'));
            foreach ($zones as $zone) {
                if ($this->usableForward($zone) && strtolower((string) ($zone['name'] ?? '')) === $needle) {
                    return $needle;
                }
            }
            throw new \RuntimeException('Configured forward DNS zone is not available or is not managed: ' . $needle . '.');
        }

        $usable = array_values(array_filter($zones, fn (array $zone): bool => $this->usableForward($zone)));
        if (count($usable) !== 1) {
            throw new \RuntimeException('DNS forward zone is ambiguous. Configure dns.forward_zone when HomeLAB-DNS contains more than one managed forward zone.');
        }
        return strtolower((string) $usable[0]['name']);
    }

    private function reverseZone(string $reverseFqdn): string
    {
        $matches = [];
        foreach ($this->zones() as $zone) {
            if (($zone['reverse'] ?? false) !== true || ($zone['enabled'] ?? false) !== true || ($zone['managed'] ?? false) !== true) {
                continue;
            }
            $name = strtolower(rtrim((string) ($zone['name'] ?? ''), '.'));
            if ($name !== '' && ($reverseFqdn === $name || str_ends_with($reverseFqdn, '.' . $name))) {
                $matches[] = $name;
            }
        }
        if ($matches === []) {
            throw new \RuntimeException('No managed reverse DNS zone matches the reserved IP address.');
        }
        usort($matches, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        return $matches[0];
    }

    /** @return list<array<string,mixed>> */
    private function zones(): array
    {
        $response = $this->request('GET', '/zones', null, ['limit' => 200]);
        $items = $response['items'] ?? null;
        if (!is_array($items)) {
            throw new \RuntimeException('HomeLAB-DNS returned an invalid zone list.');
        }
        return array_values(array_filter($items, 'is_array'));
    }

    /** @param array<string,mixed> $zone */
    private function usableForward(array $zone): bool
    {
        return ($zone['reverse'] ?? false) !== true
            && ($zone['enabled'] ?? false) === true
            && ($zone['managed'] ?? false) === true
            && trim((string) ($zone['name'] ?? '')) !== '';
    }

    private function ensureRecord(string $zone, string $name, string $type, string $value): int
    {
        $list = $this->request('GET', '/zones/' . rawurlencode($zone) . '/records', null, ['limit' => 500]);
        foreach (is_array($list['items'] ?? null) ? $list['items'] : [] as $record) {
            if (!is_array($record)) {
                continue;
            }
            if (strtolower((string) ($record['name'] ?? '')) === strtolower($name)
                && strtoupper((string) ($record['type'] ?? '')) === $type
                && strtolower(rtrim((string) ($record['value'] ?? ''), '.')) === strtolower(rtrim($value, '.'))) {
                return (int) ($record['id'] ?? 0);
            }
        }

        $zoneData = $this->request('GET', '/zones/' . rawurlencode($zone));
        $version = (int) ($zoneData['version'] ?? 0);
        if ($version <= 0) {
            throw new \RuntimeException('HomeLAB-DNS did not return a valid zone version for ' . $zone . '.');
        }
        $created = $this->request('POST', '/zones/' . rawurlencode($zone) . '/records', [
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'ttl' => 300,
            'zone_version' => $version,
        ]);
        $id = (int) ($created['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('HomeLAB-DNS did not return the created DNS record ID.');
        }
        return $id;
    }

    /** @return array{fqdn:string} */
    private function reverseIdentity(string $ip): array
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            throw new \RuntimeException('PTR generation currently supports IPv4 only.');
        }
        return ['fqdn' => implode('.', array_reverse($parts)) . '.in-addr.arpa'];
    }

    private function relativeOwner(string $fqdn, string $zone): string
    {
        $fqdn = strtolower(rtrim($fqdn, '.'));
        $zone = strtolower(rtrim($zone, '.'));
        if ($fqdn === $zone) {
            return '@';
        }
        $suffix = '.' . $zone;
        if (!str_ends_with($fqdn, $suffix)) {
            throw new \RuntimeException('Reverse DNS owner cannot be derived from the selected reverse zone.');
        }
        return substr($fqdn, 0, -strlen($suffix));
    }

    /** @param array<string,mixed>|null $body @param array<string,mixed> $query @return array<string,mixed> */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $host = $this->serverIp;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        $url = $this->scheme . '://' . $host . ':' . $this->port . '/api/v1' . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $curl = curl_init($url);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize DNS API request.');
        }
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_PROTOCOLS => $this->scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($body !== null) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_POSTFIELDS] = $encoded;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false) {
            throw new DnsApiException('HomeLAB-DNS connection failed: ' . $curlError, 0);
        }
        $decoded = $raw === '' ? [] : json_decode((string) $raw, true, 128, JSON_THROW_ON_ERROR);
        if ($status < 200 || $status >= 300) {
            $error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $message = (string) ($error['message'] ?? 'HomeLAB-DNS API request failed.');
            throw new DnsApiException($message, $status, is_array($decoded) ? $decoded : []);
        }
        return is_array($decoded) ? $decoded : [];
    }
}

final class DnsApiException extends \RuntimeException
{
    /** @param array<string,mixed> $response */
    public function __construct(string $message, public readonly int $httpStatus, public readonly array $response = [])
    {
        parent::__construct($message);
    }
}
