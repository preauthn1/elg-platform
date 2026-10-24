<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * 單一商戶的遊戲清單同步結果
 *
 * `syncResult` 為 null 代表該商戶未取得三方清單（回空或缺漏），
 * 本輪未執行同步；此時 `gameList` 為空陣列。
 */
class MerchantGameListSyncResultData extends Data
{
    public function __construct(
        public int $vendorInfoId,
        public string $vendorInfoName,
        /** @var list<GameInfoData> 三方回傳的完整遊戲清單 */
        public array $gameList = [],
        public ?GameListSyncResultData $syncResult = null,
        /** @var list<string> 本商戶服務的遊戲商個性化組別名稱（agent_groups.agent_group_type = 5） */
        public array $agentGroupNames = [],
    ) {}
}
