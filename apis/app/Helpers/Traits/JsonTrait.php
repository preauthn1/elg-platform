<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use Illuminate\Support\Facades\Log;

trait JsonTrait
{
    /**
     * 統一 JSON 編碼旗標
     */
    private static int $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * 安全解析 JSON（建議優先使用）
     */
    public static function safeJsonDecode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    /**
     * 安全 JSON 編碼（建議優先使用）
     */
    public static function safeJsonEncode(mixed $data): string
    {
        try {
            return json_encode($data, self::$jsonFlags);
        } catch (\JsonException $e) {
            Log::error('JSON encode error: ' . $e->getMessage(), ['data' => $data]);

            return '[]';
        }
    }
}
