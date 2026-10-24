<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentManagement\CashFlowResource\Pages;

use App\Filament\Admin\Resources\PaymentManagement\CashFlowResource;
use Filament\Resources\Pages\ListRecords;

class ListCashFlow extends ListRecords
{
    protected static string $resource = CashFlowResource::class;
}
