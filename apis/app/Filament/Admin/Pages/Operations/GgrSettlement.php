<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Operations;

use App\Enums\AdminOperationEnum;
use App\Models\GgrPlan;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

/**
 * GGR 推廣代理結算的手動補跑／重算入口（target/tickets#4121 批次六）。
 *
 * 補跑 catch-up 指定方案（或全部啟用方案）截至指定業務日的未結算期間；
 * 已有完成標記的期間會被跳過，已發放（PAID）的結算列不會被覆蓋。
 * 同步執行（--direct），結算失敗會如實反映在操作紀錄狀態與輸出。
 *
 * 截至業務日最晚只能選昨日：今天所在的期間尚未結束，結算會拿不完整的資料落庫、
 * 連同完成標記與 settled_through 一起推進，當日排程之後就不會再重算該期。
 * 命令端（ggr:settle）有同一道驗證，這裡的 maxDate 只是提前擋在畫面上。
 *
 * ⚠️ 重跑「中間期」會使其後已結期的結轉（carry）鏈失準——需要重算歷史期
 * 時，應從最早的目標期起依序重跑其後所有未發放期。
 */
class GgrSettlement extends AbstractOperationPage
{
    protected static ?int $navigationSort = 4;

    public static function operation(): AdminOperationEnum
    {
        return AdminOperationEnum::GgrSettlement;
    }

    protected function formComponents(): array
    {
        return [
            Select::make('plan_id')
                ->label('GGR 方案')
                ->placeholder('全部啟用中方案')
                ->helperText('留空即跑全部啟用中方案')
                ->options(
                    GgrPlan::query()
                        ->orderBy('id')
                        ->pluck('name', 'id')
                        ->map(fn (string $name, int $id): string => sprintf('(%d) %s', $id, $name)),
                )
                ->searchable(),
            DatePicker::make('date')
                ->label('結算截至業務日（含，GMT+8）')
                ->native(false)
                ->displayFormat('Y-m-d')
                ->helperText('只能選昨日以前；今天所在的期間尚未結束不予結算')
                ->default(fn (): string => Carbon::now(self::DEFAULT_TIMEZONE)->subDay()->format('Y-m-d'))
                ->minDate('2026-01-01')
                // closure 求值：admin 頁長駐不重載，寫死常數會在跨日後停在舊的昨日
                ->maxDate(fn (): string => Carbon::now(self::DEFAULT_TIMEZONE)->subDay()->format('Y-m-d'))
                ->required(),
            Toggle::make('dry_run')
                ->label('乾跑模式（同步試算不落庫，結果見執行輸出）'),
        ];
    }

    protected function buildParams(array $data): array
    {
        // --direct：同步執行不入 queue，任一方案失敗即回非 0，操作紀錄才不會把
        // 「已派發」誤標成功（比照 FetchBetRecord）；乾跑以 --dry-run 取代（同為同步、不落庫）
        $params = [
            '--date' => Carbon::parse($data['date'])->format('Y-m-d'),
            !empty($data['dry_run']) ? '--dry-run' : '--direct' => true,
        ];

        if (($data['plan_id'] ?? null) !== null && $data['plan_id'] !== '') {
            $params['--plan'] = (string) $data['plan_id'];
        }

        return $params;
    }
}
