<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 根據 agent_cash_flows 的 info 重置銀行的 min_amount 和 max_amount
 * 同個銀行有多個 agent_cash_flows 的 info 時，取最小的 min_amount 和最大的 max_amount
 *
 * 【分類】Bank — 可複用維運工具，🔴 **不退役**；但缺守衛、用法有硬限制（見下方）。
 *
 * ## 退役條件
 *
 * 🔴 **不退役（`docs/未完成計劃/RB/TODO.md` §2.4-3，2026-09-09 登記）。**
 *
 * 原判「已被 `bank:set-amount-limits` 取代」**不成立**——兩者是不同場景
 * （`SetBankAmountLimitsCommand.php:17-22` 明寫）：本命令「整群組＋幣別依所有代付 ACF 重算」，
 * **新開通群組整批設限額只有它能做**；那支是「以 `banks.id` 點名、選取即範圍」，
 * 適合收一兩個敞口。兩支並存，依場景選。
 *
 * ⚠️ **但它確實缺守衛，動手前要知道**：
 *
 * 1. **無 `--execute` 旗標＝預設就寫入**，與 bank 家族「預設只預演」的慣例相反。
 * 2. 只濾 `cash_flows.status`、不濾 ACF 自身的 status ⇒ 已停用通道的限額也會被算進來。
 * 3. 選取範圍是整個代理群組＋幣別，收單一敞口請改用 `bank:set-amount-limits`。
 *
 * ⇒ 待裁決的是「**要不要補 `--execute` 與 ACF status 濾條件**」，不是要不要退役。
 */
class ResetBanksMinMaxAmount extends Command
{
    // php artisan cash-flow:reset-banks-min-max-amount --agent-id=71561 --currency-code=SGD
    protected $signature = 'cash-flow:reset-banks-min-max-amount
                            {--agent-id= : 代理 ID}
                            {--currency-code= : 幣別代碼}';

    protected $description = '重置銀行的 min_amount 和 max_amount';

    public function handle(): int
    {
        $this->info('開始重置銀行的 min_amount 和 max_amount...');

        $context = $this->resolveContext();
        if ($context === null) {
            return self::FAILURE;
        }

        ['agentGroupId' => $agentGroupId, 'currencyId' => $currencyId] = $context;

        $agentCashFlows = $this->fetchAgentCashFlows($agentGroupId, $currencyId);
        if ($agentCashFlows === null) {
            return self::FAILURE;
        }

        $updates = $this->buildUpdates($agentCashFlows);
        $changes = $this->detectChanges($updates, $agentGroupId, $currencyId);

        if ($changes->isEmpty()) {
            $this->info('沒有銀行需要更新');

            return self::SUCCESS;
        }

        $this->displayChanges($changes);
        $this->newLine();

        if (!$this->confirm('確定要更新這些銀行的 min_amount 和 max_amount 嗎？', true)) {
            $this->warn('❌ 已取消操作');

            return self::FAILURE;
        }

        $this->newLine();

        return $this->executeUpdates($changes);
    }

    /**
     * 解析並驗證所有命令參數
     *
     * @return array{agentGroupId: int, currencyCode: string, currencyId: int}|null
     */
    private function resolveContext(): ?array
    {
        $agentId = $this->option('agent-id');
        $currencyCode = $this->option('currency-code');

        if (!$agentId) {
            $this->error('❌ 請指定 --agent-id');

            return null;
        }

        if (!$currencyCode) {
            $this->error('❌ 請指定 --currency-code');

            return null;
        }

        /** @var Agent|null $agent */
        $agent = Agent::find($agentId);
        if (!$agent) {
            $this->error(sprintf('❌ 代理不存在: %s', $agentId));

            return null;
        }

        $currencyCode = strtoupper($currencyCode);
        $currency = Currency::where('code', $currencyCode)->first();
        if (!$currency) {
            $this->error(sprintf('❌ 無效的幣別: %s', $currencyCode));

            return null;
        }

        $this->info(sprintf('代理群組 ID: %d', $agent->cash_flow_group_id));
        $this->info(sprintf('幣別: %s', $currencyCode));

        return [
            'agentGroupId' => $agent->cash_flow_group_id,
            'currencyCode' => $currencyCode,
            'currencyId' => $currency->id,
        ];
    }

