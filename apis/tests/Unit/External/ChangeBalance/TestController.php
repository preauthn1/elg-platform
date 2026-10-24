<?php

declare(strict_types=1);

namespace Tests\Unit\External\ChangeBalance;

use App\Data\MemberWalletData;
use App\Http\Controllers\External\SingleWallets\ClotPlayController;
use App\Models\Member;
use App\Models\VendorInfo;
use App\Vendors\Models\AbstractBetRecord;

class TestController extends ClotPlayController
{
    public function changeBalanceTest(
        Member $member,
        MemberWalletData $walletDto,
        ?AbstractBetRecord $beforeBetRecord,
        AbstractBetRecord $afterBetRecord,
        bool $autoSave = true,
    ): int {
        return $this->changeBalance($member, $walletDto, $beforeBetRecord, $afterBetRecord, $autoSave);
    }

    public function setVendorInfoTest(VendorInfo $vendorInfo)
    {
        $this->vendorInfo = $vendorInfo;
    }
}
