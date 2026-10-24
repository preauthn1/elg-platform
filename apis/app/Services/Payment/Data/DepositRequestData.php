<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Models\Agent;
use App\Models\Member;
use App\Models\Order;
use Spatie\LaravelData\Data;

class DepositRequestData extends Data
{
    public function __construct(
        public Member|Agent $member,
        public Order $order,
        public string $callbackUrl = '', // Common::depositReturnUrl()
        public string $returnUrl = '', // Common::depositReturnUrl()
        public string $ip = '', // Common::getIp()
    ) {}
}
