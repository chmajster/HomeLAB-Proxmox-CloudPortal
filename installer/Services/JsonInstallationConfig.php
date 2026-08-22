<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Services;

use CloudPortal\Installer\Validators\InstallerInput;

final class JsonInstallationConfig
{
    /**
     * @return array{
     *   database:array<string,mixed>,
     *   administrator:array<string,mixed>,
     *   proxmox:array<string,mixed>,
     *   portal:array<string,mixed>,
     *   dns:array<string,mixed>,
     *   proxmox_credentials:array<string,mixed>,
     *   hostname_generator:array{pattern:string}
     * }
     */
    public function load(string $path, string $defaultBaseUrl): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('install.json is not readable.');
        }

        $size = filesize($path);
        if ($size === false || $size < 2 || $size > 131072) {
            throw new \RuntimeException('install.json must contain between 2 bytes and 128 KiB.');
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('install.json could not be read.');
        }

        try {
            $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('install.json contains invalid JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \RuntimeException('install.json must contain a JSON object at the top level.');
        }

        $database = $this->section($decoded, 'database', true);
        $administrator = $this->administratorSection($decoded);
        $proxmox = $this->section($decoded, 'proxmox', false);
        $portal = $this->section($decoded, 'portal', false);
        $dns = $this->dns($this->section($decoded, 'dns', false));
        $hostnameGenerator = $this->hostnameGenerator($decoded);

        $databaseInput = [
            'db_driver' => $database['driver'] ?? 'mysql',
            'db_host' => $database['host'] ?? '127.0.0.1',
            'db_port' => $database['port'] ?? 3306,
            'db_name' => $database['name'] ?? '',
            'db_user' => $database['user'] ?? '',
            'db_password' => $database['password'] ?? '',
            'confirm_existing_database' => $database['confirm_existing'] ?? false,
        ];

        $administratorPassword = (string) ($administrator['password'] ?? '');
        $administratorInput = [
            'username' => $administrator['username'] ?? $administrator['login'] ?? '',
            'email' => $administrator['email'] ?? '',
            'password' => $administratorPassword,
            'password_confirmation' => $administrator['password_confirmation'] ?? $administratorPassword,
            'resume_existing_admin' => $administrator['resume_existing'] ?? false,
        ];

        [$proxmoxInput, $proxmoxCredentials] = $this->proxmox($proxmox);

        $portalInput = [
            'portal_name' => $portal['name'] ?? 'Algen Cloud Portal',
            'base_url' => $portal['url'] ?? $defaultBaseUrl,
            'timezone' => $portal['timezone'] ?? 'Europe/Warsaw',
            'locale' => $portal['locale'] ?? 'pl',
            'session_lifetime' => $portal['session_lifetime'] ?? 7200,
        ];

        return [
            'database' => InstallerInput::database($databaseInput),
            'administrator' => InstallerInput::administrator($administratorInput),
            'proxmox' => InstallerInput::proxmox($proxmoxInput),
            'portal' => InstallerInput::portal($portalInput),
            'dns' => $dns,
            'proxmox_credentials' => $proxmoxCredentials,
            'hostname_generator' => $hostnameGenerator,
        ];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function administratorSection(array $data): array
    {
        $hasAdministrator = array_key_exists('administrator', $data);
        $hasPanel = array_key_exists('panel', $data);
        if ($hasAdministrator && $hasPanel) {
            throw new \RuntimeException("install.json must use either 'panel' or legacy 'administrator', not both.");
        }
        return $this->section($data, $hasPanel ? 'panel' : 'administrator', true);
    }

    /** @param array<string,mixed> $proxmox @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function proxmox(array $proxmox): array
    {
        if ($proxmox === []) {
            return [['skip_proxmox' => true], []];
        }

        $skip = filter_var($proxmox['skip'] ?? false, FILTER_VALIDATE_BOOL);
        if ($skip) {
            return [['skip_proxmox' => true], []];
        }

        $login = trim((string) ($proxmox['login'] ?? ''));
        $password = (string) ($proxmox['password'] ?? '');
        if ($login !== '' && preg_match('/^[A-Za-z0-9._-]+(?:@[A-Za-z0-9._-]+)?$/', $login) !== 1) {
            throw new \RuntimeException('proxmox.login must use user or user@realm format.');
        }
        if (preg_match('/[\r\n]/', $password)) {
            throw new \RuntimeException('proxmox.password must not contain line breaks.');
        }

        $tokenId = trim((string) ($proxmox['token_id'] ?? ''));
        $tokenSecret = (string) ($proxmox['token_secret'] ?? $proxmox['token'] ?? '');
        if ($tokenId === '' && str_contains($tokenSecret, '=')) {
            [$candidateId, $candidateSecret] = explode('=', $tokenSecret, 2);
            if (str_contains($candidateId, '!') && $candidateSecret !== '') {
                $tokenId = trim($candidateId);
                $tokenSecret = $candidateSecret;
            }
        }

        $tokenName = trim((string) ($proxmox['token_name'] ?? 'cloudportal'));
        if ($tokenId === '' && $login !== '' && $tokenSecret !== '') {
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $tokenName) !== 1) {
                throw new \RuntimeException('proxmox.token_name is invalid.');
            }
            $tokenId = $login . '!' . $tokenName;
        }

        return [[
            'skip_proxmox' => false,
            'connection_name' => $proxmox['name'] ?? 'Primary Proxmox',
            'hostname' => $proxmox['hostname'] ?? '',
            'port' => $proxmox['port'] ?? 8006,
            'realm' => $proxmox['realm'] ?? 'pve',
            'api_token_id' => $tokenId,
            'api_token_secret' => $tokenSecret,
            'verify_ssl' => $proxmox['verify_ssl'] ?? true,
        ], [
            'login' => $login,
            'password' => $password,
        ]];
    }

    /** @param array<string,mixed> $dns @return array<string,mixed> */
    private function dns(array $dns): array
    {
        if ($dns === []) {
            return [];
        }

        $serverIp = trim((string) ($dns['server_ip'] ?? $dns['ip'] ?? ''));
        $apiToken = (string) ($dns['api_token'] ?? $dns['token'] ?? '');
        if (filter_var($serverIp, FILTER_VALIDATE_IP) === false) {
            throw new \RuntimeException('dns.server_ip must be a valid IPv4 or IPv6 address.');
        }
        if ($apiToken === '' || preg_match('/[\r\n]/', $apiToken)) {
            throw new \RuntimeException('dns.api_token is required and must not contain line breaks.');
        }

        return ['server_ip' => $serverIp, 'api_token' => $apiToken];
    }

    /** @param array<string,mixed> $data @return array{pattern:string} */
    private function hostnameGenerator(array $data): array
    {
        $section = $this->section($data, 'hostname_generator', false);
        $pattern = trim((string) (
            $section['pattern']
            ?? $data['hostname_generator_pattern']
            ?? 'vm-{project}-{counter}'
        ));
        if ($pattern === '' || strlen($pattern) > 128) {
            throw new \RuntimeException('hostname_generator.pattern must contain 1-128 characters.');
        }
        if (preg_match('/^[A-Za-z0-9._{}-]+$/', $pattern) !== 1) {
            throw new \RuntimeException('hostname_generator.pattern contains unsupported characters.');
        }
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $pattern, $matches);
        foreach ($matches[1] as $placeholder) {
            if (!in_array($placeholder, ['project', 'user', 'counter'], true)) {
                throw new \RuntimeException("hostname_generator.pattern contains unsupported placeholder {{$placeholder}}.");
            }
        }
        if (!str_contains($pattern, '{counter}')) {
            throw new \RuntimeException('hostname_generator.pattern must contain {counter} to keep generated hostnames unique.');
        }

        return ['pattern' => $pattern];
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function section(array $data, string $name, bool $required): array
    {
        if (!array_key_exists($name, $data)) {
            if ($required) {
                throw new \RuntimeException("install.json is missing the required '{$name}' object.");
            }
            return [];
        }

        $section = $data[$name];
        if (!is_array($section) || ($section !== [] && array_is_list($section))) {
            throw new \RuntimeException("install.json field '{$name}' must be a JSON object.");
        }
        return $section;
    }
}
