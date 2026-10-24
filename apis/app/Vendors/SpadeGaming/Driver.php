<?php

declare(strict_types=1);

namespace App\Vendors\SpadeGaming;

use App\Vendors\Models\SpadegamingAccount;
use App\Vendors\Models\SpadegamingBetRecord;
use App\Vendors\NextSpin;

/**
 * @method authorize() 與轉帳錢包遊戲模式共用 authorize 接口，詳見 routes/external/tw.php
 */
class Driver extends NextSpin\Driver
{
    protected string $accountModel = SpadegamingAccount::class;

    protected string $betRecordModel = SpadegamingBetRecord::class;

    protected string $actionTitle = 'SPADEGAMING';
}
