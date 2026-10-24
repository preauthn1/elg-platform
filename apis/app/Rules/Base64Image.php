<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\FileStorageService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * base64 圖片（data URI）：解碼後依檔案內容判斷格式，可收的格式見 FileStorageService::IMAGE_EXTENSIONS，大小上限見 FileStorageService::IMAGE_MAX_BYTES
 */
class Base64Image implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail(self::message(FileStorageService::IMAGE_ERROR_INVALID_FORMAT));

            return;
        }

        $error = app(FileStorageService::class)->checkBase64Image($value);

        if ($error !== null) {
            $fail(self::message($error));
        }
    }

    /**
     * 錯誤訊息：格式清單與大小上限由常數帶入語系的 :formats／:max
     */
    public static function message(string $error): string
    {
        return __('error_message.image_upload.' . $error, [
            'formats' => implode('/', FileStorageService::IMAGE_EXTENSIONS),
            'max' => intdiv(FileStorageService::IMAGE_MAX_BYTES, 1024) . 'KB',
        ]);
    }
}
