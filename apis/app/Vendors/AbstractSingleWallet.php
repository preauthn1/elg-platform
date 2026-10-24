<?php

declare(strict_types=1);

namespace App\Vendors;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;

abstract class AbstractSingleWallet extends BaseAbstractVendor
{
    // 取得遊戲網址
    abstract public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData;
}
