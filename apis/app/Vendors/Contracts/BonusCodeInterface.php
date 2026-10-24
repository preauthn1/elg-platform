<?php

declare(strict_types=1);

namespace App\Vendors\Contracts;

use App\Data\VendorBonusCodeRequestData;
use App\Data\VendorBonusCodeResponseData;

interface BonusCodeInterface
{
    public function getBonusCode(VendorBonusCodeRequestData $params): VendorBonusCodeResponseData;
}
