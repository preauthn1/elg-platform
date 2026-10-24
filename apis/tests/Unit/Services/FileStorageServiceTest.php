<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FileStorageService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * FileStorageService 測試
 *
 * php artisan test tests/Unit/Services/FileStorageServiceTest.php
 */
class FileStorageServiceTest extends TestCase
{
    private const MIN_PNG_BASE64 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('filesystems.default', 'local');
        Storage::fake('local');
    }

    private function service(): FileStorageService
    {
        return new FileStorageService;
    }

    public function test_store_base64_image_throws_when_not_data_uri(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid image data format');

        $this->service()->storeBase64Image('plain-text');
    }

    public function test_store_base64_image_stores_with_explicit_path(): void
    {
        $url = $this->service()->storeBase64Image(self::MIN_PNG_BASE64, 'invite/qrcode.png', true);

        Storage::disk('local')->assertExists('invite/qrcode.png');
        $this->assertNotSame('invite/qrcode.png', $url);
        $this->assertStringContainsString('qrcode.png', $url);
    }

    public function test_store_base64_image_appends_extension_when_missing(): void
    {
        $this->service()->storeBase64Image(self::MIN_PNG_BASE64, 'folder/prefix_only', true);

        Storage::disk('local')->assertExists('folder/prefix_only.png');
    }

    public function test_store_base64_image_uses_content_extension_not_declared_type(): void
    {
        $png = substr(self::MIN_PNG_BASE64, strpos(self::MIN_PNG_BASE64, ',') + 1);

        $this->service()->storeBase64Image('data:image/php;base64,' . $png, 'folder/avatar.php', true);

        Storage::disk('local')->assertExists('folder/avatar.png');
        Storage::disk('local')->assertMissing('folder/avatar.php');
    }

    public function test_store_base64_image_generates_random_name_without_path(): void
    {
        $url = $this->service()->storeBase64Image(self::MIN_PNG_BASE64);

        $this->assertMatchesRegularExpression('/[0-9a-f]{32}\.png$/', $url);
    }

    public function test_store_base64_image_rejects_non_image_content_with_image_type(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid image data format');

        $this->service()->storeBase64Image('data:image/png;base64,' . base64_encode('plain text, not an image'), 'folder/x', true);
    }

    public function test_store_base64_image_rejects_oversized_image(): void
    {
        $binary = "\x89PNG\r\n\x1A\n" . str_repeat("\0", FileStorageService::IMAGE_MAX_BYTES);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Image size exceeds limit');

        $this->service()->storeBase64Image('data:image/png;base64,' . base64_encode($binary), 'folder/x', true);
    }

    public function test_check_base64_image_accepts_heic_and_keeps_original(): void
    {
        $heic = base64_encode("\0\0\0\x18ftypheic" . str_repeat("\0", 16));

        $this->assertNull($this->service()->checkBase64Image('data:image/heic;base64,' . $heic));

        $this->service()->storeBase64Image('data:image/heic;base64,' . $heic, 'folder/photo', true);

        Storage::disk('local')->assertExists('folder/photo.heic');
    }

    public function test_check_base64_image_rejects_non_strict_base64(): void
    {
        $this->assertSame(
            FileStorageService::IMAGE_ERROR_INVALID_FORMAT,
            $this->service()->checkBase64Image('data:image/png;base64,@@not-base64@@')
        );
    }

    public function test_store_public_writes_file_and_returns_url(): void
    {
        $returned = $this->service()->store('path/to/file.bin', 'hello', true);

        Storage::disk('local')->assertExists('path/to/file.bin');
        $this->assertSame(Storage::disk('local')->url('path/to/file.bin'), $returned);
    }

    public function test_store_private_returns_path_only(): void
    {
        $returned = $this->service()->store('secret/file.dat', 'payload', false);

        $this->assertSame('secret/file.dat', $returned);
        Storage::disk('local')->assertExists('secret/file.dat');
    }
}
