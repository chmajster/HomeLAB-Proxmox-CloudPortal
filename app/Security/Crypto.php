<?php

declare(strict_types=1);

namespace CloudPortal\Security;

final class Crypto
{
    private readonly string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('APP_KEY must be a base64-encoded 32-byte key.');
        }
        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key));
    }

    public function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Encrypted value is malformed.');
        }
        $nonce = substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plaintext === false) {
            throw new \RuntimeException('Encrypted value cannot be decrypted.');
        }
        return $plaintext;
    }
}

