<?php

namespace App\Http\Controllers\External\SingleWallets;

use App\Enums\Vendor\VendorIdEnum;
use App\Vendors\Models\ClotlsswAccount;
use App\Vendors\Models\ClotlsswBetRecord;

class ClotPlayLsController extends ClotPlayController
{
    protected int $vendorId = VendorIdEnum::CLOT_LEGEND_SLOT_SW->value;

    protected string $accountClass = ClotlsswAccount::class;

    protected string $betRecordClass = ClotlsswBetRecord::class;

    protected string $actionPrefix = 'Clotlssw_get_request_';
}
