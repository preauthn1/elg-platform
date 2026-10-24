<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;

interface WidgetGameInterface
{
    public function getWidgetUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData;
}
