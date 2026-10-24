<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Models\GgrPlan;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

/**
 * GGR 推廣代理發放的手動觸發入口（target/tickets#4121 批次七）。
 *
 * 把 ggr_settlements 的未發放列入帳到代理餘額並標為已發放。冪等：已發放列
 * 會被狀態守門跳過，重複觸發不會重複入帳；代理停用的列會留在未發放、
 * 下次觸發（或每日 GMT+8 16:00 排程）自動重試。同步執行（--direct），任一列
 * 失敗會如實反映在操作紀錄狀態與輸出。
 */
class GgrPayout extends AbstractOperationPage
{
    protected static ?int $navigationSort = 5;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::GgrPayout;
    }

    protected function formComponents(): array
    {
        return [
            Select::make('plan_id')
                ->label('GGR 方案')
                ->placeholder('全部方案的未發放列')
                ->helperText('留空即發放全部未發放結算列')
                ->options(
                    GgrPlan::query()
                        ->orderBy('id')
                        ->pluck('name', 'id')
                        ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                )
                ->searchable(),
            Toggle::make('dry_run')
                ->label('乾跑模式（唯讀列出將發放清單，不入帳、不改狀態）'),
        ];
    }

    protected function buildParams(array $data): array
    {
        // --direct：同步執行不入 queue，失敗回非 0 讓操作紀錄如實顯示（比照 GgrSettlement 頁）
        $params = [
            !empty($data['dry_run']) ? '--dry-run' : '--direct' => true,
        ];

        if (($data['plan_id'] ?? null) !== null && $data['plan_id'] !== '') {
            $params['--plan'] = (string) $data['plan_id'];
        }

        return $params;
    }
}
