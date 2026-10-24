<?php

declare(strict_types=1);

namespace App\CacheKeys\Traits;

trait ExternalCache
{
    /**
     * 外部 MegaWin 遊戲商資訊
     */
    public static function externalMegawinswVendorInfo(string $mwCode): string
    {
        return "external:megawinsw:vendorInfo:{$mwCode}";
    }

    public static function externalRefreshRankingRecord(int $agentId, string $date): string
    {
        return "external:refresh_ranking_record:{$agentId}:{$date}";
    }
}
