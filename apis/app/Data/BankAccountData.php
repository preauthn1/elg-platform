<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class BankAccountData extends Data
{
    public function __construct(
        public int $order_type_id,
        public int $bank_id,
        public string $bank_name,
        public string $bank_code,
        public int $currency_id,
        public string $account_name,
        public ?string $account_nickname,
        public string $account_no,
        public ?string $bank_icon,
        public ?string $qrcode,
        public int $agent_cash_flow_id,
        public string $rate,
        public int $min_amount,
        public int $max_amount,
    ) {}
}
