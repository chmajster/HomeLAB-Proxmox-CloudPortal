<?php

declare(strict_types=1);

namespace CloudPortal\Services\Auth;

use CloudPortal\Http\HttpException;
use PDO;

final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxAttempts,
        private readonly int $windowSeconds,
        private readonly int $lockoutSeconds,
    ) {
    }

    /** @return list<string> */
    public function acquire(string $identity, string $ip): array
    {
        $names = [
            'portal_login_i_' . substr(hash('sha256', mb_strtolower(trim($identity))), 0, 40),
            'portal_login_p_' . substr(hash('sha256', $ip), 0, 40),
        ];
        sort($names, SORT_STRING);
        $acquired = [];
        foreach ($names as $name) {
            $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, 5)');
            $statement->execute(['name' => $name]);
            if ((int) $statement->fetchColumn() !== 1) {
                $this->release($acquired);
                throw new HttpException(503, 'Login service is temporarily busy. Try again.');
            }
            $acquired[] = $name;
        }
        return $acquired;
    }

    /** @param list<string> $locks */
    public function release(array $locks): void
    {
        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        foreach (array_reverse($locks) as $name) {
            $statement->execute(['name' => $name]);
        }
    }

    public function ensureAllowed(string $identity, string $ip): void
    {
        $hash = hash('sha256', mb_strtolower(trim($identity)));
        $since = gmdate('Y-m-d H:i:s', time() - max($this->windowSeconds, $this->lockoutSeconds));
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE successful = 0 AND created_at >= :since AND (identity_hash = :identity OR ip_address = :ip)'
        );
        $statement->execute(['since' => $since, 'identity' => $hash, 'ip' => $ip]);
        if ((int) $statement->fetchColumn() >= $this->maxAttempts) {
            throw new HttpException(429, 'Too many login attempts. Try again later.');
        }
    }

    public function record(string $identity, string $ip, bool $successful): void
    {
        $statement = $this->pdo->prepare('INSERT INTO login_attempts (identity_hash, ip_address, successful) VALUES (:identity, :ip, :successful)');
        $statement->execute([
            'identity' => hash('sha256', mb_strtolower(trim($identity))),
            'ip' => $ip,
            'successful' => (int) $successful,
        ]);
        if (random_int(1, 100) === 1) {
            $this->pdo->prepare('DELETE FROM login_attempts WHERE created_at < :cutoff')->execute([
                'cutoff' => gmdate('Y-m-d H:i:s', time() - 86400 * 30),
            ]);
        }
    }
}
