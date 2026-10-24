<?php

declare(strict_types=1);

namespace App\Vendors\ClotPlayP2PSW;

use App\Vendors\ClotPlaySW\Driver as ClotPlaySWDriver;
use App\Vendors\Contracts\HasCallbackPath;
use App\Vendors\Models\Clotp2pswAccount;
use App\Vendors\Models\Clotp2pswBetRecord;

class Driver extends ClotPlaySWDriver implements HasCallbackPath
{
    protected string $accountModel = Clotp2pswAccount::class;

    protected string $betRecordModel = Clotp2pswBetRecord::class;

    protected string $actionTitle = 'CLOTP2PSW';

    /**
     * 預設回調路徑後綴，對應 routes/external/sw.php 的 clotp2p 路由群組
     */
    public static function defaultCallbackPath(): string
    {
        return '/external/api/sw/clotp2p';
    }
}
