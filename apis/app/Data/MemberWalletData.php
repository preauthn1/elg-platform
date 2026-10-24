<?php

declare(strict_types=1);

namespace App\Data;

use App\Helpers\Common;
use App\Models\Member;
use App\Models\MemberWallet;
use Spatie\LaravelData\Data;

class MemberWalletData extends Data
{
    public function __construct(
        public MemberWallet|Member $wallet,
        public ?MemberWallet $rollingChipWallet = null,
        public bool $isMultipleWallet = false
    ) {}

    public function getTotalBalance(): string
    {
        $balance = $this->wallet->balance;
        if (is_object($this->rollingChipWallet)) {
            $balance = Common::numAdd($balance, $this->rollingChipWallet->balance, 4);
        }

        return $balance;
    }
}
