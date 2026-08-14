<?php

declare(strict_types=1);

namespace CloudPortal\Tests\Unit;

use CloudPortal\Http\HttpException;
use CloudPortal\Services\Proxmox\IsoUploadService;
use PHPUnit\Framework\TestCase;

final class IsoUploadServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cloud-iso-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) @unlink($file);
        @rmdir($this->directory);
    }

    public function testChunkedUploadRequiresExactOffsetsAndCompletesOwnedSession(): void
    {
        $service = new IsoUploadService($this->directory, 100);
        $upload = $service->initialize([
            'filename' => 'ubuntu-24.04.iso', 'size' => 6, 'connection_id' => 7, 'node' => 'pve-1', 'storage' => 'local',
        ], 11);

        self::assertSame(0, $upload['received']);
        self::assertSame(IsoUploadService::CHUNK_SIZE, $upload['chunk_size']);
        self::assertSame(3, $service->append($upload['id'], 11, 0, 'abc')['received']);
        try {
            $service->append($upload['id'], 11, 0, 'def');
            self::fail('An out-of-order ISO chunk was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->status);
        }
        self::assertTrue($service->append($upload['id'], 11, 3, 'def')['complete']);
        $result = $service->complete($upload['id'], 11, static function (array $metadata, string $path): string {
            self::assertSame('ubuntu-24.04.iso', $metadata['filename']);
            self::assertSame('abcdef', file_get_contents($path));
            return 'UPID:test:upload';
        });

        self::assertSame('UPID:test:upload', $result['proxmox_result']);
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testUploadSessionCannotBeReadByAnotherAdministrator(): void
    {
        $service = new IsoUploadService($this->directory, 100);
        $upload = $service->initialize([
            'filename' => 'safe.iso', 'size' => 3, 'connection_id' => 1, 'node' => 'pve', 'storage' => 'local',
        ], 5);

        $this->expectException(HttpException::class);
        $service->append($upload['id'], 6, 0, 'abc');
    }

    public function testUnsafeFilenameAndOversizedIsoAreRejected(): void
    {
        $service = new IsoUploadService($this->directory, 10);
        foreach ([
            ['filename' => '../escape.iso', 'size' => 3],
            ['filename' => 'large.iso', 'size' => 11],
        ] as $invalid) {
            try {
                $service->initialize([...$invalid, 'connection_id' => 1, 'node' => 'pve', 'storage' => 'local'], 5);
                self::fail('Unsafe ISO metadata was accepted.');
            } catch (HttpException $exception) {
                self::assertSame(422, $exception->status);
            }
        }
    }
}
