<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * total_bet_records 基礎欄位 DTO（driver formatBaseTotalBetRecord 產出）
 *
 * 只承載 driver 可自行決定的欄位；member_id / game_id / valid_bet_rate /
 * summary_time 由 BetRecordFetchService 解析補齊後組成最終 upsert 列。
 */
class BaseTotalBetRecordData extends Data
{
    public function __construct(
        /** 帳號表 betRecordAccountColumn() 欄位的值（member 解析用） */
        public string $accountKey,

        /** 遊戲代碼（對應 games.game_type，game 解析用） */
        public string $gameCode,

        public string $referenceNo,

        public string $bet,

        public string $validBet,

        public string $winLose,

        public string $betTime,

        public string $payoutTime,
    ) {}
}
