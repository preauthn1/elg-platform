<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * 遊戲清單同步結果 DTO（純新增，無更新統計）
 *
 * 記錄單次同步各資料表的新增筆數。「三方未提供」偵測屬 vendor 層，
 * 不在本 DTO：見 GameListSyncService::findGamesMissingFromUnion。
 */
class GameListSyncResultData extends Data
{
    public function __construct(
        public int $gamesInserted = 0,
        public int $agentGamesInserted = 0,
        public int $vendorInfoGamesInserted = 0,
        public int $gameNamesInserted = 0,

        /** @var array<string, string> games 表本次新增的遊戲（代碼 => 名稱） */
        public array $insertedGameCodes = [],
    ) {}
}
