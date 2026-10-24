<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

use App\Data\Vendor\GetGameListParamsData;
use App\Data\Vendor\GetGameListResultData;

/**
 * 契約層：宣告遊戲商 Driver 具備「向遊戲商抓取遊戲清單」的能力。
 *
 * 實作本介面即可被 update_game_list --vendor-id 手動觸發（含 Olympus 系統操作），
 * 但不會進自動排程；需自動排程請標 ShouldSyncGameList（繼承本契約的 marker）。
 *
 * 遊戲商改為純手動同步時，將 implements ShouldSyncGameList 降級為本介面：
 * 退出排程，getGameList 的型別約束仍然保留。
 */
interface HasGameList
{
    /**
     * 向遊戲商取得遊戲清單
     */
    public function getGameList(GetGameListParamsData $params): GetGameListResultData;
}
