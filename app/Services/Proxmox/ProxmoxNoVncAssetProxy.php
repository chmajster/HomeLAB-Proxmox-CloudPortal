<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;
use CloudPortal\Security\Crypto;
use PDO;

final class ProxmoxNoVncAssetProxy
{
    public function __construct(private readonly PDO $pdo, private readonly Crypto $crypto)
    {
    }

    /** @return array{body:string,content_type:string} */
    public function fetch(int $connectionId, string $asset): array
    {
        $asset = ltrim($asset, '/');
        if ($connectionId <= 0 || $asset === '' || str_contains($asset, '..') || preg_match('#^[A-Za-z0-9._/-]+$#', $asset) !== 1) {
            throw new HttpException(404, 'noVNC asset not found.');
        }
        $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
        if (!in_array($extension, ['js', 'css', 'html', 'svg', 'png', 'gif', 'jpg', 'jpeg', 'woff', 'woff2', 'ttf', 'map'], true)) {
            throw new HttpException(404, 'noVNC asset type is not allowed.');
        }

        $statement = $this->pdo->prepare("SELECT hostname,port,realm,api_token_id,api_token_secret_encrypted,verify_ssl,status FROM proxmox_connections WHERE id=:id");
        $statement->execute(['id' => $connectionId]);
        $connection = $statement->fetch();
        if (!is_array($connection)) throw new HttpException(404, 'Proxmox connection not found.');
        if (($connection['status'] ?? null) === 'disabled') throw new HttpException(409, 'Proxmox connection is disabled.');

        $host = preg_replace('#^https?://#i', '', rtrim(trim((string) $connection['hostname']), '/'));
        if ($host === '' || preg_match('/[\r\n]/', (string) $host)) throw new HttpException(500, 'Invalid Proxmox connection hostname.');
        $urlHost = $host;
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && !str_starts_with($host, '[')) $urlHost = '[' . $host . ']';
        $port = (int) $connection['port'];
        $url = 'https://' . $urlHost . ':' . max(1, min(65535, $port)) . '/novnc/' . $asset;

        $secret = $this->crypto->decrypt((string) $connection['api_token_secret_encrypted']);
        $tokenId = $this->normalizedTokenId((string) $connection['api_token_id'], (string) $connection['realm']);
        $curl = curl_init($url);
        if ($curl === false) throw new \RuntimeException('Unable to initialize noVNC asset request.');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => (bool) $connection['verify_ssl'],
            CURLOPT_SSL_VERIFYHOST => (bool) $connection['verify_ssl'] ? 2 : 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Authorization: PVEAPIToken=' . $tokenId . '=' . $secret,
            ],
        ]);
        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        curl_close($curl);

        if ($body === false) throw new \RuntimeException('Unable to load noVNC asset: ' . $curlError);
        if ($status < 200 || $status >= 300) throw new HttpException(502, 'Proxmox noVNC asset request failed.', ['upstream_status' => $status]);
        if ($contentType === '') $contentType = $this->fallbackContentType($extension);
        return ['body' => (string) $body, 'content_type' => $contentType];
    }

    private function normalizedTokenId(string $tokenId, string $realm): string
    {
        if (str_contains($tokenId, '@')) return $tokenId;
        if (str_contains($tokenId, '!')) {
            [$user, $token] = explode('!', $tokenId, 2);
            return $user . '@' . $realm . '!' . $token;
        }
        throw new \RuntimeException('Invalid Proxmox API token identifier.');
    }

    private function fallbackContentType(string $extension): string
    {
        return match ($extension) {
            'js' => 'text/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'html' => 'text/html; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map' => 'application/json; charset=utf-8',
            default => 'application/octet-stream',
        };
    }
}
