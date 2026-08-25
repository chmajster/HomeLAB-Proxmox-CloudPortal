<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;
use PDOException;

final class ProxmoxFailureMessage
{
    /** @param list<string> $secrets */
    public static function describe(ProxmoxException $exception, string $hostname, int $port, array $secrets = []): string
    {
        $endpoint = $hostname . ':' . $port;
        if ($exception->curlCode > 0) {
            $curlText = function_exists('curl_strerror') ? curl_strerror($exception->curlCode) : 'błąd transportu';
            $detail = self::safeDetail($exception->getMessage(), $secrets);
            return sprintf(
                'Brak połączenia z %s. Status transportu: cURL %d (%s). Szczegóły: %s. %s',
                $endpoint,
                $exception->curlCode,
                $curlText,
                $detail,
                self::curlHint($exception->curlCode),
            );
        }

        if ($exception->httpStatus > 0) {
            $detail = self::safeDetail($exception->getMessage(), $secrets);
            $stoppedVm = self::notRunningVmId($detail);
            if ($stoppedVm !== null) {
                return sprintf(
                    'Maszyna VM %d jest zatrzymana. Proxmox zwrócił ten stan jako HTTP %d, ale nie oznacza to awarii serwera. Uruchom VM, jeśli wykonywana operacja wymaga działającej maszyny.',
                    $stoppedVm,
                    $exception->httpStatus,
                );
            }

            $label = self::httpLabel($exception->httpStatus);
            $apiErrors = $exception->response['errors'] ?? null;
            if ($apiErrors !== null) {
                $encoded = json_encode($apiErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($encoded) && $encoded !== '') $detail .= '; pola API: ' . self::safeDetail($encoded, $secrets);
            }
            return sprintf(
                'Proxmox API %s zwróciło HTTP %d (%s). Szczegóły: %s. %s',
                $endpoint,
                $exception->httpStatus,
                $label,
                $detail,
                self::httpHint($exception->httpStatus),
            );
        }

        return 'Test połączenia Proxmox nie powiódł się przed otrzymaniem statusu HTTP. Sprawdź format Token ID, endpoint i konfigurację PHP cURL. Szczegóły techniczne zapisano w logu instalatora.';
    }

    public static function asHttpException(\Throwable $exception, string $hostname, int $port, string $operation = 'Operacja Proxmox'): HttpException
    {
        if ($exception instanceof HttpException) return $exception;
        if ($exception instanceof ProxmoxException) {
            $stoppedVm = self::notRunningVmId($exception->getMessage());
            if ($stoppedVm !== null) {
                return new HttpException(409, sprintf(
                    '%s nie może zostać wykonana w obecnym stanie: VM %d jest zatrzymana. To nie jest awaria API Proxmox. Uruchom maszynę i ponów operację, jeśli wymaga ona działającej VM.',
                    $operation,
                    $stoppedVm,
                ), ['proxmox_status' => $exception->httpStatus, 'vmid' => $stoppedVm, 'vm_state' => 'stopped']);
            }

            $details = [];
            if ($exception->httpStatus > 0) $details['proxmox_status'] = $exception->httpStatus;
            if ($exception->curlCode > 0) $details['curl_code'] = $exception->curlCode;
            return new HttpException(422, self::describe($exception, $hostname, $port), $details);
        }
        if ($exception instanceof \InvalidArgumentException) {
            return new HttpException(422, $operation . ' nie powiodła się: zapisana konfiguracja połączenia jest nieprawidłowa.');
        }
        if ($exception instanceof \RuntimeException && str_starts_with($exception->getMessage(), 'Encrypted value')) {
            return new HttpException(422, $operation . ' nie powiodła się: nie można odszyfrować zapisanego sekretu tokenu. Użyj opcji „Rotuj sekret”, zapisz nowy sekret API i spróbuj ponownie.');
        }
        if ($exception instanceof PDOException) {
            return new HttpException(500, $operation . ' połączyła się z usługą, ale nie udało się zapisać danych w bazie. Szczegóły techniczne zapisano w logu serwera.');
        }

        $class = str_replace("\0", '', get_debug_type($exception));
        return new HttpException(500, $operation . ' nie powiodła się podczas przetwarzania odpowiedzi. Typ błędu: ' . $class . '. Szczegóły techniczne zapisano w logu serwera.');
    }

    /** @param list<string> $secrets */
    private static function safeDetail(string $message, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') $message = str_replace($secret, '[ukryto]', $message);
        }
        $message = preg_replace('/PVEAPIToken=[^\s,;]+/i', 'PVEAPIToken=[ukryto]', $message) ?? '';
        $message = preg_replace('/(api[_ -]?token[_ -]?secret\s*[=:]\s*)[^\s,;]+/i', '$1[ukryto]', $message) ?? '';
        $message = strip_tags($message);
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
        $message = preg_replace('/\s+/u', ' ', trim($message)) ?? '';
        return mb_substr($message !== '' ? $message : 'brak dodatkowego opisu', 0, 500);
    }

    private static function notRunningVmId(string $message): ?int
    {
        $matches = [];
        if (preg_match('/\bVM\s+(\d+)\s+(?:is\s+)?not\s+running\b/i', $message, $matches) !== 1) return null;
        $vmid = (int) ($matches[1] ?? 0);
        return $vmid > 0 ? $vmid : null;
    }

    private static function curlHint(int $code): string
    {
        return match ($code) {
            6 => 'Nie udało się rozwiązać nazwy hosta w DNS.',
            7 => 'Host odrzuca połączenie albo wybrany port jest niedostępny z serwera WWW.',
            28 => 'Upłynął limit czasu połączenia lub odpowiedzi.',
            35, 51, 58, 60, 77, 83, 90, 91 => 'Weryfikacja TLS nie powiodła się; sprawdź certyfikat i nazwę hosta.',
            default => 'Sprawdź trasę sieciową, firewall, port i ustawienia TLS.',
        };
    }

    private static function httpLabel(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'Not Found',
            405 => 'Method Not Allowed', 408 => 'Request Timeout', 429 => 'Too Many Requests',
            500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway',
            503 => 'Service Unavailable', 504 => 'Gateway Timeout',
            default => $status >= 200 && $status < 300 ? 'nieprawidłowa odpowiedź' : 'błąd API',
        };
    }

    private static function httpHint(int $status): string
    {
        return match ($status) {
            401 => 'Token ID lub sekret są nieprawidłowe.',
            403 => 'Token został rozpoznany, ale nie ma wymaganych uprawnień ACL.',
            404 => 'Sprawdź, czy podany adres i port prowadzą do Proxmox VE API.',
            429 => 'Proxmox ograniczył liczbę żądań; spróbuj ponownie później.',
            500, 502, 503, 504 => 'API Proxmox lub pośredniczący serwer ma problem po stronie serwera.',
            default => 'Sprawdź konfigurację endpointu i odpowiedź API Proxmox.',
        };
    }
}
