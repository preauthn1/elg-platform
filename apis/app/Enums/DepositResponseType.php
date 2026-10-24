<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 代收響應類型
 */
enum DepositResponseType: int
{
    /** 異常 */
    case ERROR = 0;

    /** 跳轉支付頁 */
    case URL_REDIRECT = 1;

    /** QR Code (舊版，某些金流商使用) */
    case QR_CODE_LEGACY = 2;

    /** 返回 HTML 表單 */
    case HTML_FORM = 3;

    /** 顯示成功，回錢包頁 */
    case SUCCESS = 4;

    /** TngPin 專用：顯示訊息 @deprecated */
    // case TNGPIN_MESSAGE = 9;

    /** QR Code */
    case QR_CODE = 11;

    case QR_CODE_IMAGE_PATH = 12;

    /**
     * 取得描述
     */
    public function label(): string
    {
        return match ($this) {
            self::ERROR => '異常',
            self::URL_REDIRECT => '跳轉支付頁',
            self::QR_CODE_LEGACY => 'QR Code (舊版)',
            self::HTML_FORM => '返回 HTML 表單',
            self::SUCCESS => '顯示成功，回錢包頁',
            // self::TNGPIN_MESSAGE => 'TngPin 訊息',
            self::QR_CODE => 'QR Code',
            self::QR_CODE_IMAGE_PATH => 'QR Code 圖片路徑',
        };
    }

    /**
     * 判斷是否為成功狀態
     */
    public function isSuccess(): bool
    {
        return match ($this) {
            self::URL_REDIRECT, self::QR_CODE_LEGACY, self::HTML_FORM, self::SUCCESS, self::QR_CODE,
            self::ERROR => false,
        };
    }
}
