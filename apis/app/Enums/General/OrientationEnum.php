<?php

declare(strict_types=1);

namespace App\Enums\General;

/**
 * 前端裝置螢幕方向，由請求標頭 `X-Orientation` 帶入
 *
 * @see app/Http/Middleware/DetectDevice.php
 */
enum OrientationEnum: string
{
    case UNKNOWN = 'unknown'; // 未帶標頭或無法辨識
    case LANDSCAPE = 'landscape'; // 橫屏
    case PORTRAIT = 'portrait'; // 直屏

    /**
     * 由 `X-Orientation` 標頭解析，未帶或無法辨識一律視為 UNKNOWN
     */
    public static function fromHeader(?string $header): self
    {
        if ($header === null) {
            return self::UNKNOWN;
        }

        return self::tryFrom(strtolower(trim($header))) ?? self::UNKNOWN;
    }
}
