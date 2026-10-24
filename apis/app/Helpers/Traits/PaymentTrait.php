<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

trait PaymentTrait
{
    /**
     * 三方處理代收後, 回調通知交易結果的網址, 我司接收後更新存款訂單狀態
     */
    public static function depositCallbackUrl($agentCashFlowId): string
    {
        return config('custom.api_external_url') . 'external/api/deposit/notify/' . $agentCashFlowId;
    }

    /**
     * 三方處理代收後, 轉跳的前端站點網址
     */
    public static function depositReturnUrl($domain): string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? config('custom.web_frontend_url');
        $urlObj = parse_url($referer);

        $scheme = $urlObj['scheme'] ?? 'https';

        return $scheme . '://' . $domain;
    }

    /**
     * 三方處理代付後, 轉跳的前端站點網址
     */
    public static function withdrawalCallbackUrl($agentCashFlowId): string
    {
        return config('custom.api_external_url') . 'external/api/withdrawal/notify/' . $agentCashFlowId;
    }

    /**
     * 三方反查代付訂單狀態的網址
     */
    public static function withdrawalQueryUrl($agentCashFlowId): string
    {
        return config('custom.api_external_url') . 'external/api/withdrawal/query/' . $agentCashFlowId;
    }

    public static function genTransactionNo(string $prefix): string
    {
        $timeOrderNo = self::getOrderTime();

        return $prefix . $timeOrderNo;
    }

    public static function getOrderTime(): string
    {
        $timestamp = microtime(true);
        $timestampInt = (int) $timestamp;
        $datetime = date('YmdHis', $timestampInt);

        // 提取時間戳記的微秒部分並格式化為5位數字字串
        $fractionalPart = $timestamp - floor($timestamp);
        $microsecondsInt = (int) ($fractionalPart * 100000);
        $microseconds = str_pad((string) $microsecondsInt, 5, '0', STR_PAD_LEFT);

        return $datetime . $microseconds;
    }
}
