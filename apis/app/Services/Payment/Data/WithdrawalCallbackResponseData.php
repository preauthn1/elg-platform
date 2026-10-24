<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Models\WithdrawalOrder;
use Spatie\LaravelData\Data;

class WithdrawalCallbackResponseData extends Data
{
    public function __construct(
        public mixed $response, // 回應三方的信息
        public ?WithdrawalOrder $order,
        public ?string $errorMessage = null,
        public int $httpStatusCode = 200,
    ) {}
}
