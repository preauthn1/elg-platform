<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Vendors\Models\Clotp2pswAccount;
use App\Vendors\Models\Clotp2pswBetRecord;

class ClotPlayP2PController extends ClotPlayController
{
    protected int $vendorId = VendorIdEnum::CLOT_P2P_SW->value;

    protected string $accountClass = Clotp2pswAccount::class;

    protected string $betRecordClass = Clotp2pswBetRecord::class;

    protected string $actionPrefix = 'Clotp2psw_get_request_';
}
