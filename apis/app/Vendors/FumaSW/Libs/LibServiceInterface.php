<?php

declare(strict_types=1);

namespace App\Vendors\FumaSW\Libs;

use App\Models\VendorInfo;
use App\Vendors\FumaSW\Datas\BetInfoData;
use App\Vendors\FumaSW\Datas\SettleInfoData;
use App\Vendors\Models\FumaswAccount;

interface LibServiceInterface
{
    /**
     * @param  BetInfoData|SettleInfoData  $dto
     */
    public function setSettings($dto, VendorInfo $vendorInfo, FumaswAccount $account): void;

    public function exec(): string;
}
