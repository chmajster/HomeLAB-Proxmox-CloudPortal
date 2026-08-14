<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;

final class ProxmoxConnectionInput
{
    public const TOKEN_ID_HELP = 'Użyj formatu user!token lub user@realm!token, np. root@pam!cloudportal. Sam login (np. root) i hasło nie są tokenem API.';

    /**
     * @param array<string,mixed> $input
     * @return array{name:string,hostname:string,port:int,realm:string,token_id:string,token_secret:string,verify_ssl:bool}
     */
    public static function validate(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $hostname = trim((string) ($input['hostname'] ?? ''));
        $hostname = preg_replace('#^https?://#i', '', rtrim($hostname, '/')) ?? '';
        $port = filter_var($input['port'] ?? 8006, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $realm = trim((string) ($input['realm'] ?? 'pve'));
        $tokenId = trim((string) ($input['api_token_id'] ?? ''));
        $tokenSecret = (string) ($input['api_token_secret'] ?? '');
        $fields = [];

        if ($name === '' || mb_strlen($name) > 100) {
            $fields['name'] = 'Nazwa połączenia jest wymagana i może mieć maksymalnie 100 znaków.';
        }
        if (!self::validHost($hostname) || strlen($hostname) > 255) {
            $fields['hostname'] = 'Podaj prawidłowy hostname lub adres IP bez ścieżki URL.';
        }
        if ($port === false) {
            $fields['port'] = 'Port musi być liczbą od 1 do 65535.';
        }
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $realm) !== 1) {
            $fields['realm'] = 'Realm może zawierać wyłącznie litery, cyfry, kropki, myślniki i podkreślenia.';
        }

        $tokenMatches = [];
        if (strlen($tokenId) > 255 || preg_match('/^(?<user>[A-Za-z0-9._-]+)(?:@(?<token_realm>[A-Za-z0-9._-]+))?!(?<token>[A-Za-z0-9._-]+)$/', $tokenId, $tokenMatches) !== 1) {
            $fields['api_token_id'] = self::TOKEN_ID_HELP;
        }
        if ($tokenSecret === '' || preg_match('/[\r\n]/', $tokenSecret) === 1) {
            $fields['api_token_secret'] = 'Wymagany jest sekret wygenerowanego tokenu API (nie hasło użytkownika).';
        }

        if ($fields !== []) {
            $message = isset($fields['api_token_id'])
                ? 'Nieprawidłowy Token ID Proxmox. ' . self::TOKEN_ID_HELP
                : 'Nieprawidłowe dane połączenia Proxmox.';
            throw new HttpException(422, $message, ['fields' => $fields]);
        }

        // An explicitly qualified token is authoritative, so a default "pve"
        // left in the form cannot make a root@pam token appear to use pve.
        $explicitRealm = (string) ($tokenMatches['token_realm'] ?? '');
        if ($explicitRealm !== '') {
            $realm = $explicitRealm;
        }

        return [
            'name' => $name,
            'hostname' => $hostname,
            'port' => (int) $port,
            'realm' => $realm,
            'token_id' => $tokenId,
            'token_secret' => $tokenSecret,
            'verify_ssl' => !array_key_exists('verify_ssl', $input) || filter_var($input['verify_ssl'], FILTER_VALIDATE_BOOL),
        ];
    }

    private static function validHost(string $hostname): bool
    {
        $bare = str_starts_with($hostname, '[') && str_ends_with($hostname, ']') ? substr($hostname, 1, -1) : $hostname;
        return filter_var($bare, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $hostname) === 1;
    }
}
