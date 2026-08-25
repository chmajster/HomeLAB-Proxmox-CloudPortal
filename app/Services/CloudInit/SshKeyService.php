<?php

declare(strict_types=1);

namespace CloudPortal\Services\CloudInit;

use CloudPortal\Http\HttpException;
use PDO;

final class SshKeyService
{
    private const ALLOWED_TYPES = [
        'ssh-ed25519',
        'ssh-rsa',
        'ecdsa-sha2-nistp256',
        'ecdsa-sha2-nistp384',
        'ecdsa-sha2-nistp521',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id,name,key_type,fingerprint,public_key,created_at,updated_at
             FROM user_ssh_keys WHERE user_id=:user ORDER BY created_at DESC,id DESC'
        );
        $statement->execute(['user' => $userId]);
        return $statement->fetchAll();
    }

    public function create(int $userId, string $name, string $publicKey): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new HttpException(422, 'Nazwa klucza SSH jest wymagana i może mieć maksymalnie 100 znaków.');
        }
        $parsed = self::parsePublicKey($publicKey);
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO user_ssh_keys (user_id,name,key_type,fingerprint,public_key)
                 VALUES (:user,:name,:type,:fingerprint,:key)'
            );
            $statement->execute([
                'user' => $userId,
                'name' => $name,
                'type' => $parsed['type'],
                'fingerprint' => $parsed['fingerprint'],
                'key' => $parsed['public_key'],
            ]);
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException(409, 'Ten klucz SSH jest już zapisany na Twoim koncie.');
            }
            throw $exception;
        }
        return (int) $this->pdo->lastInsertId();
    }

    public function delete(int $userId, int $keyId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM user_ssh_keys WHERE id=:id AND user_id=:user');
        $statement->execute(['id' => $keyId, 'user' => $userId]);
        if ($statement->rowCount() !== 1) {
            throw new HttpException(404, 'Klucz SSH nie istnieje albo nie należy do tego użytkownika.');
        }
    }

    /** @param mixed $value @return list<int> */
    public static function ids(mixed $value): array
    {
        if ($value === null || $value === '') return [];
        $items = is_array($value) ? $value : preg_split('/\s*,\s*/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $ids = [];
        foreach ($items ?: [] as $item) {
            $id = filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) throw new HttpException(422, 'Lista identyfikatorów kluczy SSH jest nieprawidłowa.');
            $ids[(int) $id] = true;
        }
        return array_keys($ids);
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    public function resolve(int $userId, array $ids): array
    {
        if ($ids === []) return [];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id,name,key_type,fingerprint,public_key FROM user_ssh_keys
             WHERE user_id=? AND id IN ({$placeholders}) ORDER BY id"
        );
        $statement->execute([$userId, ...$ids]);
        $rows = $statement->fetchAll();
        if (count($rows) !== count($ids)) {
            throw new HttpException(422, 'Co najmniej jeden wybrany klucz SSH nie istnieje albo nie należy do właściciela VM.');
        }
        return $rows;
    }

    /** @return array{type:string,fingerprint:string,public_key:string} */
    public static function parsePublicKey(string $publicKey): array
    {
        $publicKey = trim($publicKey);
        if ($publicKey === '' || strlen($publicKey) > 16384 || str_contains($publicKey, "\n") || str_contains($publicKey, "\r")) {
            throw new HttpException(422, 'Publiczny klucz SSH jest nieprawidłowy. Podaj jeden klucz w formacie OpenSSH.');
        }
        $parts = preg_split('/\s+/', $publicKey, 3);
        $type = (string) ($parts[0] ?? '');
        $encoded = (string) ($parts[1] ?? '');
        if (!in_array($type, self::ALLOWED_TYPES, true) || $encoded === '' || preg_match('/^[A-Za-z0-9+\/=]+$/', $encoded) !== 1) {
            throw new HttpException(422, 'Obsługiwane są klucze ssh-ed25519, ssh-rsa oraz ECDSA nistp256/384/521.');
        }
        $binary = base64_decode($encoded, true);
        if (!is_string($binary) || strlen($binary) < 16) {
            throw new HttpException(422, 'Dane Base64 klucza SSH są nieprawidłowe.');
        }
        $fingerprint = 'SHA256:' . rtrim(base64_encode(hash('sha256', $binary, true)), '=');
        return ['type' => $type, 'fingerprint' => $fingerprint, 'public_key' => $publicKey];
    }
}