    /**
     * 查詢符合條件的 AgentCashFlows（代付、啟用、指定幣別）
     *
     * 幣別判準走 currency_id 欄位；舊版以 name LIKE '%(CUR)%' 判斷，
     * 名稱無幣別後綴的 ACF 會整批漏抓，導致限額計算來源不完整。
     */
    private function fetchAgentCashFlows(int $agentGroupId, int $currencyId): ?Collection
    {
        $this->warnAboutNullCurrencyAcfs($agentGroupId);

        $agentCashFlows = AgentCashFlow::query()
            ->withWhereHas('cashFlow', function ($query) {
                $query->where('type', 3);
                $query->where('status', 1);
            })
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $currencyId)
            ->orderBy('id', 'asc')
            ->get();

        if ($agentCashFlows->isEmpty()) {
            $this->warn(sprintf(
                '⚠️  找不到符合條件的 agent_cash_flows (agent_group_id=%d, currency_id=%d)',
                $agentGroupId,
                $currencyId,
            ));

            return null;
        }

        return $agentCashFlows;
    }

    /**
     * 提醒同群組內 currency_id 為空而被排除的代付 ACF（需先回填才會納入計算）
     */
    private function warnAboutNullCurrencyAcfs(int $agentGroupId): void
    {
        $excluded = AgentCashFlow::query()
            ->whereHas('cashFlow', function ($query) {
                $query->where('type', 3);
                $query->where('status', 1);
            })
            ->where('agent_group_id', $agentGroupId)
            ->whereNull('currency_id')
            ->get(['id', 'name']);

        if ($excluded->isEmpty()) {
            return;
        }

        $this->warn(sprintf(
            '⚠️  %d 個 agent_cash_flows 因 currency_id 為空被排除，回填後才會納入計算: %s',
            $excluded->count(),
            $excluded->map(fn ($acf) => sprintf('#%d %s', $acf->id, $acf->name))->implode(', '),
        ));
    }

    /**
     * 從 AgentCashFlows 收集銀行金額清單，並計算每個銀行的最終限額
     *
     * 完全沒有限額資料的銀行直接略過，避免把 0.00/0.00 寫進 banks 蓋掉既有限額
     *
     * @return array<string, array{min_amount: string, max_amount: string}>
     */
    private function buildUpdates(Collection $agentCashFlows): array
    {
        $bankAmounts = $this->collectBankAmounts($agentCashFlows);

        $updates = [];
        foreach ($bankAmounts as $bankCode => $amounts) {
            if (empty($amounts['min_amounts']) && empty($amounts['max_amounts'])) {
                $this->warn(sprintf('⚠️  %s 無任何限額資料，略過不更新', $bankCode));

                continue;
            }

            $updates[$bankCode] = [
                'min_amount' => !empty($amounts['min_amounts']) ? min($amounts['min_amounts']) : '0.00',
                'max_amount' => !empty($amounts['max_amounts']) ? max($amounts['max_amounts']) : '0.00',
            ];
        }

        return $updates;
    }

    /**
     * 遍歷 AgentCashFlows info，收集每個銀行代碼的所有金額限制
     *
     * @return array<string, array{min_amounts: string[], max_amounts: string[]}>
     */
    private function collectBankAmounts(Collection $agentCashFlows): array
    {
        $bankAmounts = [];
        $this->info(sprintf('共有 %d 個 agent_cash_flows', $agentCashFlows->count()));

        foreach ($agentCashFlows as $agentCashFlow) {
            $this->line(sprintf('AgentCashFlow ID: %d %s', $agentCashFlow->id, $agentCashFlow->name));

            $info = $agentCashFlow->info ?? [];
            $availableBanks = $info['available_banks'] ?? [];

            if (empty($availableBanks)) {
                $this->warn(sprintf('  ⚠️  AgentCashFlow ID %d 沒有 available_banks', $agentCashFlow->id));

                continue;
            }

            // 外部資料進入時正規化為固定精度字串
            $minAmount = $info['MinAmount'] ?? $info['min_amount'] ?? null;
            $maxAmount = $info['MaxAmount'] ?? $info['max_amount'] ?? null;

            foreach ($availableBanks as $bank) {
                $bankCode = $bank['BankCode_ELG'] ?? null;

                if (empty($bankCode)) {
                    continue;
                }

                $bankAmounts[$bankCode] ??= ['min_amounts' => [], 'max_amounts' => []];

                if ($minAmount !== null && $minAmount !== '') {
                    $bankAmounts[$bankCode]['min_amounts'][] = Common::numAdd('0', (string) $minAmount, 2);
                }

                if ($maxAmount !== null && $maxAmount !== '') {
                    $bankAmounts[$bankCode]['max_amounts'][] = Common::numAdd('0', (string) $maxAmount, 2);
                }
            }
        }

        return $bankAmounts;
    }

    /**
     * 一次性查詢所有銀行，比對差異，回傳需要更新的項目（避免 N+1）
     */
    private function detectChanges(array $updates, int $agentGroupId, int $currencyId): Collection
    {
        $banks = Bank::query()
            ->where('agent_group_id', $agentGroupId)
            ->where('currency_id', $currencyId)
            ->whereIn('code', array_keys($updates))
            ->get();

        // 警告找不到的銀行代碼
        $notFound = array_diff(array_keys($updates), $banks->pluck('code')->toArray());
        if (!empty($notFound)) {
            $this->warn(sprintf('⚠️  有 %d 個銀行找不到: %s', count($notFound), implode(', ', $notFound)));
        }

        $changes = collect();

        foreach ($banks as $bank) {
            if (!isset($updates[$bank->code])) {
                continue;
            }

            $amounts = $updates[$bank->code];

            $updateData = [];

            if (Common::numCompare($bank->min_amount, $amounts['min_amount'], 2) !== 0) {
                $updateData['min_amount'] = $amounts['min_amount'];
            }

            if (Common::numCompare($bank->max_amount, $amounts['max_amount'], 2) !== 0) {
                $updateData['max_amount'] = $amounts['max_amount'];
            }

            if (!empty($updateData)) {
                $changes->push([
                    'bank' => $bank,
                    'updateData' => $updateData,
                    'amounts' => $amounts,
                ]);
            }
        }

        return $changes->sortBy('bank.id');
    }

    /**
     * 顯示待更新銀行的異動摘要
     */
    private function displayChanges(Collection $changes): void
    {
        $this->info('變動的銀行：');

        foreach ($changes as $change) {
            $bank = $change['bank'];
            $updateData = $change['updateData'];
            $amounts = $change['amounts'];

            $this->info(sprintf('銀行 ID: %d %s', $bank->id, $bank->name));

            if (isset($updateData['min_amount'])) {
                $this->line(sprintf('  min_amount: %s -> %s', $bank->min_amount, $amounts['min_amount']));
            }

            if (isset($updateData['max_amount'])) {
                $this->line(sprintf('  max_amount: %s -> %s', $bank->max_amount, $amounts['max_amount']));
            }
        }
    }

    /**
     * 在交易中批次更新銀行限額
     */
    private function executeUpdates(Collection $changes): int
    {
        $updatedCount = 0;

        DB::beginTransaction();

        try {
            foreach ($changes as $change) {
                $bank = $change['bank'];
                $updateData = $change['updateData'];

                $this->info(sprintf(
                    '✅ 已更新銀行: id %d %s min_amount: %s -> %s max_amount: %s -> %s',
                    $bank->id,
                    $bank->name,
                    $bank->min_amount,
                    $updateData['min_amount'] ?? '未變動',
                    $bank->max_amount,
                    $updateData['max_amount'] ?? '未變動',
                ));

                $bank->update($updateData);
                $updatedCount++;
            }

            DB::commit();
            $this->info(sprintf('✅ 完成！已更新 %d 個銀行', $updatedCount));

            return self::SUCCESS;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error(sprintf('❌ 更新失敗: %s', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
