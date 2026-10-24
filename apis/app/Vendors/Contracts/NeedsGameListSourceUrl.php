<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

/**
 * 契約層：宣告遊戲商的遊戲清單來源網址必須由觸發者提供，Driver 自己沒有。
 *
 * 適用「三方不給清單 API、改以一份帶存取權杖的檔案連結發佈」的遊戲商（目前只有 PTSW，
 * 見 App\Vendors\PlayTechSW\GameListSheet）。那條連結是**憑證**：不需登入即可下載
 * 該廠商全站遊戲代碼，且會被撤銷、改權限或隨新檔失效。
 *
 * 刻意不落在 config／env：寫進 repo 等同永久留在 git 歷史，落 env 則要四個白標的部署端
 * 各自注入、換連結得走一輪發布。改由 update_game_list --sheet-url 於執行當下帶入
 * （Olympus 系統操作頁在選到該遊戲商時要求填寫），只存在於該次執行的記憶體中。
 *
 * 未提供時 getGameList 應拋 ApiException，不得靜默回空清單——空清單會讓
 * GameListSyncService 誤判成「三方未提供」而跳過偵測。
 */
interface NeedsGameListSourceUrl
{
    /**
     * 設定本次同步要用的清單來源網址
     */
    public function setGameListSourceUrl(string $url): void;
}
