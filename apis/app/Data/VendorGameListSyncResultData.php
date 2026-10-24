<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * 單一遊戲商（vendor 層）的遊戲清單同步結果
 *
 * 由 GameListSyncService::syncVendor() 產出：逐商戶同步統計，
 * 加上 vendor 層「三方未提供」聯集偵測結果。
 */
class VendorGameListSyncResultData extends Data
{
    public function __construct(
        public int $vendorId,

        public string $vendorName,

        /** @var list<MerchantGameListSyncResultData> */
        public array $merchantResults = [],

        /** 全部啟用商戶是否都成功取得清單；false 時本輪跳過「三方未提供」偵測 */
        public bool $unionComplete = true,

        /** @var array<string, string> 三方未提供但仍啟用的遊戲（代碼 => 名稱） */
        public array $missingGameCodes = [],

        /** @var list<string> 我方自建入口遊戲代碼，本輪同步已整批排除 */
        public array $entryGameCodes = [],

        /** 單商同步失敗訊息；非 null 時其餘欄位為失敗前的部分狀態 */
        public ?string $error = null,
    ) {}
}
