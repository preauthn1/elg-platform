<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Models\AgentCashFlowOrderType;
use App\Models\OrderType;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 批次更新指定 AgentCashFlow 底下「父層 OrderType ＋ 其所有子層」的代收限額。
 *
 * 【分類】Maintenance — 可複用維運工具，營運調限額時跑。
 *
 * ## 發生原因
 * 代收限額（`min_amount`／`max_amount`）存在 `agent_cash_flow_order_types` 這一層，
 * 而 OrderType 是**父子結構**（一個父層底下掛多條子通道）。調整某個支付方式的限額時，
 * 父層與每一條子層都要改到，逐筆處理費時且**容易漏掉子層**——漏改的子層會用舊限額，
 * 造成同一支付方式不同通道限額不一致。
 *
 * ## 修正目的
 * 給定 `agent_cash_flow_id` 與**父層** `order_type_id`，自動展開該父層底下所有子層
 * OrderType，把整組 ACFOT 的 `min_amount`／`max_amount` 一次更新為指定值。
 *
 * `max_amount` 傳 `0` 代表**不限制上限**（非「上限為 0」）。
 *
 * ## 觸發時機
 * 1. 營運調整某支付方式的單筆限額
 * 2. 新渠道上線後補設限額
 * 3. 發現父子層限額不一致時做齊頭對齊
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全**（設定為絕對值而非增減量）。保護層完整：
 * - `--dry-run` 預覽將變更的每一筆
 * - `confirm()` 二次確認（`--force` 可略過，供 CI／批次使用）
 * - 寫入包在 `DB::transaction` 內並以 `lockForUpdate()` 鎖列，防併發覆寫
 * - 金額參數先過 `validateAmounts()` 檢核
 * - 找不到符合條件的 ACFOT 時回 FAILURE，不會靜默成功
 *
 * ## 退役條件
 * 當後台提供「父層批次調限額」功能（一次寫入父層與子層）後，本命令即可刪除。
 * 在那之前它是避免漏改子層的必要工具。
 *
 * ## 關聯
 * - 限額另一個來源：`agent_cash_flows.info` 的 `min_amount`／`max_amount`
 *   （鍵名正規化見 `fix:agent-cash-flow-info-keys`）
 * - 費率／限額收斂到 ACFOT 的長期方向：`docs/未完成計劃/金流商架構重構/費率與手續費雙層收斂計畫.md`
 *
 * 用法：
 *   php artisan fix:agent-cash-flow-order-type-limits {acf_id} {parent_ot_id} {min} {max} [--dry-run|--force]
 *   php artisan fix:agent-cash-flow-order-type-limits 63 209 10000 20000000 --dry-run
 *   php artisan fix:agent-cash-flow-order-type-limits 63 209 10000 20000000 --force
 */
class UpdateAgentCashFlowOrderTypeLimits extends Command
{
    protected $signature = 'fix:agent-cash-flow-order-type-limits
                            {agent_cash_flow_id : AgentCashFlow ID}
                            {parent_order_type_id : 父層 OrderType ID}
                            {min_amount : 最小金額}
                            {max_amount : 最大金額，0 為不限制}
                            {--dry-run : 只預覽變更，不寫入資料庫}
                            {--force : 跳過執行確認}';

    protected $description = '批次更新指定 AgentCashFlow 底下父層與子層 OrderType 的代收渠道限額';

    public function handle(): int
    {
        $agentCashFlowId = (int) $this->argument('agent_cash_flow_id');
        $parentOrderTypeId = (int) $this->argument('parent_order_type_id');
        $minAmount = (int) $this->argument('min_amount');
        $maxAmount = (int) $this->argument('max_amount');
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');

        if (!$this->validateAmounts($minAmount, $maxAmount)) {
            return self::FAILURE;
        }

        $orderTypeIds = $this->resolveOrderTypeIds($parentOrderTypeId);
        if (empty($orderTypeIds)) {
            return self::FAILURE;
        }

        $targets = $this->getTargetRows($agentCashFlowId, $orderTypeIds);
        if ($targets->isEmpty()) {
            $this->error('找不到符合條件的 agent_cash_flow_order_types 記錄');

            return self::FAILURE;
        }

        $this->displayPreview($targets, $minAmount, $maxAmount);

        if ($isDryRun) {
            $this->warn('乾跑模式：未寫入資料庫');

            return self::SUCCESS;
        }

        if (!$isForce && $this->input->isInteractive() && !$this->confirm('確定要更新上述限額？', false)) {
            $this->info('已取消');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($agentCashFlowId, $orderTypeIds, $minAmount, $maxAmount): void {
                $targets = AgentCashFlowOrderType::query()
                    ->where('agent_cash_flow_id', $agentCashFlowId)
                    ->whereIn('order_type_id', $orderTypeIds)
                    ->lockForUpdate()
                    ->get();

                foreach ($targets as $target) {
                    $target->min_amount = $minAmount;
                    $target->max_amount = $maxAmount;
                    $target->save();
                }
            });
        } catch (Throwable $e) {
            $this->error(sprintf('更新失敗：%s', $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf('已更新 %d 筆 agent_cash_flow_order_types 記錄', $targets->count()));

        return self::SUCCESS;
    }

    protected function validateAmounts(int $minAmount, int $maxAmount): bool
    {
        if ($minAmount < 0 || $maxAmount < 0) {
            $this->error('min_amount / max_amount 不可為負數');

            return false;
        }

        if ($maxAmount > 0 && $minAmount > $maxAmount) {
            $this->error('min_amount 不可大於 max_amount');

            return false;
        }

        return true;
    }

    /**
     * @return list<int>
     */
    protected function resolveOrderTypeIds(int $parentOrderTypeId): array
    {
        $parentExists = OrderType::query()
            ->whereKey($parentOrderTypeId)
            ->exists();

        if (!$parentExists) {
            $this->error(sprintf('找不到父層 OrderType：%d', $parentOrderTypeId));

            return [];
        }

        return OrderType::query()
            ->where('parent', $parentOrderTypeId)
            ->pluck('id')
            ->push($parentOrderTypeId)
            ->unique()
            ->values()
            ->all();
    }

    protected function getTargetRows(int $agentCashFlowId, array $orderTypeIds): Collection
    {
        return AgentCashFlowOrderType::query()
            ->where('agent_cash_flow_id', $agentCashFlowId)
            ->whereIn('order_type_id', $orderTypeIds)
            ->orderBy('order_type_id')
            ->get(['id', 'agent_cash_flow_id', 'order_type_id', 'min_amount', 'max_amount']);
    }

    protected function displayPreview(Collection $targets, int $minAmount, int $maxAmount): void
    {
        $this->table(
            ['ID', 'AgentCashFlow ID', 'OrderType ID', 'Current Min', 'Current Max', 'New Min', 'New Max'],
            $targets->map(fn (AgentCashFlowOrderType $target): array => [
                $target->id,
                $target->agent_cash_flow_id,
                $target->order_type_id,
                $target->min_amount,
                $target->max_amount,
                $minAmount,
                $maxAmount,
            ])->all()
        );
    }
}
