<?php

declare(strict_types=1);

namespace App\Data\Vip;

use Spatie\LaravelData\Data;

class RebateRecordData extends Data
{
    public function __construct(
        public ?float $total_bet,
        public ?float $rebate_amount,
    ) {}
}
