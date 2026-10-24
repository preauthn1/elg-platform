<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;

/**
 * @knowledge rules/vendor-target
 */
class BWGController extends PegasusController
{
    protected int $vendorId = VendorIdEnum::BWG->value;

    protected string $actionPrefix = 'BWGSW_get_request_';
}
