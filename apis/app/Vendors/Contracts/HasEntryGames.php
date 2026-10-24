<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

/**
 * 契約層：宣告遊戲商有「我方自建的入口遊戲」，遊戲清單同步一律不得碰。
 *
 * 這類 games 列不是三方 API 回傳的遊戲，而是我方自建、供前台當作該廠商的進入點
 * （大廳、體育／電競總入口等）。它們永遠不會出現在 getGameList 的結果裡，
 * 因此差集比對會把它們判成「DB 有、API 無」而停用，導致前台整個廠商進不去。
 *
 * 標記本介面後，`entryGameCodes()` 列出的代碼會在同步時整批排除：
 * 不停用、不新增、也不更新——三方即使日後回傳同名代碼也不覆蓋我方自建的名稱與圖。
 *
 * 聚合遊戲商（同一 Driver 掛多個 vendor id）若各 id 的入口代碼不同，
 * 於實作內依 `$this->vendor->id` 分支即可。
 */
interface HasEntryGames
{
    /**
     * 我方自建的入口遊戲代碼（對應 `games.game_type`）
     *
     * @return list<string>
     */
    public function entryGameCodes(): array;
}
