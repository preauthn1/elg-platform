<?php

declare(strict_types=1);

namespace App\Vendors\VpowerSW\Libs;

use App\Models\VendorInfo;
use App\Vendors\Models\VpowerswAccount;
use App\Vendors\VpowerSW\Datas\BetInfoData;

interface LibServiceInterface
{
    public function setSettings(BetInfoData $dto, VendorInfo $vendorInfo, VpowerswAccount $account);

    public function exec(): string;
}
