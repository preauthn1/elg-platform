<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use Spatie\LaravelData\Data;

/**
 * 參照 API_Agent ThirdPartyApiPayoutResult
 */
class WithdrawalResponseData extends Data
{
    public function __construct(
        public bool $isSuccess,
        public ?string $providerOrderNo, // 三方金流商的交易號，寫入 withdrawal_orders.third_party_order_no
        public mixed $providerResponse, // 三方金流商的回應內容，若為陣列以 json_encode 寫入 withdrawal_orders.third_party_response
        public ?string $errorMessage,
        public bool $isIndeterminate = false, // 請求例外（逾時／連線失敗）＝結果未知，訂單停留 21 待人工定案，不得轉 29 開放重送
    ) {}
}
