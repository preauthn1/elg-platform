<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Models\AgentCashFlow;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 收斂 agent_cash_flows.cash_flow_type 便利欄＝cash_flows.type。
 *
 * 【分類】Maintenance — 可複用資料收斂工具（寫入），排程常駐。
 *
 * ## 發生原因
 * `cash_flow_type` 是 denormalize 便利欄（權威＝cash_flows.type，供 GUI 直接 SQL／報表
 * 免 join 判代收代付）。{@see \App\Observers\AgentCashFlowObserver} 只攔得到 Eloquent
 * 路徑；舊系統 API_Agent 後台建 ACF 不帶此欄、raw SQL 亦繞過 Observer，於是留下
 * NULL（未同步）或與 CF 不一致（drift）的列。
 *
 * ## 修正目的
 * 全表對 cash_flows.type 收斂：NULL 補齊、不一致矯正，含軟刪列（對帳／客服查歷史
 * 也要看得到正確 type）。懸空 cash_flow_id（CF 不存在）無真值可繼承，跳過並報告。
 *
 * ## 觸發時機
 * 1. 排程 daily（routes/console.php，帶 --execute）
 * 2. GUI 查到 cash_flow_type 為 NULL／可疑 drift 時手動跑
 *
 * ## 冪等性與重跑安全性
 * 冪等：收斂到與權威一致即無事可做，重跑零寫入。寫入不觸 updated_at
 * （避免污染以 updated_at 回推的批次誤傷稽核）。預設 dry-run，--execute 才寫入。
 *
 * ## 退役條件
 * 舊系統 API_Agent 汰換（ACF 寫入全走 APIs Eloquent、Observer 全覆蓋）後可退役或降頻。
 *
 * ## 關聯
 * - Eloquent 層繼承：{@see \App\Observers\AgentCashFlowObserver} creating／updating
 * - 欄位出處：migration `2026_08_03_102237_add_cash_flow_type_to_agent_cash_flows_table`
 *
 * 用法：
 *   php artisan cash-flow:sync-acf-type            # dry-run：只報告差異
 *   php artisan cash-flow:sync-acf-type --execute  # 實際寫入
 */
class SyncAcfType extends Command
{
    protected $signature = 'cash-flow:sync-acf-type
                            {--execute : 實際寫入（預設 dry-run 只報告差異）}';

    protected $description = '收斂 agent_cash_flows.cash_flow_type 便利欄＝cash_flows.type（Observer 以外寫入路徑的補洞）';

    public function handle(): int
    {
        $pending = $this->pendingQuery()
            ->orderBy('agent_cash_flows.id')
            ->get([
                'agent_cash_flows.id',
                'agent_cash_flows.cash_flow_id',
                'agent_cash_flows.cash_flow_type',
                'cash_flows.type as cf_type',
            ]);

        [$dangling, $syncable] = $pending->partition(fn (object $row): bool => $row->cf_type === null);

        $fillCount = $syncable->filter(fn (object $row): bool => $row->cash_flow_type === null)->count();

        $this->info(sprintf(
            '待同步 %d 筆（NULL 補齊 %d、矯正 %d）；懸空 cash_flow_id 跳過 %d 筆',
            $syncable->count(),
            $fillCount,
            $syncable->count() - $fillCount,
            $dangling->count(),
        ));

        if ($dangling->isNotEmpty()) {
            $this->warn(sprintf(
                '懸空清單（無 CF 可繼承，留 NULL）：ACF id %s',
                $dangling->pluck('id')->take(20)->implode(', '),
            ));
        }

        if ($syncable->isEmpty()) {
            return self::SUCCESS;
        }

        if (!$this->option('execute')) {
            $this->table(
                ['ACF id', 'cash_flow_id', '現值', '應為'],
                $syncable->take(20)->map(fn (object $row): array => [
                    $row->id,
                    $row->cash_flow_id,
                    $row->cash_flow_type ?? 'NULL',
                    $row->cf_type,
                ])->all(),
            );
            $this->line('dry-run 未寫入；加 --execute 實際同步。');

            return self::SUCCESS;
        }

        DB::transaction(function (): void {
            // 批次收斂寫入（紅線豁免註記）：經 toBase 繞過 Eloquent 是為了不觸 updated_at
            // （保護以 updated_at 回推的稽核）；DB::raw 內容為識別子常量、非用戶輸入。
            $this->pendingQuery()
                ->whereNotNull('cash_flows.type')
                ->update(['agent_cash_flows.cash_flow_type' => DB::raw('cash_flows.type')]);
        });

        $residual = $this->pendingQuery()->whereNotNull('cash_flows.type')->count();
        $this->info(sprintf('已同步 %d 筆；收斂後複驗殘餘 %d 筆', $syncable->count(), $residual));

        return self::SUCCESS;
    }

    /**
     * 待收斂列：cash_flow_type 為 NULL 或與權威 cash_flows.type 不一致（含軟刪列）
     */
    private function pendingQuery(): Builder
    {
        return AgentCashFlow::withTrashed()
            ->toBase()
            ->leftJoin('cash_flows', 'cash_flows.id', '=', 'agent_cash_flows.cash_flow_id')
            ->where(function (Builder $query): void {
                $query->whereNull('agent_cash_flows.cash_flow_type')
                    ->orWhereColumn('agent_cash_flows.cash_flow_type', '!=', 'cash_flows.type');
            });
    }
}
