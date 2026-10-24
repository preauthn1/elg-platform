<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentManagement\CashFlowResource\Pages;

use App\Filament\Admin\Resources\PaymentManagement\CashFlowResource;
use App\Models\CashFlow;
use App\Services\CashFlowManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * 金流商編輯頁：儲存改走 {@see CashFlowManagementService}（同交易寫操作紀錄），
 * 不用 Filament 預設的 $record->update()——那會把表單送上來的所有欄位直接寫進去。
 *
 * 沒有刪除按鈕，也沒有停用／啟用（理由見 {@see CashFlowResource} 的類別註解）。
 */
class EditCashFlow extends EditRecord
{
    protected static string $resource = CashFlowResource::class;

    /**
     * @param  CashFlow  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        app(CashFlowManagementService::class)->updateWhitelist($record, $data, (int) Filament::auth()->id());

        return $record->refresh();
    }
}
