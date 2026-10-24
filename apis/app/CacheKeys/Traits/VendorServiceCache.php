<?php

declare(strict_types=1);

namespace App\CacheKeys\Traits;

trait VendorServiceCache
{
    public static function vendorServiceVendorIds(int $agentId): string
    {
        return "vendorIds:a{$agentId}";
    }

    public static function vendorServiceEventWalletVendorIds(int $agentId): string
    {
        return "eventWalletVendorIds:a{$agentId}";
    }

    public static function vendorServiceVendorInfos(int $agentId): string
    {
        return "vendorInfos:a{$agentId}";
    }

    public static function vendorServiceEventWalletVendorInfos(int $agentId): string
    {
        return "eventWalletVendorInfos:a{$agentId}";
    }
}
