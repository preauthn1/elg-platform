<?php

declare(strict_types=1);

namespace App\CacheKeys\Traits;

trait GameCache
{
    /**
     * 遊戲列表重導向 URL
     */
    public static function gameListRedirectUrl(int $agentId): string
    {
        return "GameListRedirectUrl:a{$agentId}";
    }

    /**
     * 有效代理遊戲
     */
    public static function validAgentGames(int $agentId, int $gameId): string
    {
        return "validAgentGames:a{$agentId}:g{$gameId}";
    }
}
