<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

/**
 * 註冊層 marker：標記即納入 update_game_list 自動排程（hourly 逐商派發）。
 *
 * getGameList 契約由父介面 HasGameList 承擔，本介面不額外宣告方法。
 *
 * - 自動排程的遊戲商範圍由 config('vendor.driver') 中有實作本介面的 driver 決定，
 *   不以 vendor id 分界線對照。
 * - ⚠️ 聚合遊戲商（同一 Driver 掛多個 vendor id）標記後旗下所有 vendor id 一併納入。
 * - 改為純手動同步：implements 降級為 HasGameList，退出排程但保留契約。
 */
interface ShouldSyncGameList extends HasGameList {}
