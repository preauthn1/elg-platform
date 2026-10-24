<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Services\Payment\Enums\ProviderOrderStatusEnum;
use Spatie\LaravelData\Data;

class QueryOrderResponseData extends Data
{
    public function __construct(
        public string $type, // deposit=代收, withdraw=代付
        public string $orderNo,
        public ProviderOrderStatusEnum $orderStatus,
        public ?string $errorMessage = '', // 错误信息
        // 三方查單回報的金額；三方未回報或該 provider 未實作時為 null，不可當 0 用
        public ?string $amount = null,
    ) {}
}
