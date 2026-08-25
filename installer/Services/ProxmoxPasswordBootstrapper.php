<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

final class ProxmoxPasswordBootstrapper
{
    /** @param array<string,mixed> $config @return array{cluster:string,nodes:int,version:string,storages:int,username:string} */
    public function test(array $config): array
    {
        $session = $this->authenticate($config);
        return $this->inspect($config, $session);
    }

    /** @param array<string,mixed> $config @return array{token_id:string,token_secret:string,token_name:string,username:string} */
    public function createToken(array $config, bool $replaceExisting = false): array
    {
        // Password bootstrap owns the configured token name. If it already exists,
        // replace it in the same request instead of requiring a second confirmation.
        $replaceExisting = true;

        $session = $this->authenticate($config);
        $username = (string) $session['username'];
        $tokenName = (string) ($config['token_name'] ?? 'cloudportal');
        $userPath = '/access/users/' . rawurlencode($username) . '/token';
        $path = $userPath . '/' . rawurlencode($tokenName);

        if ($this->tokenExists($config, $session, $userPath, $tokenName)) {
            if (!$replaceExisting) {
                throw new \RuntimeException(
                    'Token API o nazwie „' . $tokenName . '” już istnieje dla użytkownika ' . $username . '.',
                    409,
                );
            }

            try {
                $this->request($config, 'DELETE', $path, [], $session);
            } catch (\RuntimeException $exception) {
                throw new \RuntimeException(
                    'Nie udało się usunąć istniejącego tokenu API „' . $username . '!' . $tokenName . '”. ' . $exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }

        try {
            $result = $this->request(
                $config,
                'POST',
                $path,
                ['privsep' => 0, 'comment' => 'Algen Cloud Portal'],
                $session,
            );
        } catch (\RuntimeException $exception) {
            $message = mb_strtolower($exception->getMessage());
            if (str_contains($message, 'already exists') || str_contains($message, 'value already exists')) {
                throw new \RuntimeException(
                    'Token API o nazwie „' . $tokenName . '” już istnieje dla użytkownika ' . $username . '.',
                    409,
                    $exception,
                );
            }
            throw new \RuntimeException('Nie udało się utworzyć tokenu API Proxmox. Konto musi mieć prawo utworzenia tokenu dla użytkownika ' . $username . '. ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($result) || !is_string($result['value'] ?? null) || trim((string) $result['value']) === '') {
            throw new \RuntimeException('Proxmox utworzył token, ale nie zwrócił jego sekretu. Usuń utworzony token i spróbuj ponownie.');
        }

        $fullTokenId = is_string($result['full-tokenid'] ?? null) && trim((string) $result['full-tokenid']) !== ''
            ? (string) $result['full-tokenid']
            : $username . '!' . $tokenName;

        return [
            'token_id' => $fullTokenId,
            'token_secret' => (string) $result['value'],
            'token_name' => $tokenName,
            'username' => $username,
        ];
    }

    /** @param array<string,mixed> $config */
    public function deleteTokenBestEffort(array $config, string $username, string $tokenName): void
    {
        try {
            $session = $this->authenticate($config);
            $path = '/access/users/' . rawurlencode($username) . '/token/' . rawurlencode($tokenName);
            $this->request($config, 'DELETE', $path, [], $session);
        } catch (\Throwable) {
            // Cleanup is best effort. Never hide the original installer error.
        }
    }

    /**
     * @param array<string,mixed> $config
     * @param array{ticket:string,csrf:string,username:string} $session
     */
    private function tokenExists(array $config, array $session, string $userPath, string $tokenName): bool
    {
        $tokens = $this->request($config, 'GET', $userPath, [], $session);
        if (!is_array($tokens)) {
            throw new \RuntimeException('Proxmox nie zwrócił listy tokenów użytkownika ' . $session['username'] . '.');
        }

        foreach ($tokens as $token) {
            if (!is_array($token)) continue;
            if (is_string($token['tokenid'] ?? null) && hash_equals((string) $token['tokenid'], $tokenName)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $config @return array{ticket:string,csrf:string,username:string} */
    private function authenticate(array $config): array
    {
        $login = (string) ($config['username'] ?? '');
        $realm = (string) ($config['realm'] ?? 'pve');
        $username = str_contains($login, '@') ? $login : $login . '@' . $realm;

        $result = $this->request($config, 'POST', '/access/ticket', [
            'username' => $username,
            'password' => (string) ($config['password'] ?? ''),
        ]);

        if (!is_array($result)) {
            throw new \RuntimeException('Proxmox odrzucił login lub hasło. Sprawdź dane logowania oraz wybrany realm.');
        }
        if (isset($result['NeedTFA'])) {
            throw new \RuntimeException('Konto Proxmox wymaga uwierzytelniania dwuskładnikowego (2FA). Automatyczne tworzenie tokenu z samego loginu i hasła nie jest możliwe dla tego konta — użyj istniejącego tokenu API.');
        }
        if (!is_string($result['ticket'] ?? null) || !is_string($result['CSRFPreventionToken'] ?? null)) {
            throw new \RuntimeException('Proxmox nie zwrócił poprawnego biletu logowania. Sprawdź login, hasło i realm.');
        }

        return [
            'ticket' => (string) $result['ticket'],
            'csrf' => (string) $result['CSRFPreventionToken'],
            'username' => is_string($result['username'] ?? null) ? (string) $result['username'] : $username,
        ];
    }

    /** @param array<string,mixed> $config @param array{ticket:string,csrf:string,username:string} $session @return array{cluster:string,nodes:int,version:string,storages:int,username:string} */
    private function inspect(array $config, array $session): array
    {
        $clusterStatus = $this->request($config, 'GET', '/cluster/status', [], $session);
        $nodes = $this->request($config, 'GET', '/nodes', [], $session);
        $version = $this->request($config, 'GET', '/version', [], $session);
        $storages = $this->request($config, 'GET', '/storage', [], $session);

        if (!is_array($clusterStatus) || !is_array($nodes) || !is_array($version) || !is_array($storages)) {
            throw new \RuntimeException('Logowanie działa, ale wymagane zasoby API Proxmox zwróciły nieprawidłową odpowiedź.');
        }

        $clusterName = (string) ($config['hostname'] ?? 'Proxmox');
        foreach ($clusterStatus as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'cluster' && !empty($entry['name'])) {
                $clusterName = (string) $entry['name'];
                break;
            }
        }

        return [
            'cluster' => mb_substr($clusterName, 0, 100),
            'nodes' => count($nodes),
            'version' => mb_substr((string) ($version['version'] ?? 'Unknown'), 0, 40),
            'storages' => count($storages),
            'username' => $session['username'],
        ];
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $data
     * @param null|array{ticket:string,csrf:string,username:string} $session
     */
    private function request(array $config, string $method, string $path, array $data = [], ?array $session = null): mixed
    {
        $host = preg_replace('#^https?://#i', '', rtrim(trim((string) ($config['hostname'] ?? '')), '/'));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) $host = '[' . $host . ']';
        $url = 'https://' . $host . ':' . (int) ($config['port'] ?? 8006) . '/api2/json' . $path;
        $curl = curl_init($url);
        if ($curl === false) throw new \RuntimeException('Nie udało się zainicjalizować połączenia cURL do Proxmox.');

        $headers = ['Accept: application/json'];
        if ($session !== null) {
            $headers[] = 'Cookie: PVEAuthCookie=' . $session['ticket'];
            if ($method !== 'GET') $headers[] = 'CSRFPreventionToken: ' . $session['csrf'];
        }
        if ($method !== 'GET') $headers[] = 'Content-Type: application/x-www-form-urlencoded';

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => (bool) ($config['verify_ssl'] ?? true),
            CURLOPT_SSL_VERIFYHOST => (bool) ($config['verify_ssl'] ?? true) ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method !== 'GET' && $data !== []) {
            $options[CURLOPT_POSTFIELDS] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
        }
        curl_setopt_array($curl, $options);

        $raw = curl_exec($curl);
        $curlCode = curl_errno($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($raw === false) {
            throw new \RuntimeException('Nie można połączyć się z Proxmox: ' . ($curlError !== '' ? $curlError : 'błąd cURL ' . $curlCode) . '.');
        }
        try {
            $decoded = json_decode((string) $raw, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Proxmox zwrócił nieprawidłową odpowiedź JSON (HTTP ' . $status . ').', 0, $exception);
        }

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';
            $fieldErrors = [];
            if (is_array($decoded) && is_array($decoded['errors'] ?? null)) {
                foreach ($decoded['errors'] as $field => $detail) {
                    if (!is_scalar($detail)) continue;
                    $fieldErrors[] = (string) $field . ': ' . trim((string) $detail);
                }
            }
            $details = $fieldErrors !== [] ? implode('; ', $fieldErrors) : '';
            if ($status === 401) throw new \RuntimeException('Proxmox odrzucił login lub hasło (HTTP 401).');
            if ($status === 403) throw new \RuntimeException('Logowanie działa, ale konto nie ma wymaganych uprawnień w Proxmox (HTTP 403).');
            throw new \RuntimeException(
                'Proxmox API zwróciło HTTP ' . $status
                . ($message !== '' ? ': ' . mb_substr($message, 0, 300) : '.')
                . ($details !== '' ? ' Szczegóły: ' . mb_substr($details, 0, 500) : ''),
            );
        }

        return is_array($decoded) ? ($decoded['data'] ?? null) : null;
    }
}
