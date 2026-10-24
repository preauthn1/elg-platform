<?php

declare(strict_types=1);

namespace App\Enums\Event;

/**
 * prizes.type_id 獎勵遊戲類型
 *
 * 1-4 依 API_Frontend EventController 的 API 文件註解；
 * 5-7、10 依實際邏輯分支（抽獎券表分流與達標多次獎勵）。
 */
enum PrizeTypeEnum: int
{
    // 刮刮卡
    case SCRATCH = 1;
    // 輪盤
    case ROULETTE = 2;
    // 直接兌獎
    case DIRECT = 3;
    // 抽獎券
    case LOTTERY_TICKET = 4;
    // V-Jackpot（走 lucky_draws）
    case V_JACKPOT = 5;
    // W-Jackpot（走 w_jackpots）
    case W_JACKPOT = 6;
    // W-Jackpot 6D（走 lucky_draws，以此 type 與 V-Jackpot 區分）
    case W_JACKPOT_6D = 7;
    // 達標多次獎勵（每日可領次數以 prizes.number 為上限）
    case MULTI_TIER_ACHIEVEMENT = 10;
}
