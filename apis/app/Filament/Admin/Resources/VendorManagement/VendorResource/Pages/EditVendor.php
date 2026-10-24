<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorManagement\VendorResource\Pages;

use App\Enums\StatusEnum;
use App\Filament\Admin\Resources\VendorManagement\VendorResource;
use App\Models\Vendor;
use App\Services\VendorManagementService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * 遊戲商編輯頁：儲存改走 VendorManagementService（同交易寫操作紀錄、commit 後清快取），
 * 不用 Filament 預設的 $record->update()。沒有刪除按鈕，以停用取代。
 */
class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VendorResource::statusAction(StatusEnum::DISABLE),
            VendorResource::statusAction(StatusEnum::ENABLE),
        ];
    }

    /**
     * @param  Vendor  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        app(VendorManagementService::class)->update($record, $data, (int) Filament::auth()->id());

        return $record->refresh();
    }
}
