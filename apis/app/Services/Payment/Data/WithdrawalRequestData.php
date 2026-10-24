<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Models\Agent;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use Spatie\LaravelData\Data;

class WithdrawalRequestData extends Data
{
    public function __construct(
        public ?WithdrawalOrder $order = null,  // 已查詢好的 WithdrawalOrder，避免重複查詢
        public Agent|Member|null $owner = null, // 訂單擁有者（Agent 或 Member）
    ) {}
}
