<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Enums\DepositResponseType;
use App\Enums\TngPinResponseCode;
use Spatie\LaravelData\Data;

class DepositResponseData extends Data
{
    public function __construct(
        public DepositResponseType $type,
        public ?string $url,
        public ?string $html,
        public string $orderNo,
        public ?string $providerOrderNo, // 第三方金流商的交易號 orders.third_party_order_no
        // TngPin 需要用到的參數
        public ?TngPinResponseCode $code = null,
        public ?string $message = null,
    ) {}
}
