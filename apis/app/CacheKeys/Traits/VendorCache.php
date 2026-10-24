<?php

declare(strict_types=1);

namespace App\CacheKeys\Traits;

trait VendorCache
{
    /**
     * 遊戲商資訊
     */
    public static function vendor(int $vendorId): string
    {
        return "Vendor:{$vendorId}";
    }

    /**
     * 遊戲商帳戶
     */
    public static function vendorAccount(int $vendorInfoId, int $memberId): string
    {
        return "VendorAccount:{$vendorInfoId}:{$memberId}";
    }

    /**
     * 遊戲商資訊 (一般版本)
     */
    public static function vendorInfo(int $agentId, int $vendorId): string
    {
        return "vendorInfo:a{$agentId}:v{$vendorId}";
    }

    /**
     * 遊戲商資訊 (Event V2 版本)
     */
    public static function eventV2VendorInfo(int $agentId, int $vendorId): string
    {
        return "eventV2VendorInfo:a{$agentId}:v{$vendorId}";
    }
}
