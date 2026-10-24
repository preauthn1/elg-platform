<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Models\Vendor;
use App\Vendors\Contracts\HasGameList;
use App\Vendors\Contracts\NeedsGameListSourceUrl;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;

/**
 * @knowledge rules/game-list-sync
 */
class UpdateGameList extends AbstractOperationPage
{
    protected static ?int $navigationSort = 1;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::UpdateGameList;
    }

    protected function formComponents(): array
    {
        return [
            Select::make('vendor_id')
                ->label('遊戲商')
                ->options(
                    Vendor::query()
                        // 僅列 driver 具備抓取遊戲清單能力（HasGameList）的遊戲商，
                        // 其餘選了也無法被 update_game_list 觸發
                        ->whereIn('id', self::gameListCapableVendorIds())
                        ->where('name', '!=', '')
                        ->orderBy('id')
                        ->pluck('name', 'id')
                        ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                )
                ->searchable()
                // Filament Select 預設 optionsLimit=50，會截斷完整遊戲商清單
                ->optionsLimit(500)
                // 清單來源網址欄位要跟著所選遊戲商顯示／隱藏
                ->live()
                ->required(),
            TextInput::make('sheet_url')
                ->label('遊戲清單來源網址')
                ->helperText('直接貼遊戲商給的活頁簿連結即可，系統會自動轉成下載網址')
                ->url()
                ->maxLength(1000)
                // 只有 driver 標 NeedsGameListSourceUrl 的遊戲商（目前只有 PTSW 144）需要填；
                // 不硬寫 vendor id，日後有第二家走同一模式即自動生效
                ->visible(fn (Get $get): bool => self::needsSourceUrl($get('vendor_id')))
                ->required(fn (Get $get): bool => self::needsSourceUrl($get('vendor_id'))),
            Toggle::make('dry_run')
                ->label('乾跑模式（不寫入資料庫）'),
        ];
    }

    /**
     * 該遊戲商的 driver 是否需要外部提供清單來源網址
     */
    private static function needsSourceUrl(mixed $vendorId): bool
    {
        if (blank($vendorId)) {
            return false;
        }

        $driverClass = config('vendor.driver')[(int) $vendorId] ?? null;

        return is_string($driverClass) && is_a($driverClass, NeedsGameListSourceUrl::class, true);
    }

    /**
     * driver 實作 HasGameList 的 vendor id 清單（含聚合 driver 掛的所有 id）
     *
     * @return list<int>
     */
    private static function gameListCapableVendorIds(): array
    {
        return collect(config('vendor.driver'))
            ->filter(fn (string $driver): bool => is_a($driver, HasGameList::class, true))
            ->keys()
            ->all();
    }

    protected function buildParams(array $data): array
    {
        // --direct：同步執行不派發 queue，繞過 Job 唯一鎖（排程卡住時仍可救援），
        // 同步結果統計得以回寫 admin_operation_logs 供頁面顯示
        // --show-list：固定附上三方回傳的完整遊戲清單，供客服直接在執行輸出
        // 確認「某遊戲三方到底有沒有提供」（已在 DB 的遊戲不會出現在差異統計裡）
        $params = [
            '--vendor-id' => (string) $data['vendor_id'],
            '--direct' => true,
            '--show-list' => true,
        ];

        if ($data['dry_run'] ?? false) {
            $params['--dry-run'] = true;
        }

        // ⚠️ 這條網址是憑證，會原文存進 admin_operation_logs.params——
        // RunAdminOperationJob 得從該列讀回參數才組得出 artisan 指令，無法只存遮罩版。
        // 能看到操作紀錄的人就能看到它，換連結時舊紀錄裡的舊連結也還在。
        if (filled($data['sheet_url'] ?? null)) {
            $params['--sheet-url'] = (string) $data['sheet_url'];
        }

        return $params;
    }
}
