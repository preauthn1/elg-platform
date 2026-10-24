<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 回填 agent_cash_flow_order_types 與 order_type_names 的 cash_flow_id。
 *
 * 【分類】Maintenance — 可複用維運工具，需要時才跑。
 *
 * ## 發生原因
 * 這兩張表的 `cash_flow_id` 是**刻意的 denormalize 欄位**：原本要取得某筆 ACFOT／
 * order_type_name 屬於哪個金流商，得從 cash_flows → order_types → agent_cash_flows
 * 一路 JOIN 下來（GUI 直接下 SQL 排查時尤其痛），故加欄位讓「以 cash_flow_id 為根」
 * 可一步直查。欄位是後加的，**既有資料當下全為未對齊狀態**，需要一次回填。
 *
 * 另有持續性缺口：`AgentCashFlowOrderTypeObserver` 雖然在 creating／updating 強制
 * `acf.cash_flow_id === ot.cash_flow_id`，但它**只在 APIs 範疇（cf > 62）強制**，
 * legacy（cf ≤ 62）明確放行，因此舊系統流程仍可能寫出不一致的列。
 *
 * ## 修正目的
 * 把下游的 `cash_flow_id` 對齊其上層來源：
 * - `agent_cash_flow_order_types.cash_flow_id` ← 其 `agent_cash_flows.cash_flow_id`
 * - `order_type_names.cash_flow_id`            ← 其 `order_types.cash_flow_id`
 *
 * ## 觸發時機
 * 1. 欄位上線後的初次回填（六站已於 2026-07-24 完成 rollout）
 * 2. legacy 範疇（cf ≤ 62）有異動後——Observer 不擋該範疇
 * 3. 以 cash_flow_id 直查 GUI 時發現筆數對不上，先跑 dry-run 確認是否有 drift
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** dry-run 是預設行為（不加 `--execute` 只出報表）；只更新
 * `cash_flow_id <> 上層值` 的列，已對齊者不碰；全部就緒時直接回「✅ 無需回填」。
 *
 * ⚠️ 注意：本命令是 **sync-safe** 的——`cash-flow:sync` 不會把已對齊的值沖掉
 * （寫入端 `SyncCashFlowsCommand` 建立 OrderTypeName 時即帶正確 `cash_flow_id`）。
 * 2026-07-24 前的 description 誤標「非 sync-safe 需守護」，已於本次更正。
 *
 * ## 退役條件
 * 同時滿足即可刪除本命令：
 * 1. legacy 範疇（cf ≤ 62）全數退場，或 Observer 改為不分範疇一律強制；且
 * 2. 六站複跑 dry-run 連續為零。
 *
 * ## 關聯
 * - 為什麼是實體欄位不是 view：維運者要在 DB GUI 直接以 cash_flow_id 為條件 SELECT／UPDATE，
 *   跨三表 JOIN 的 view 就算可更新也受 MySQL 條件限制（只能動一張底層表、不得含聚合），不想依賴它；
 *   實體欄位也與 agent_cash_flows、cash_flow_banks 既有的 cash_flow_id 是同一種 denormalize 模式。
 * - invariant 守門員：`app/Observers/AgentCashFlowOrderTypeObserver.php`
 * - denormalize 原則：`docs/未完成計劃/金流商架構重構/denormalization-設計原則.md`
 *
 * 用法：
 *   php artisan cash-flow:backfill-cash-flow-id             # dry-run（預設）
 *   php artisan cash-flow:backfill-cash-flow-id --execute   # 實際回填（需確認）
 *   php artisan cash-flow:backfill-cash-flow-id --execute --force
 */
class BackfillCashFlowId extends Command
{
    protected $signature = 'cash-flow:backfill-cash-flow-id
                            {--execute : 實際執行回填（預設僅 dry-run 報表）}
                            {--force : 跳過執行前確認}';

    protected $description = '回填 ACFOT 與 order_type_names 的 cash_flow_id（來源：ACF／order_types 上層；冪等、sync-safe）';

    public function handle(): int
    {
        $acfotPending = $this->countAcfotPending();
        $otnPending = $this->countOrderTypeNamePending();

        $this->table(
            ['目標表', '待回填筆數'],
            [
                ['agent_cash_flow_order_types', (string) $acfotPending],
                ['order_type_names', (string) $otnPending],
            ],
        );

        if (!$this->option('execute')) {
            $this->info(sprintf('🔍 Dry-run 模式，未寫入。ACFOT %d 筆、order_type_names %d 筆待回填。加 --execute 以實際執行。', $acfotPending, $otnPending));

            return self::SUCCESS;
        }

        if ($acfotPending === 0 && $otnPending === 0) {
            $this->info('✅ 無需回填（全部已就緒，冪等）');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm(sprintf('確認回填 ACFOT %d 筆、order_type_names %d 筆？', $acfotPending, $otnPending), false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $this->backfillAcfot();
        $this->backfillOrderTypeName();

        $this->info('✅ 回填完成');

        return self::SUCCESS;
    }

    private function countAcfotPending(): int
    {
        return (int) AgentCashFlow::query()
            ->join('agent_cash_flow_order_types as acfot', 'acfot.agent_cash_flow_id', '=', 'agent_cash_flows.id')
            ->whereColumn('acfot.cash_flow_id', '<>', 'agent_cash_flows.cash_flow_id')
            ->count('acfot.id');
    }

    private function countOrderTypeNamePending(): int
    {
        return (int) OrderType::query()
            ->join('order_type_names as otn', 'otn.type_id', '=', 'order_types.id')
            ->whereColumn('otn.cash_flow_id', '<>', 'order_types.cash_flow_id')
            ->count('otn.id');
    }

    private function backfillAcfot(): void
    {
        AgentCashFlow::query()->select('id', 'cash_flow_id')->chunkById(500, function ($agentCashFlows): void {
            foreach ($agentCashFlows as $agentCashFlow) {
                DB::transaction(function () use ($agentCashFlow): void {
                    AgentCashFlowOrderType::query()
                        ->where('agent_cash_flow_id', $agentCashFlow->id)
                        ->where('cash_flow_id', '<>', $agentCashFlow->cash_flow_id)
                        ->update(['cash_flow_id' => $agentCashFlow->cash_flow_id]);
                });
            }
        });
    }

    private function backfillOrderTypeName(): void
    {
        OrderType::query()->select('id', 'cash_flow_id')->chunkById(500, function ($orderTypes): void {
            foreach ($orderTypes as $orderType) {
                DB::transaction(function () use ($orderType): void {
                    OrderTypeName::query()
                        ->where('type_id', $orderType->id)
                        ->where('cash_flow_id', '<>', $orderType->cash_flow_id)
                        ->update(['cash_flow_id' => $orderType->cash_flow_id]);
                });
            }
        });
    }
}
