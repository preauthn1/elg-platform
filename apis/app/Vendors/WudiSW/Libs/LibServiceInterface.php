<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Libs;

use App\Models\VendorInfo;
use App\Vendors\Models\WudiswAccount;
use App\Vendors\WudiSW\Datas\TransferInfoData;

interface LibServiceInterface
{
    public function setSettings(TransferInfoData $dto, VendorInfo $vendorInfo, WudiswAccount $account): void;

    public function exec(): string;
}
