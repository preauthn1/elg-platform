<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Models\Vendor;
use App\Vendors\Contracts\SupportsAccountCreation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

/**
 * @knowledge rules/vendor-account-pool
 */
class CreateVendorAccount extends AbstractOperationPage
{
    protected static ?int $navigationSort = 4;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::CreateVendorAccount;
    }

    protected function formComponents(): array
    {
        return [
            Select::make('vendor_id')
                ->label('遊戲商')
                ->options(
                    Vendor::query()
                        // 僅列 driver 具備建帳號能力（SupportsAccountCreation）的遊戲商，
                        // 其餘選了也無法被 create_vendor_account 觸發
                        ->whereIn('id', self::accountCreationCapableVendorIds())
                        ->where('name', '!=', '')
                        ->orderBy('id')
                        ->pluck('name', 'id')
                        ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                )
                ->searchable()
                // Filament Select 預設 optionsLimit=50，會截斷完整遊戲商清單
                ->optionsLimit(500)
                ->helperText('(4) 4D／Haolong 三方限制建號時段：台北 14:00–22:00（UTC 06:00–14:00）不受理，該時段執行會整批略過、不會新增帳號，請於其他時段補建')
                ->required(),
            Toggle::make('dry_run')
                ->label('乾跑模式（不寫入資料庫；已送出三方的建號請求不會撤銷）'),
        ];
    }

    /**
     * driver 實作 SupportsAccountCreation 的 vendor id 清單（含聚合 driver 掛的所有 id）
     *
     * @return list<int>
     */
    private static function accountCreationCapableVendorIds(): array
    {
        return collect(config('vendor.driver'))
            ->filter(fn (string $driver): bool => is_a($driver, SupportsAccountCreation::class, true))
            ->keys()
            ->all();
    }

    protected function buildParams(array $data): array
    {
        // --direct：同步執行不派發 per-vendor job，補建結果統計得以回寫
        // admin_operation_logs 供頁面顯示（口徑同「更新遊戲清單」操作頁）
        $params = [
            '--vendor-id' => (string) $data['vendor_id'],
            '--direct' => true,
        ];

        if ($data['dry_run'] ?? false) {
            $params['--dry-run'] = true;
        }

        return $params;
    }
}
