<?php

declare(strict_types=1);

namespace CloudPortal\Installer\Validators;

final class InstallerInput
{
    /** @param array<string,mixed> $input @return array{driver:string,host:string,port:int,name:string,user:string,password:string,create_if_missing:bool,connection_test_only:bool,confirm_existing:bool} */
    public static function database(array $input): array
    {
        $driver = (string) ($input['db_driver'] ?? 'mysql');
        $host = trim((string) ($input['db_host'] ?? ''));
        $port = filter_var($input['db_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $name = trim((string) ($input['db_name'] ?? ''));
        $user = trim((string) ($input['db_user'] ?? ''));
        $connectionTestOnly = filter_var($input['connection_test_only'] ?? false, FILTER_VALIDATE_BOOL);
        $createIfMissing = !array_key_exists('create_database_if_missing', $input)
            || filter_var($input['create_database_if_missing'], FILTER_VALIDATE_BOOL);

        // Empty database name has two intentional meanings:
        // - during "Test connection": test only the MariaDB/MySQL server and credentials;
        // - during "Continue": use the default database name "cloudportal" and ensure it exists.
        if ($name === '') {
            if ($connectionTestOnly) {
                $createIfMissing = true;
            } else {
                $name = 'cloudportal';
                $createIfMissing = true;
            }
        }

        $fields = [];
        if ($driver !== 'mysql') $fields['db_driver'] = 'Obsługiwane bazy: MariaDB / MySQL.';
        if (!self::host($host)) $fields['db_host'] = 'Podaj prawidłowy hostname lub adres IP serwera bazy danych.';
        if ($port === false) $fields['db_port'] = 'Port musi być liczbą od 1 do 65535.';
        if ($name !== '' && preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $name) !== 1) {
            $fields['db_name'] = 'Podaj prawidłową nazwę bazy danych (1-64 znaki: litery, cyfry, _, $, -).';
        }
        if ($user === '' || strlen($user) > 128) $fields['db_user'] = 'Użytkownik bazy danych jest wymagany.';
        if ($fields !== []) {
            throw new InstallerValidationException(
                'Popraw dane połączenia z bazą danych: ' . implode(' ', array_values($fields)),
                $fields,
            );
        }
        return [
            'driver' => $driver, 'host' => $host, 'port' => (int) $port, 'name' => $name,
            'user' => $user, 'password' => (string) ($input['db_password'] ?? ''),
            'create_if_missing' => $createIfMissing,
            'connection_test_only' => $connectionTestOnly,
            'confirm_existing' => filter_var($input['confirm_existing_database'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    /** @param array<string,mixed> $input @return array{username:string,email:string,password:string,resume:bool,test_account:bool} */
    public static function administrator(array $input): array
    {
        if (filter_var($input['use_test_administrator'] ?? false, FILTER_VALIDATE_BOOL)) {
            return [
                'username' => 'admin',
                'email' => 'admin@localhost.invalid',
                'password' => '1',
                'resume' => false,
                'test_account' => true,
            ];
        }

        $username = trim((string) ($input['username'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $fields = [];
        if (preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username) !== 1) $fields['username'] = 'Use 3-64 letters, digits, dots, hyphens or underscores.';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) $fields['email'] = 'Enter a valid email address or leave this field empty.';
        if (strlen($password) < 12 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password)) $fields['password'] = 'Use at least 12 characters with upper/lowercase letters and a digit.';
        if (!hash_equals($password, (string) ($input['password_confirmation'] ?? ''))) $fields['password_confirmation'] = 'Passwords do not match.';
        self::fail($fields, 'Correct the administrator fields.');
        if ($email === '') $email = mb_strtolower($username) . '@localhost.invalid';
        return [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'resume' => filter_var($input['resume_existing_admin'] ?? false, FILTER_VALIDATE_BOOL),
            'test_account' => false,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public static function proxmox(array $input): array
    {
        $skip = filter_var($input['skip_proxmox'] ?? false, FILTER_VALIDATE_BOOL);
        if ($skip) return ['skipped' => true];
        $name = trim((string) ($input['connection_name'] ?? ''));
        $hostname = trim((string) ($input['hostname'] ?? ''));
        $hostname = preg_replace('#^https?://#i', '', rtrim($hostname, '/')) ?? '';
        $port = filter_var($input['port'] ?? 8006, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $realm = trim((string) ($input['realm'] ?? 'pve'));
        $tokenId = trim((string) ($input['api_token_id'] ?? ''));
        $secret = (string) ($input['api_token_secret'] ?? '');
        $fields = [];
        if ($name === '' || mb_strlen($name) > 100) $fields['connection_name'] = 'Connection name is required.';
        if (!self::host($hostname)) $fields['hostname'] = 'Enter a valid hostname or IP address without a path.';
        if ($port === false) $fields['port'] = 'Port must be between 1 and 65535.';
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $realm) !== 1) $fields['realm'] = 'Realm is invalid.';
        $tokenMatches = [];
        if (preg_match('/^(?<user>[A-Za-z0-9._-]+)(?:@(?<token_realm>[A-Za-z0-9._-]+))?!(?<token>[A-Za-z0-9._-]+)$/', $tokenId, $tokenMatches) !== 1) $fields['api_token_id'] = 'Użyj formatu user!token lub user@realm!token, np. root@pam!cloudportal. Sam login i hasło nie są tokenem API.';
        if ($secret === '' || preg_match('/[\r\n]/', $secret)) $fields['api_token_secret'] = 'Wymagany jest sekret tokenu API (nie hasło użytkownika).';
        if (isset($fields['api_token_id'])) throw new InstallerValidationException('Nieprawidłowy Token ID Proxmox. ' . $fields['api_token_id'], $fields);
        self::fail($fields, 'Correct the Proxmox connection fields.');
        $explicitRealm = (string) ($tokenMatches['token_realm'] ?? '');
        if ($explicitRealm !== '') $realm = $explicitRealm;
        return [
            'skipped' => false, 'name' => $name, 'hostname' => $hostname, 'port' => (int) $port,
            'realm' => $realm, 'token_id' => $tokenId, 'token_secret' => $secret,
            'verify_ssl' => !isset($input['verify_ssl']) || filter_var($input['verify_ssl'], FILTER_VALIDATE_BOOL),
        ];
    }

    /** @param array<string,mixed> $input @return array{name:string,url:string,timezone:string,locale:string,session_lifetime:int} */
    public static function portal(array $input): array
    {
        $name = trim((string) ($input['portal_name'] ?? ''));
        $url = rtrim(trim((string) ($input['base_url'] ?? '')), '/');
        $timezone = (string) ($input['timezone'] ?? '');
        $locale = (string) ($input['locale'] ?? 'pl');
        $lifetime = filter_var($input['session_lifetime'] ?? 7200, FILTER_VALIDATE_INT, ['options' => ['min_range' => 900, 'max_range' => 86400]]);
        $urlParts = parse_url($url);
        $fields = [];
        if ($name === '' || mb_strlen($name) > 100) $fields['portal_name'] = 'Portal name is required and may contain at most 100 characters.';
        $scheme = strtolower((string) ($urlParts['scheme'] ?? ''));
        $host = strtolower((string) ($urlParts['host'] ?? ''));
        $path = (string) ($urlParts['path'] ?? '');
        $secureScheme = $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true));
        $safePath = $path === '' || preg_match('#^(?:/[A-Za-z0-9._~-]+)*$#', $path) === 1;
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($urlParts) || $host === '' || isset($urlParts['user'], $urlParts['pass'], $urlParts['query'], $urlParts['fragment']) || !$safePath || !$secureScheme) $fields['base_url'] = 'Use an HTTPS URL without query or fragment (HTTP is allowed only for localhost).';
        if (!in_array($timezone, timezone_identifiers_list(), true)) $fields['timezone'] = 'Select a valid IANA timezone.';
        if (!in_array($locale, ['pl', 'en'], true)) $fields['locale'] = 'Supported languages: Polish or English.';
        if ($lifetime === false) $fields['session_lifetime'] = 'Session lifetime must be between 900 and 86400 seconds.';
        self::fail($fields, 'Correct the portal configuration fields.');
        return ['name' => $name, 'url' => $url, 'timezone' => $timezone, 'locale' => $locale, 'session_lifetime' => (int) $lifetime];
    }

    /** @param array<string,string> $fields */
    private static function fail(array $fields, string $message): void
    {
        if ($fields !== []) throw new InstallerValidationException($message, $fields);
    }

    private static function host(string $host): bool
    {
        $bare = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
        return filter_var($bare, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $host) === 1;
    }
}
