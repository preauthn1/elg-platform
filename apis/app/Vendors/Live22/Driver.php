<?php

declare(strict_types=1);

namespace App\Vendors\Live22;

use App\Enums\Vendor\VendorChannelEnum;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Evo\Driver as EvoDriver;
use App\Vendors\Models\L4Account;
use App\Vendors\Models\L4BetRecord;

class Driver extends EvoDriver implements HasAggregatorChannel
{
    protected string $accountModel = L4Account::class;

    protected string $betRecordModel = L4BetRecord::class;

    /**
     * 接入通道：經由聚合商 TitanHub
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::TITAN_HUB;
    }
}
