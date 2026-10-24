<?php

declare(strict_types=1);

namespace App\Data\Vendor;

use Spatie\LaravelData\Data;

/**
 * 單一遊戲商（vendor 層）的帳號池補充結果
 *
 * 由 VendorAccountPoolService 產出：逐啟用商戶的水位檢查與補建統計。
 */
class VendorAccountPoolResultData extends Data
{
    public function __construct(
        public int $vendorId,

        public string $vendorName,

        /** @var list<MerchantAccountPoolResultData> */
        public array $merchantResults = [],

        /** 單商處理失敗訊息；非 null 時其餘欄位為失敗前的部分狀態 */
        public ?string $error = null,
    ) {}
}
