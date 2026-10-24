<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW\Libs;

use App\Models\VendorInfo;
use App\Vendors\Models\TfgamingswAccount;
use App\Vendors\TFGamingSW\Datas\TransferInfoData;

interface LibServiceInterface
{
    public function setSettings(TransferInfoData $dto, VendorInfo $vendorInfo, TfgamingswAccount $account): void;

    public function exec(): string;
}
