<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Member;
use App\Models\MemberWallet;
use Spatie\LaravelData\Data;

class MemberAllWalletData extends Data
{
    public function __construct(
        public Member $mainWallet,
        public ?MemberWallet $gameWallet,
        public MemberWallet $rollingChipWallet,
        public string $walletBalance,
        public bool $isMultipleWallet
    ) {}
}
