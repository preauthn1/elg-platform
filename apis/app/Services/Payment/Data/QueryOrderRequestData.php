<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Models\Order;
use App\Models\WithdrawalOrder;
use Spatie\LaravelData\Data;

class QueryOrderRequestData extends Data
{
    public function __construct(
        public string $type, // deposit=代收, withdraw=代付
        public Order|WithdrawalOrder $order,
    ) {}
}
