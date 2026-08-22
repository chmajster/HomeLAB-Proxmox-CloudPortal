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
     *   portal:array<string,mixed>
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
        $administrator = $this->section($decoded, 'administrator', true);
        $proxmox = $this->section($decoded, 'proxmox', false);
        $portal = $this->section($decoded, 'portal', false);

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
            'username' => $administrator['username'] ?? '',
            'email' => $administrator['email'] ?? '',
            'password' => $administratorPassword,
            'password_confirmation' => $administrator['password_confirmation'] ?? $administratorPassword,
            'resume_existing_admin' => $administrator['resume_existing'] ?? false,
        ];

        $proxmoxInput = $proxmox === []
            ? ['skip_proxmox' => true]
            : [
                'skip_proxmox' => $proxmox['skip'] ?? false,
                'connection_name' => $proxmox['name'] ?? 'Primary Proxmox',
                'hostname' => $proxmox['hostname'] ?? '',
                'port' => $proxmox['port'] ?? 8006,
                'realm' => $proxmox['realm'] ?? 'pve',
                'api_token_id' => $proxmox['token_id'] ?? '',
                'api_token_secret' => $proxmox['token_secret'] ?? '',
                'verify_ssl' => $proxmox['verify_ssl'] ?? true,
            ];

        $portalInput = [
            'portal_name' => $portal['name'] ?? 'Algen Cloud Portal',
            'base_url' => $portal['url'] ?? $defaultBaseUrl,
            'timezone' => $portal['timezone'] ?? 'Europe/Warsaw',
            'locale' => $portal['locale'] ?? 'pl',
            'session_lifetime' => $portal['session_lifetime'] ?? 7200,
        ];

        // Validate every section before the installer performs any database or
        // filesystem mutation. This prevents a syntactically valid but
        // incomplete JSON file from causing a partial installation.
        return [
            'database' => InstallerInput::database($databaseInput),
            'administrator' => InstallerInput::administrator($administratorInput),
            'proxmox' => InstallerInput::proxmox($proxmoxInput),
            'portal' => InstallerInput::portal($portalInput),
        ];
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
