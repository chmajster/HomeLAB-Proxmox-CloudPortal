<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

final class ConsoleToken
{
    private readonly string $key;

    public function __construct(string $secret)
    {
        if ($secret === '') throw new \InvalidArgumentException('Console token secret cannot be empty.');
        if (!function_exists('sodium_crypto_secretbox')) throw new \RuntimeException('The sodium extension is required for noVNC console tokens.');
        $this->key = hash('sha256', $secret, true);
    }

    /** @param array<string,mixed> $claims */
    public function issue(array $claims, int $ttlSeconds = 20): string
    {
        if ($ttlSeconds < 5 || $ttlSeconds > 120) throw new \InvalidArgumentException('Invalid console token TTL.');
        $now = time();
        $payload = json_encode([
            ...$claims,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'nonce_id' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($payload, $nonce, $this->key);
        return $this->encode($nonce . $ciphertext);
    }

    /** @return array<string,mixed> */
    public function verify(string $token): array
    {
        if ($token === '' || strlen($token) > 8192) throw new \RuntimeException('Invalid console token.');
        $packed = $this->decode($token);
        if (strlen($packed) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) throw new \RuntimeException('Invalid console token.');
        $nonce = substr($packed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($packed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) throw new \RuntimeException('Invalid console token.');
        try {
            $claims = json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Invalid console token.');
        }
        if (!is_array($claims) || !isset($claims['exp']) || (int) $claims['exp'] < time()) throw new \RuntimeException('Console token expired.');
        if ((int) ($claims['iat'] ?? 0) > time() + 5) throw new \RuntimeException('Invalid console token timestamp.');
        return $claims;
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) throw new \RuntimeException('Invalid console token.');
        $padding = strlen($value) % 4;
        if ($padding !== 0) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) throw new \RuntimeException('Invalid console token.');
        return $decoded;
    }
}
