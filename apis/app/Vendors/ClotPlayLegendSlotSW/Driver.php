<?php

declare(strict_types=1);

namespace App\Vendors\ClotPlayLegendSlotSW;

use App\Vendors\ClotPlaySW\Driver as ClotPlaySWDriver;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Models\ClotlsswAccount;
use App\Vendors\Models\ClotlsswBetRecord;

class Driver extends ClotPlaySWDriver implements HasCallbackPath
{
    protected string $accountModel = ClotlsswAccount::class;

    protected string $betRecordModel = ClotlsswBetRecord::class;

    protected string $actionTitle = 'CLOTLS';

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 clotls 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/clotls';
    }
}
