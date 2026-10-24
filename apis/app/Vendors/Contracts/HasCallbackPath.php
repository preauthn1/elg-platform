<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

/**
 * 契約層：無縫錢包 Driver 宣告遊戲商打回調時的預設路徑後綴。
 *
 * 廠商管理列表的回調路徑：vendors.callback_path 有值用 DB，為 NULL 時用本介面宣告的預設；
 * 完整網址為 config('custom.api_external_url') 去尾斜線後接上後綴。
 *
 * - 後綴必須以 `/` 開頭，對應 routes/external/sw.php 的路由群組，
 *   例如 `/external/api/sw/tthsw`（external/api 前綴見 bootstrap/app.php）。
 * - 只有 AbstractSingleWallet 的子類實作；轉帳錢包沒有回調路徑。
 * - 方法為 static：推導時只有 class 字串，不實例化 Driver。
 *
 * @knowledge rules/vendor-and-channel-management
 */
interface HasCallbackPath
{
    /**
     * 預設回調路徑後綴（以 `/` 開頭）
     */
    public static function defaultCallbackPath(): string;
}
