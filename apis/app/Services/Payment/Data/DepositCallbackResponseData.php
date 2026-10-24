<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Models\Order;
use Spatie\LaravelData\Data;

class DepositCallbackResponseData extends Data
{
    public function __construct(
        public mixed $response,
        public ?Order $order,
        public ?string $errorMessage = null,
        public int $httpStatusCode = 200,
    ) {}
}
