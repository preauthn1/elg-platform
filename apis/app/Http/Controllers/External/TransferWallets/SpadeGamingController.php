<?php

namespace App\Http\Controllers\External\TransferWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Vendors\Models\SpadegamingAccount;
use App\Vendors\Models\SpadegamingBetRecord;

class SpadeGamingController extends NextSpinController
{
    protected int $vendorId = VendorIdEnum::SG->value;

    protected string $accountClass = SpadegamingAccount::class;

    protected string $betRecordClass = SpadegamingBetRecord::class;

    protected string $vendorInfoOperatorField = 'Merchant_Code';

    protected string $actionPrefix = 'SG_get_request_';
}
