<?php

declare(strict_types=1);

namespace App\Data\Vendor;

use Spatie\LaravelData\Data;

/**
 * 單一商戶（vendor_info 層）的帳號池補充結果
 */
class MerchantAccountPoolResultData extends Data
{
    public function __construct(
        public int $vendorInfoId,

        /** 檢查當下的未綁定帳號數（member_id = 0 且啟用） */
        public int $unboundCount,

        /** 安全庫存目標（vendor_info.prepared_account_number） */
        public int $preparedAccountNumber,

        /** 本輪需補建的帳號數（水位足夠時為 0） */
        public int $lackNumber = 0,

        /** @var list<string> 本輪成功建立的帳號 */
        public array $createdUsernames = [],

        /** @var list<string> 本輪建立失敗的帳號（driver 回報失敗或拋例外） */
        public array $failedUsernames = [],
    ) {}
}
