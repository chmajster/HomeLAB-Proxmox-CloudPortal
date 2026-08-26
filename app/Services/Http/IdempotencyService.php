<?php

declare(strict_types=1);

namespace CloudPortal\Services\Http;

use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use PDO;
use PDOException;

final class IdempotencyService
{
    private const LOCK_SECONDS = 120;
    private const RETENTION_SECONDS = 86400;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int}|Response|null */
    public function begin(Request $request, ?int $userId, ?int $apiTokenId): array|Response|null
    {
        if ($userId === null || !str_starts_with($request->path, '/api/')) return null;
        if (!in_array($request->method, ['POST','PUT','PATCH','DELETE'], true)) return null;
        $key = trim((string) $request->header('idempotency-key', ''));
        if ($key === '') return null;
        if (strlen($key) < 8 || strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
            throw new HttpException(400, 'Idempotency-Key must contain 8-128 safe ASCII characters.');
        }

        $requestHash = hash('sha256', $request->method . "\n" . $request->path . "\n" . $request->rawBody());
        $expires = gmdate('Y-m-d H:i:s', time() + self::RETENTION_SECONDS);
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO api_idempotency_keys(user_id,api_token_id,method,request_path,idempotency_key,request_hash,expires_at)
                 VALUES(:user,:token,:method,:path,:key,:hash,:expires)'
            );
            $insert->execute([
                'user' => $userId, 'token' => $apiTokenId, 'method' => $request->method,
                'path' => $request->path, 'key' => $key, 'hash' => $requestHash, 'expires' => $expires,
            ]);
            return ['id' => (int) $this->pdo->lastInsertId()];
        } catch (PDOException $exception) {
            $native = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
            if ($native !== 1062) throw $exception;
        }

        $statement = $this->pdo->prepare(
            'SELECT * FROM api_idempotency_keys
             WHERE user_id=:user AND method=:method AND request_path=:path AND idempotency_key=:key LIMIT 1'
        );
        $statement->execute(['user' => $userId, 'method' => $request->method, 'path' => $request->path, 'key' => $key]);
        $row = $statement->fetch();
        if (!is_array($row)) throw new HttpException(409, 'Idempotency state changed concurrently; retry the request.');
        if (!hash_equals((string) $row['request_hash'], $requestHash)) {
            throw new HttpException(409, 'Idempotency-Key was already used with a different request payload.');
        }
        if ((string) $row['state'] === 'completed') {
            return new Response(
                (string) ($row['response_body'] ?? ''),
                (int) ($row['response_status'] ?? 200),
                ['Content-Type' => (string) ($row['response_content_type'] ?: 'application/json; charset=utf-8'), 'Idempotency-Replayed' => 'true'],
            );
        }

        $lockedAt = strtotime((string) $row['locked_at']) ?: time();
        if ($lockedAt > time() - self::LOCK_SECONDS) {
            throw new HttpException(409, 'A request with this Idempotency-Key is already processing.');
        }
        $takeover = $this->pdo->prepare(
            "UPDATE api_idempotency_keys SET locked_at=CURRENT_TIMESTAMP,expires_at=:expires
             WHERE id=:id AND state='processing' AND locked_at<:cutoff"
        );
        $takeover->execute([
            'expires' => $expires,
            'id' => $row['id'],
            'cutoff' => gmdate('Y-m-d H:i:s', time() - self::LOCK_SECONDS),
        ]);
        if ($takeover->rowCount() !== 1) {
            throw new HttpException(409, 'A request with this Idempotency-Key is already processing.');
        }
        return ['id' => (int) $row['id']];
    }

    /** @param array{id:int}|null $context */
    public function complete(?array $context, Response $response): void
    {
        if ($context === null) return;
        $contentType = $response->headers()['Content-Type'] ?? 'application/octet-stream';
        $statement = $this->pdo->prepare(
            "UPDATE api_idempotency_keys SET state='completed',response_status=:status,response_body=:body,
                    response_content_type=:content_type,expires_at=:expires
             WHERE id=:id AND state='processing'"
        );
        $statement->execute([
            'status' => $response->status(),
            'body' => $response->body(),
            'content_type' => mb_substr($contentType, 0, 100),
            'expires' => gmdate('Y-m-d H:i:s', time() + self::RETENTION_SECONDS),
            'id' => $context['id'],
        ]);
    }

    /** @param array{id:int}|null $context */
    public function release(?array $context): void
    {
        if ($context === null) return;
        $this->pdo->prepare("DELETE FROM api_idempotency_keys WHERE id=:id AND state='processing'")
            ->execute(['id' => $context['id']]);
    }

    public function cleanup(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM api_idempotency_keys WHERE expires_at<CURRENT_TIMESTAMP');
        $statement->execute();
        return $statement->rowCount();
    }
}
