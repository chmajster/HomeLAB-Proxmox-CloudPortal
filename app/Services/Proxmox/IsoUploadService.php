<?php

declare(strict_types=1);

namespace CloudPortal\Services\Proxmox;

use CloudPortal\Http\HttpException;

final class IsoUploadService
{
    public const CHUNK_SIZE = 4 * 1024 * 1024;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes = 17179869184,
    ) {
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('ISO upload limit must be positive.');
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function initialize(array $input, int $userId): array
    {
        $this->ensureDirectory();
        $this->cleanupExpired();
        $filename = trim((string) ($input['filename'] ?? ''));
        $size = filter_var($input['size'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $this->maxBytes]]);
        $connectionId = filter_var($input['connection_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $node = trim((string) ($input['node'] ?? ''));
        $storage = trim((string) ($input['storage'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}\.iso$/i', $filename) !== 1) {
            throw new HttpException(422, 'Nazwa pliku ISO może zawierać tylko litery, cyfry, kropki, myślniki i podkreślenia.');
        }
        if ($size === false) {
            throw new HttpException(422, 'Rozmiar ISO jest nieprawidłowy albo przekracza limit ' . $this->maxBytes . ' bajtów.');
        }
        if ($connectionId === false || !$this->validResourceId($node) || !$this->validResourceId($storage)) {
            throw new HttpException(422, 'Nieprawidłowy cel uploadu ISO.');
        }

        $id = bin2hex(random_bytes(16));
        $metadata = [
            'id' => $id,
            'user_id' => $userId,
            'filename' => $filename,
            'size' => (int) $size,
            'received' => 0,
            'connection_id' => (int) $connectionId,
            'node' => $node,
            'storage' => $storage,
            'status' => 'receiving',
            'created_at' => time(),
        ];
        $part = @fopen($this->partPath($id), 'x+b');
        if ($part === false) {
            throw new HttpException(500, 'Nie udało się utworzyć tymczasowego pliku ISO.');
        }
        fclose($part);
        @chmod($this->partPath($id), 0600);
        try {
            $this->createMetadata($id, $metadata);
        } catch (\Throwable $exception) {
            @unlink($this->partPath($id));
            throw $exception;
        }
        return $this->publicMetadata($metadata);
    }

    /** @return array<string,mixed> */
    public function append(string $id, int $userId, int $offset, string $chunk): array
    {
        if ($offset < 0 || $chunk === '' || strlen($chunk) > self::CHUNK_SIZE) {
            throw new HttpException(422, 'Fragment ISO jest pusty, za duży albo ma nieprawidłowy offset.');
        }
        [$handle, $metadata] = $this->lockedMetadata($id, $userId);
        try {
            if (($metadata['status'] ?? null) !== 'receiving') {
                throw new HttpException(409, 'Ta sesja uploadu nie przyjmuje już fragmentów.');
            }
            $received = (int) ($metadata['received'] ?? -1);
            $size = (int) ($metadata['size'] ?? 0);
            if ($offset !== $received) {
                throw new HttpException(409, 'Nieprawidłowa kolejność fragmentów ISO. Oczekiwany offset: ' . $received . '.');
            }
            if ($received + strlen($chunk) > $size) {
                throw new HttpException(422, 'Fragment przekracza zadeklarowany rozmiar ISO.');
            }
            $part = @fopen($this->partPath($id), 'r+b');
            if ($part === false || !flock($part, LOCK_EX)) {
                if (is_resource($part)) fclose($part);
                throw new HttpException(500, 'Nie udało się zablokować tymczasowego pliku ISO.');
            }
            try {
                $actual = fstat($part)['size'] ?? -1;
                if ((int) $actual !== $received || fseek($part, $received) !== 0) {
                    throw new HttpException(409, 'Stan tymczasowego pliku ISO jest niespójny. Rozpocznij upload ponownie.');
                }
                $written = 0;
                $length = strlen($chunk);
                while ($written < $length) {
                    $result = fwrite($part, substr($chunk, $written));
                    if ($result === false || $result === 0) {
                        ftruncate($part, $received);
                        throw new HttpException(500, 'Nie udało się zapisać fragmentu ISO.');
                    }
                    $written += $result;
                }
                fflush($part);
            } finally {
                flock($part, LOCK_UN);
                fclose($part);
            }
            $metadata['received'] = $received + strlen($chunk);
            $this->rewriteMetadata($handle, $metadata);
            return $this->publicMetadata($metadata);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param callable(array<string,mixed>,string):mixed $uploader
     * @return array<string,mixed>
     */
    public function complete(string $id, int $userId, callable $uploader): array
    {
        [$handle, $metadata] = $this->lockedMetadata($id, $userId);
        try {
            if (($metadata['status'] ?? null) !== 'receiving' || (int) ($metadata['received'] ?? -1) !== (int) ($metadata['size'] ?? 0)) {
                throw new HttpException(409, 'ISO nie zostało jeszcze przesłane w całości.');
            }
            if (!is_file($this->partPath($id)) || filesize($this->partPath($id)) !== (int) $metadata['size']) {
                throw new HttpException(409, 'Rozmiar tymczasowego ISO nie zgadza się z sesją uploadu.');
            }
            $metadata['status'] = 'uploading';
            $this->rewriteMetadata($handle, $metadata);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        try {
            $result = $uploader($metadata, $this->partPath($id));
            @unlink($this->partPath($id));
            @unlink($this->metadataPath($id));
            return ['upload' => $this->publicMetadata($metadata), 'proxmox_result' => $result];
        } catch (\Throwable $exception) {
            $this->restoreReceivingStatus($id, $userId);
            throw $exception;
        }
    }

    public function cancel(string $id, int $userId): void
    {
        [$handle, $metadata] = $this->lockedMetadata($id, $userId);
        try {
            if (($metadata['status'] ?? null) === 'uploading') {
                throw new HttpException(409, 'Upload do Proxmox już trwa i nie może zostać anulowany.');
            }
            $metadata['status'] = 'cancelled';
            $this->rewriteMetadata($handle, $metadata);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        @unlink($this->partPath($id));
        @unlink($this->metadataPath($id));
    }

    /** @return array<string,mixed> */
    public function metadata(string $id, int $userId): array
    {
        [$handle, $metadata] = $this->lockedMetadata($id, $userId);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $metadata;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new HttpException(500, 'Nie udało się przygotować katalogu uploadów ISO.');
        }
        @chmod($this->directory, 0700);
        if (!is_writable($this->directory)) {
            throw new HttpException(500, 'Katalog uploadów ISO nie jest zapisywalny.');
        }
    }

    private function cleanupExpired(): void
    {
        $threshold = time() - 86400;
        foreach (glob($this->directory . '/*.json') ?: [] as $path) {
            if ((filemtime($path) ?: time()) >= $threshold) continue;
            $id = pathinfo($path, PATHINFO_FILENAME);
            if (!$this->validId($id)) continue;
            $handle = @fopen($path, 'c+');
            if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
                if (is_resource($handle)) fclose($handle);
                continue;
            }
            $this->readMetadata($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($this->partPath($id));
            @unlink($path);
        }
    }

    /** @param array<string,mixed> $metadata */
    private function createMetadata(string $id, array $metadata): void
    {
        $handle = @fopen($this->metadataPath($id), 'x+b');
        if ($handle === false) throw new HttpException(500, 'Nie udało się utworzyć sesji uploadu ISO.');
        try {
            $this->rewriteMetadata($handle, $metadata);
            @chmod($this->metadataPath($id), 0600);
        } finally {
            fclose($handle);
        }
    }

    /** @return array{resource,array<string,mixed>} */
    private function lockedMetadata(string $id, int $userId): array
    {
        if (!$this->validId($id)) throw new HttpException(404, 'Sesja uploadu ISO nie istnieje.');
        $handle = @fopen($this->metadataPath($id), 'r+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            throw new HttpException(404, 'Sesja uploadu ISO nie istnieje.');
        }
        $metadata = $this->readMetadata($handle);
        if ((int) ($metadata['user_id'] ?? 0) !== $userId || ($metadata['id'] ?? null) !== $id) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new HttpException(404, 'Sesja uploadu ISO nie istnieje.');
        }
        return [$handle, $metadata];
    }

    /** @param resource $handle @return array<string,mixed> */
    private function readMetadata($handle): array
    {
        rewind($handle);
        try {
            $metadata = json_decode(stream_get_contents($handle) ?: '', true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(409, 'Metadane uploadu ISO są uszkodzone.');
        }
        if (!is_array($metadata)) throw new HttpException(409, 'Metadane uploadu ISO są uszkodzone.');
        return $metadata;
    }

    /** @param resource $handle @param array<string,mixed> $metadata */
    private function rewriteMetadata($handle, array $metadata): void
    {
        $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new HttpException(500, 'Nie udało się zapisać metadanych uploadu ISO.');
        }
    }

    private function restoreReceivingStatus(string $id, int $userId): void
    {
        try {
            [$handle, $metadata] = $this->lockedMetadata($id, $userId);
            try {
                $metadata['status'] = 'receiving';
                $this->rewriteMetadata($handle, $metadata);
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable $exception) {
            error_log('Unable to restore ISO upload session: ' . $exception->getMessage());
        }
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    private function publicMetadata(array $metadata): array
    {
        return [
            'id' => (string) $metadata['id'],
            'filename' => (string) $metadata['filename'],
            'size' => (int) $metadata['size'],
            'received' => (int) $metadata['received'],
            'connection_id' => (int) $metadata['connection_id'],
            'node' => (string) $metadata['node'],
            'storage' => (string) $metadata['storage'],
            'status' => (string) $metadata['status'],
            'chunk_size' => self::CHUNK_SIZE,
            'complete' => (int) $metadata['received'] === (int) $metadata['size'],
        ];
    }

    private function metadataPath(string $id): string { return $this->directory . '/' . $id . '.json'; }
    private function partPath(string $id): string { return $this->directory . '/' . $id . '.part'; }
    private function validId(string $id): bool { return preg_match('/^[a-f0-9]{32}$/', $id) === 1; }
    private function validResourceId(string $id): bool { return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/', $id) === 1; }
}
