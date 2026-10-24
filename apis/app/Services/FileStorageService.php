<?php

declare(strict_types=1);

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Storage;

class FileStorageService
{
    /** 上傳圖片的大小上限（解碼後） */
    public const int IMAGE_MAX_BYTES = 500 * 1024;

    /** 上傳圖片可接受的格式（detectImageExtension 會回的副檔名） */
    public const array IMAGE_EXTENSIONS = ['jpg', 'png', 'gif', 'webp', 'heic', 'heif'];

    public const string IMAGE_ERROR_INVALID_FORMAT = 'invalid_format';

    public const string IMAGE_ERROR_TOO_LARGE = 'too_large';

    /** heic／heif 的 ftyp brand */
    private const array HEIF_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1'];

    /**
     * 存 base64 圖片：格式依檔案內容判斷，不採信 data URI 宣告的類型；副檔名與檔名由伺服器產生
     *
     * @param  string  $filePath  存放路徑（不含副檔名），留空則放根目錄並以亂數命名
     *
     * @throws Exception
     */
    public function storeBase64Image(string $base64Content, string $filePath = '', bool $isPublic = true): string
    {
        $error = $this->checkBase64Image($base64Content);

        if ($error === self::IMAGE_ERROR_TOO_LARGE) {
            throw new Exception('Image size exceeds limit');
        }

        if ($error !== null) {
            throw new Exception('Invalid image data format');
        }

        $binary = $this->decodeBase64Payload($base64Content);
        $extension = $this->detectImageExtension($binary);

        if (!$filePath) {
            $filePath = bin2hex(random_bytes(16));
        }

        // 副檔名一律依檔案內容決定，呼叫端帶的副檔名不採用
        $filePath = preg_replace('/\.[a-zA-Z0-9]+$/', '', $filePath) . '.' . $extension;

        return $this->store($filePath, $binary, $isPublic);
    }

    /**
     * 檢查 base64 圖片（data:<任意類型>;base64,<內容>），通過回 null，否則回 IMAGE_ERROR_* 其中一個
     */
    public function checkBase64Image(string $base64Content): ?string
    {
        if (preg_match('/^data:[^;,]*;base64,/', $base64Content, $matches) !== 1) {
            return self::IMAGE_ERROR_INVALID_FORMAT;
        }

        // 先用編碼長度擋掉明顯超過上限的內容，避免整包解碼
        if (strlen($base64Content) - strlen($matches[0]) > (int) ceil(self::IMAGE_MAX_BYTES / 3) * 4) {
            return self::IMAGE_ERROR_TOO_LARGE;
        }

        $binary = $this->decodeBase64Payload($base64Content);

        if ($binary === '') {
            return self::IMAGE_ERROR_INVALID_FORMAT;
        }

        if (strlen($binary) > self::IMAGE_MAX_BYTES) {
            return self::IMAGE_ERROR_TOO_LARGE;
        }

        return $this->detectImageExtension($binary) === null ? self::IMAGE_ERROR_INVALID_FORMAT : null;
    }

    /**
     * 依檔案開頭的特徵碼判斷圖片格式，回副檔名；不在白名單（jpg／png／gif／webp／heic／heif）回 null
     */
    public function detectImageExtension(string $binary): ?string
    {
        if (str_starts_with($binary, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        if (str_starts_with($binary, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }

        if (str_starts_with($binary, 'GIF87a') || str_starts_with($binary, 'GIF89a')) {
            return 'gif';
        }

        if (str_starts_with($binary, 'RIFF') && substr($binary, 8, 4) === 'WEBP') {
            return 'webp';
        }

        if (substr($binary, 4, 4) === 'ftyp') {
            $brand = substr($binary, 8, 4);

            if (in_array($brand, self::HEIF_BRANDS, true)) {
                return in_array($brand, ['mif1', 'msf1'], true) ? 'heif' : 'heic';
            }
        }

        return null;
    }

    /**
     * 上傳檔案到 Google Cloud Storage
     *
     * @param  string  $filePath  要存的檔名, 例如: invite_event_qrcodes/UA3MC4WGC.png
     * @param  string  $fileContent  檔案內容
     * @param  bool  $isPublic  是否公開
     * @return string 上傳後的 GCS 檔案路徑
     */
    public function store(string $filePath, string $fileContent, bool $isPublic = true): string
    {
        $disk = config('filesystems.default');

        Storage::disk($disk)->put(
            path: $filePath,
            contents: $fileContent,
            options: [
                'visibility' => $isPublic ? 'public' : 'private',
            ]
        );

        // return $filePath;
        return ($isPublic) ? Storage::disk($disk)->url($filePath) : $filePath;
    }

    /**
     * 嚴格解碼 data URI 的 base64 內容；不是合法 base64 回空字串
     */
    private function decodeBase64Payload(string $base64Content): string
    {
        $binary = base64_decode(substr($base64Content, strpos($base64Content, ',') + 1), true);

        return $binary === false ? '' : $binary;
    }
}
