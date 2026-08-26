<?php

declare(strict_types=1);

namespace CloudPortal\Services\Auth;

use CloudPortal\Security\Crypto;
use PDO;

final class MfaService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const PERIOD = 30;
    private const DIGITS = 6;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Crypto $crypto,
    ) {
    }

    /** @return array{secret:string,otpauth_uri:string,recovery_codes:list<string>} */
    public function createSetup(int $userId, string $issuer, string $account): array
    {
        $this->assertActiveUser($userId);
        $secret = $this->base32Encode(random_bytes(20));
        $issuer = trim($issuer) === '' ? 'Algen Cloud Portal' : trim($issuer);
        $account = trim($account) === '' ? ('user-' . $userId) : trim($account);
        $label = rawurlencode($issuer . ':' . $account);
        $uri = 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;

        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return ['secret' => $secret, 'otpauth_uri' => $uri, 'recovery_codes' => $codes];
    }

    /** @param list<string> $recoveryCodes */
    public function enable(int $userId, string $secret, string $code, array $recoveryCodes): void
    {
        $this->assertActiveUser($userId);
        if (!$this->verifyTotpSecret($secret, $code)) {
            throw new \RuntimeException('Invalid MFA verification code.');
        }
        if (count($recoveryCodes) < 8) {
            throw new \RuntimeException('MFA setup does not contain enough recovery codes.');
        }

        $this->atomic('mfa_enable', function () use ($userId, $secret, $recoveryCodes): void {
            $this->pdo->prepare(
                'UPDATE users SET mfa_enabled=1,mfa_secret_encrypted=:secret,mfa_enabled_at=CURRENT_TIMESTAMP WHERE id=:id'
            )->execute(['secret' => $this->crypto->encrypt($secret), 'id' => $userId]);
            $this->pdo->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id=:user')->execute(['user' => $userId]);
            $insert = $this->pdo->prepare('INSERT INTO user_mfa_recovery_codes(user_id,code_hash) VALUES(:user,:hash)');
            foreach ($recoveryCodes as $recoveryCode) {
                $normalized = $this->normalizeRecoveryCode($recoveryCode);
                if ($normalized === '') {
                    throw new \RuntimeException('Invalid MFA recovery code generated during setup.');
                }
                $insert->execute(['user' => $userId, 'hash' => password_hash($normalized, PASSWORD_DEFAULT)]);
            }
        });
    }

    public function disable(int $userId): void
    {
        $this->atomic('mfa_disable', function () use ($userId): void {
            $this->pdo->prepare(
                'UPDATE users SET mfa_enabled=0,mfa_secret_encrypted=NULL,mfa_enabled_at=NULL,session_version=session_version+1 WHERE id=:id'
            )->execute(['id' => $userId]);
            $this->pdo->prepare('DELETE FROM user_mfa_recovery_codes WHERE user_id=:user')->execute(['user' => $userId]);
        });
    }

    public function enabled(int $userId): bool
    {
        $statement = $this->pdo->prepare('SELECT mfa_enabled FROM users WHERE id=:id AND status=\'active\' LIMIT 1');
        $statement->execute(['id' => $userId]);
        return (int) $statement->fetchColumn() === 1;
    }

    public function verify(int $userId, string $code): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        $statement = $this->pdo->prepare(
            'SELECT mfa_enabled,mfa_secret_encrypted FROM users WHERE id=:id AND status=\'active\' LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user) || (int) $user['mfa_enabled'] !== 1 || !is_string($user['mfa_secret_encrypted'])) {
            return false;
        }

        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $this->verifyTotpSecret($this->crypto->decrypt($user['mfa_secret_encrypted']), $code);
        }

        return $this->consumeRecoveryCode($userId, $code);
    }

    public function verifyTotpSecret(string $secret, string $code, ?int $timestamp = null): bool
    {
        if (preg_match('/^\d{6}$/', trim($code)) !== 1) {
            return false;
        }
        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);
        for ($window = -1; $window <= 1; $window++) {
            if (hash_equals($this->totp($secret, $counter + $window), trim($code))) {
                return true;
            }
        }
        return false;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM user_mfa_recovery_codes WHERE user_id=:user AND used_at IS NULL');
        $statement->execute(['user' => $userId]);
        return (int) $statement->fetchColumn();
    }

    private function consumeRecoveryCode(int $userId, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }

        return $this->atomic('mfa_recovery', function () use ($userId, $normalized): bool {
            $statement = $this->pdo->prepare(
                'SELECT id,code_hash FROM user_mfa_recovery_codes WHERE user_id=:user AND used_at IS NULL FOR UPDATE'
            );
            $statement->execute(['user' => $userId]);
            foreach ($statement->fetchAll() as $candidate) {
                if (password_verify($normalized, (string) $candidate['code_hash'])) {
                    $update = $this->pdo->prepare(
                        'UPDATE user_mfa_recovery_codes SET used_at=CURRENT_TIMESTAMP WHERE id=:id AND used_at IS NULL'
                    );
                    $update->execute(['id' => $candidate['id']]);
                    return $update->rowCount() === 1;
                }
            }
            return false;
        });
    }

    private function totp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $high = intdiv($counter, 0x100000000);
        $low = $counter & 0xffffffff;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (unpack('C*', $binary) ?: [] as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }
        return $output;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
        if ($encoded === '') {
            throw new \RuntimeException('MFA secret is invalid.');
        }
        $bits = '';
        foreach (str_split($encoded) as $char) {
            $value = strpos(self::BASE32_ALPHABET, $char);
            if ($value === false) {
                throw new \RuntimeException('MFA secret is invalid.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $output .= chr(bindec($chunk));
        }
        return $output;
    }

    /** @template T @param callable():T $callback @return T */
    private function atomic(string $savepoint, callable $callback): mixed
    {
        $nested = $this->pdo->inTransaction();
        if ($nested) {
            $this->pdo->exec('SAVEPOINT ' . $savepoint);
        } else {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();
            if ($nested) {
                $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $exception) {
            if ($nested && $this->pdo->inTransaction()) {
                try {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (\Throwable) {
                }
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($code)) ?? '');
    }

    private function assertActiveUser(int $userId): void
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE id=:id AND status=\'active\' LIMIT 1');
        $statement->execute(['id' => $userId]);
        if (!$statement->fetchColumn()) {
            throw new \RuntimeException('Active user does not exist.');
        }
    }
}
