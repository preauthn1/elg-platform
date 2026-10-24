<?php

declare(strict_types=1);

namespace App\Vendors\Connect93;

use App\Enums\Vendor\VendorChannelEnum;
use App\Vendors\Contracts\HasAggregatorChannel;
use App\Vendors\Evo\Driver as EvoDriver;
use App\Vendors\Models\C93Account;
use App\Vendors\Models\C93BetRecord;

class Driver extends EvoDriver implements HasAggregatorChannel
{
    protected string $accountModel = C93Account::class;

    protected string $betRecordModel = C93BetRecord::class;

    /**
     * 接入通道：經由聚合商 TitanHub
     */
    public static function aggregatorChannel(): VendorChannelEnum
    {
        return VendorChannelEnum::TITAN_HUB;
    }
}
