<?php

declare(strict_types=1);

namespace CloudPortal\Services\DNS;

use CloudPortal\Security\Crypto;
use CloudPortal\Support\Config;
use PDO;

final class DnsSettingsService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?Crypto $crypto,
        private readonly ?Config $config = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function publicConfiguration(): array
    {
        $config = $this->configuration();
        unset($config['api_token_encrypted']);
        $config['token_configured'] = $this->tokenConfigured();
        $config['configured'] = $this->configured();
        return $config;
    }

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        $serverIp = trim((string) $this->value('dns.server_ip', $this->config?->get('dns.server_ip', '')));
        $tokenEncrypted = trim((string) $this->value('dns.api_token_encrypted', $this->config?->get('dns.api_token_encrypted', '')));
        $enabledStored = $this->stored('dns.enabled');
        $enabled = $enabledStored['found']
            ? filter_var($enabledStored['value'], FILTER_VALIDATE_BOOL)
            : ($serverIp !== '' && $tokenEncrypted !== '');

        return [
            'enabled' => $enabled,
            'server_ip' => $serverIp,
            'port' => (int) $this->value('dns.port', $this->config?->get('dns.port', 81)),
            'scheme' => strtolower((string) $this->value('dns.scheme', $this->config?->get('dns.scheme', 'http'))),
            'forward_zone' => strtolower(rtrim(trim((string) $this->value('dns.forward_zone', $this->config?->get('dns.forward_zone', ''))), '.')),
            'api_token_encrypted' => $tokenEncrypted,
            'hostname_pattern' => trim((string) $this->value('hostname_generator.pattern', $this->config?->get('hostname_generator.pattern', 'vm-{project}-{counter}'))),
        ];
    }

    public function configured(): bool
    {
        $config = $this->configuration();
        return $config['enabled'] === true
            && filter_var($config['server_ip'], FILTER_VALIDATE_IP) !== false
            && $config['api_token_encrypted'] !== ''
            && $config['hostname_pattern'] !== '';
    }

    public function hostnamePattern(): string
    {
        return (string) $this->configuration()['hostname_pattern'];
    }

    public function forwardZone(): ?string
    {
        $zone = (string) $this->configuration()['forward_zone'];
        return $zone === '' ? null : $zone;
    }

    public function client(): DnsApiClient
    {
        if (!$this->crypto instanceof Crypto) {
            throw new \RuntimeException('DNS secret decryption is not available in this context.');
        }
        $config = $this->configuration();
        if (!$this->configured()) {
            throw new \RuntimeException('DNS integration is not fully configured.');
        }
        $token = $this->crypto->decrypt((string) $config['api_token_encrypted']);
        return new DnsApiClient(
            (string) $config['server_ip'],
            $token,
            (int) $config['port'],
            (string) $config['scheme'],
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(array $input, int $userId): array
    {
        $current = $this->configuration();
        $normalized = $this->normalize($input, $current, true);
        $encrypted = (string) $current['api_token_encrypted'];
        $newToken = trim((string) ($input['api_token'] ?? ''));
        if ($newToken !== '') {
            if (!$this->crypto instanceof Crypto) {
                throw new \RuntimeException('DNS token encryption is not available.');
            }
            $encrypted = $this->crypto->encrypt($newToken);
        }
        if ($normalized['enabled'] && $encrypted === '') {
            throw new \InvalidArgumentException('API token DNS jest wymagany, gdy integracja jest włączona.');
        }

        $this->upsert('dns.enabled', $normalized['enabled'], $userId);
        $this->upsert('dns.server_ip', $normalized['server_ip'], $userId);
        $this->upsert('dns.port', $normalized['port'], $userId);
        $this->upsert('dns.scheme', $normalized['scheme'], $userId);
        $this->upsert('dns.forward_zone', $normalized['forward_zone'], $userId);
        $this->upsert('hostname_generator.pattern', $normalized['hostname_pattern'], $userId);
        if ($newToken !== '') {
            $this->upsert('dns.api_token_encrypted', $encrypted, $userId);
        }

        return $this->publicConfiguration();
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function test(array $input): array
    {
        $current = $this->configuration();
        $normalized = $this->normalize($input, $current, false);
        $token = trim((string) ($input['api_token'] ?? ''));
        if ($token === '') {
            if (!$this->crypto instanceof Crypto || (string) $current['api_token_encrypted'] === '') {
                throw new \InvalidArgumentException('Podaj token API DNS albo zapisz go wcześniej w ustawieniach.');
            }
            $token = $this->crypto->decrypt((string) $current['api_token_encrypted']);
        }

        $client = new DnsApiClient(
            (string) $normalized['server_ip'],
            $token,
            (int) $normalized['port'],
            (string) $normalized['scheme'],
        );
        return $client->testConnection($normalized['forward_zone'] === '' ? null : (string) $normalized['forward_zone']);
    }

    private function tokenConfigured(): bool
    {
        return (string) $this->configuration()['api_token_encrypted'] !== '';
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $current @return array<string,mixed> */
    private function normalize(array $input, array $current, bool $allowDisabled): array
    {
        $enabledValue = $input['enabled'] ?? $current['enabled'];
        $enabled = filter_var($enabledValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new \InvalidArgumentException('Pole enabled musi być wartością boolean.');
        }
        $serverIp = trim((string) ($input['server_ip'] ?? $current['server_ip']));
        $port = filter_var($input['port'] ?? $current['port'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $scheme = strtolower(trim((string) ($input['scheme'] ?? $current['scheme'])));
        $zone = strtolower(rtrim(trim((string) ($input['forward_zone'] ?? $current['forward_zone'])), '.'));
        $pattern = trim((string) ($input['hostname_pattern'] ?? $current['hostname_pattern']));

        if (($enabled || !$allowDisabled || $serverIp !== '') && filter_var($serverIp, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('Podaj prawidłowy adres IP serwera HomeLAB-DNS.');
        }
        if ($port === false) {
            throw new \InvalidArgumentException('Port DNS API musi mieścić się w zakresie 1-65535.');
        }
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Protokół DNS API musi być ustawiony na http albo https.');
        }
        if ($zone !== '' && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $zone) !== 1) {
            throw new \InvalidArgumentException('Strefa DNS jest nieprawidłowa, np. lab.example.local.');
        }
        if ($pattern === '' || strlen($pattern) > 100 || preg_match('/\{counter(?::0[1-9][0-9]?)?\}/', $pattern) !== 1) {
            throw new \InvalidArgumentException('Wzorzec hostname musi zawierać {counter} lub {counter:0N} i mieć maksymalnie 100 znaków.');
        }
        $withoutSupported = preg_replace('/\{(?:project|user|counter(?::0[1-9][0-9]?)?)\}/', '', $pattern);
        if (!is_string($withoutSupported) || preg_match('/[{}]/', $withoutSupported) === 1) {
            throw new \InvalidArgumentException('Wzorzec hostname zawiera nieobsługiwany placeholder. Dozwolone: {project}, {user}, {counter}, {counter:0N}.');
        }
        $token = trim((string) ($input['api_token'] ?? ''));
        if ($token !== '' && (strlen($token) > 4096 || preg_match('/[\r\n]/', $token))) {
            throw new \InvalidArgumentException('Token API DNS jest nieprawidłowy.');
        }

        return [
            'enabled' => $enabled,
            'server_ip' => $serverIp,
            'port' => (int) $port,
            'scheme' => $scheme,
            'forward_zone' => $zone,
            'hostname_pattern' => $pattern,
        ];
    }

    private function value(string $key, mixed $fallback): mixed
    {
        $stored = $this->stored($key);
        return $stored['found'] ? $stored['value'] : $fallback;
    }

    /** @return array{found:bool,value:mixed} */
    private function stored(string $key): array
    {
        $statement = $this->pdo->prepare('SELECT value FROM settings WHERE setting_key=:key LIMIT 1');
        $statement->execute(['key' => $key]);
        $raw = $statement->fetchColumn();
        if (!is_string($raw)) {
            return ['found' => false, 'value' => null];
        }
        try {
            return ['found' => true, 'value' => json_decode($raw, true, 32, JSON_THROW_ON_ERROR)];
        } catch (\JsonException) {
            return ['found' => false, 'value' => null];
        }
    }

    private function upsert(string $key, mixed $value, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO settings(setting_key,value,is_public,updated_by) VALUES(:key,:value,0,:user) '
            . 'ON DUPLICATE KEY UPDATE value=VALUES(value),is_public=0,updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'user' => $userId > 0 ? $userId : null,
        ]);
    }
}
