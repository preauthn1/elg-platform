<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * TngPin 響應碼
 */
enum TngPinResponseCode: int
{
    /** 前端會維持在原頁 */
    case STAY_ON_PAGE = 998;

    /** 前端會返回支付頁 */
    case RETURN_TO_PAYMENT = 999;

    /**
     * 取得描述
     */
    public function label(): string
    {
        return match ($this) {
            self::STAY_ON_PAGE => '前端會維持在原頁',
            self::RETURN_TO_PAYMENT => '前端會返回支付頁',
        };
    }
}
