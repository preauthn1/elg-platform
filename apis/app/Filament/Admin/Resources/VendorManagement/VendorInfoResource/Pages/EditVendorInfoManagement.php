<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VendorManagement\VendorInfoResource\Pages;

use App\Filament\Admin\Resources\VendorManagement\VendorInfoResource;
use App\Helpers\Common;
use App\Services\VendorManagementService;
use Filament\Resources\Pages\EditRecord;

/**
 * 遊戲商商戶設定編輯頁：info 的憑證在表單上只有遮罩值，儲存前必須還原沒改的憑證，
 * 還原不了的遮罩值一律擋下，否則遮罩字串（****1234）會蓋掉 DB 裡的真實憑證。
 * 沒有刪除按鈕，理由見 VendorInfoPolicy。
 */
class EditVendorInfoManagement extends EditRecord
{
    protected static string $resource = VendorInfoResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (!is_array($data['info'] ?? null)) {
            return $data;
        }

        // 以 DB 現值還原，不用開頁時載入的 record：開頁後若別人換過憑證，用舊 record 還原會把新憑證蓋回舊值；
        // 用現值還原則遮罩對不上、由 assertNoUnrestoredMaskedSecrets() 擋下
        $original = VendorInfoResource::decodeInfo($this->getRecord()->fresh()?->info);
        if ($original !== null) {
            $data['info'] = Common::restoreMaskedVendorInfo($data['info'], $original);
        }
        VendorManagementService::assertNoUnrestoredMaskedSecrets($data['info']);

        return $data;
    }
}
