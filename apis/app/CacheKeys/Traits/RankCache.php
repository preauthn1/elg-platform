<?php

declare(strict_types=1);

namespace App\CacheKeys\Traits;

trait RankCache
{
    /**
     * 遊戲贏家列表
     */
    public static function gameWinnerList(string $startTime, int $agentId): string
    {
        return "game_winner_list:list:{$startTime}:{$agentId}";
    }

    /**
     * 盈利列表
     */
    public static function getProfitList(string $startTime, int $agentId): string
    {
        return "get_profit_list:list:{$startTime}:{$agentId}";
    }

    /**
     * 盈利列表
     */
    public static function rankingFakeList(int $agentId, string $date): string
    {
        return "ranking_fake_list:{$agentId}:{$date}";
    }

    /**
     * 中獎排行榜
     *
     * 查詢結果含 IFNULL(gn.name, g.name) 語系相依欄位，key 須帶 languageId，
     * 否則不同語系會共用同一份快取、互相污染 game_name 顯示。
     */
    public static function winningRankingList(int $agentId, int $type, int $languageId): string
    {
        return "winning_ranking_list:list:{$agentId}:{$type}:{$languageId}";
    }

    /**
     * 平台投注排行榜
     */
    public static function platformBettingRankingList(int $agentId, int $type): string
    {
        return "platform_betting_rankings:list:{$agentId}:{$type}";
    }
}
