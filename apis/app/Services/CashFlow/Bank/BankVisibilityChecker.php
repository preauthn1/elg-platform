<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use App\Enums\CashFlowTypeEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlowBank;
use App\Models\Currency;

/**
 * 銀行可見性檢查 Service
 *
 * 8 step checklist 對應 frontend/api/withdrawal/banks 路由的銀行能見鏈。
 *
 * 三模式：
 * - checkBankForAgent          : 模式 A，單一銀行對 agent 是否可見
 * - listInvisibleBanksForAgent : 模式 B，agent + 幣別下所有不可見銀行
 * - listAllBanksForCashflow    : 模式 C，agent + cf 下所有 feed bank 可見性
 *
 * 參考：app/Http/Controllers/Frontend/WithdrawalController.php 的 getBanks()
 *
 * 🔴 **前台銀行清單路由每加一道過濾條件，必須同步加進本類的 checklist**，
 * 否則診斷會給出「應該看得到」的錯誤結論——比沒有診斷更糟。
 *
 * 🔴 **本類純唯讀，禁止在 checker 內修資料。** 真的要修（補別名、改名、去重、
 * 對齊 available_banks）走各自的命令；本類只負責定位是哪一段鏈斷掉。
 *
 * 注意：AgentCashFlow 與 Agent 的關聯是透過 Agent->cash_flow_group_id ↔
 * AgentCashFlow->agent_group_id（非 agent_id）。
 */
class BankVisibilityChecker
{
    /**
     * 模式 A：對 agent 下每個 type=3 ACF 各跑一次檢查鏈，
     * 找到任一條鏈全過即報告 visible=true，全失敗則列出每條鏈的失敗 step。
     *
     * @return array<string, mixed>
     */
    public function checkBankForAgent(int $bankId, int $agentId): array
    {
        $bank = Bank::query()->findOrFail($bankId);
        $agent = Agent::query()->findOrFail($agentId);

        $candidateCashFlowIds = CashFlowBank::query()
            ->where('bank_code', $bank->code)
            ->where('currency_id', $bank->currency_id)
            ->pluck('cash_flow_id')
            ->unique()
            ->all();

        $chains = [];
        $visibleChain = null;

        foreach ($candidateCashFlowIds as $cfId) {
            $chain = $this->runChecklistForCf($bank, $agent, (int) $cfId);
            $chains[] = $chain;

            if ($chain['failed_step'] === null) {
                $visibleChain = $chain;
                break;
            }
        }

        // 如果連 cash_flow_banks 都找不到 candidate，給一個合成的 failed_step=1 chain
        if (empty($chains)) {
            $chains[] = [
                'cash_flow_id' => 0,
                'checks' => [
                    ['step' => 1, 'name' => 'cash_flow_banks_exists', 'passed' => false, 'detail' => 'no candidate cash_flow_id'],
                ],
                'failed_step' => 1,
            ];
        }

        return [
            'bank_id' => $bankId,
            'bank_name' => $bank->name,
            'agent_id' => $agentId,
            'visible' => $visibleChain !== null,
            'chains' => $chains,
            'suggestion' => $visibleChain ? null : $this->suggestForFailure($chains[0]),
        ];
    }

    /**
     * 模式 B：列出 agent + 幣別下所有不可見銀行
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInvisibleBanksForAgent(int $agentId, string $currency): array
    {
        $agent = Agent::query()->findOrFail($agentId);
        $currencyId = Currency::query()->where('code', $currency)->value('id');

        if ($currencyId === null) {
            return [];
        }

        $banks = Bank::query()
            ->where('currency_id', $currencyId)
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->get();

        $results = [];
        foreach ($banks as $bank) {
            $check = $this->checkBankForAgent($bank->id, $agentId);
            if (!$check['visible']) {
                $results[] = $check;
            }
        }

        return $results;
    }

    /**
     * 模式 C：掃 agent 在指定 cf 下所有 feed banks 的可見性
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAllBanksForCashflow(int $agentId, int $cashflowId): array
    {
        $agent = Agent::query()->findOrFail($agentId);

        $feedBanks = CashFlowBank::query()
            ->where('cash_flow_id', $cashflowId)
            ->get();

        $results = [];
        foreach ($feedBanks as $cfBank) {
            $bank = Bank::query()
                ->where('agent_group_id', $agent->cash_flow_group_id)
                ->where('currency_id', $cfBank->currency_id)
                ->where('code', $cfBank->bank_code)
                ->first();

            if ($bank === null) {
                $results[] = [
                    'bank_code' => $cfBank->bank_code,
                    'currency_id' => $cfBank->currency_id,
                    'visible' => false,
                    'chains' => [['cash_flow_id' => $cashflowId, 'checks' => [], 'failed_step' => 6]],
                    'suggestion' => $this->suggestionForStep(6),
                ];

                continue;
            }

            $results[] = $this->checkBankForAgent($bank->id, $agentId);
        }

        return $results;
    }

    /**
     * 跑 8-step checklist。
     *
     * @return array<string, mixed>
     */
    protected function runChecklistForCf(Bank $bank, Agent $agent, int $cashflowId): array
    {
        $checks = [];

        // Step 1: cash_flow_banks
        $cfBank = CashFlowBank::query()
            ->where('cash_flow_id', $cashflowId)
            ->where('currency_id', $bank->currency_id)
            ->where('bank_code', $bank->code)
            ->where('status', 1)
            ->first();
        $checks[] = ['step' => 1, 'name' => 'cash_flow_banks_exists', 'passed' => $cfBank !== null];
        if (!$cfBank) {
            return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => 1];
        }

        // Step 2: AgentCashFlow type=3（透過 cash_flow_group_id 關聯）
        $acf = AgentCashFlow::query()
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('cash_flow_id', $cashflowId)
            ->whereHas('cashFlow', fn ($q) => $q->where('type', CashFlowTypeEnum::WITHDRAW->value))
            ->first();
        $checks[] = ['step' => 2, 'name' => 'agent_cash_flow_exists', 'passed' => $acf !== null];
        if (!$acf) {
            return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => 2];
        }

        // Step 3: status = 1
        $checks[] = ['step' => 3, 'name' => 'agent_cash_flow_active', 'passed' => $acf->status == 1];
        if ($acf->status != 1) {
            return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => 3];
        }

        // Step 4: available_banks contains bank.code
        $availableBanks = $acf->info['available_banks'] ?? [];
        $entry = collect($availableBanks)->firstWhere('BankCode_ELG', $bank->code);
        $checks[] = ['step' => 4, 'name' => 'available_banks_includes', 'passed' => $entry !== null];
        if (!$entry) {
            return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => 4];
        }

        // Step 5: entry status ≠ 0
        $entryStatus = $entry['status'] ?? 1;
        $checks[] = ['step' => 5, 'name' => 'available_bank_entry_active', 'passed' => $entryStatus != 0];
        if ($entryStatus == 0) {
            return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => 5];
        }

        // Step 6: banks 表 row（agent_group_id 對應）
        $platformBank = Bank::query()
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('currency_id', $bank->currency_id)
            ->where('code', $bank->code)
            ->first();

        $checks[] = ['step' => 6, 'name' => 'banks_row_for_group', 'passed' => $platformBank !== null];
        if (!$platformBank) {
            return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => 6];
        }

        // Step 7: banks.status = 1
        $checks[] = ['step' => 7, 'name' => 'banks_active', 'passed' => $platformBank->status == 1];
        if ($platformBank->status != 1) {
            return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => 7];
        }

        // Step 8: OrderType 啟用（簡化：只看 type > 0）
        $orderTypeOk = $platformBank->type > 0;
        $checks[] = ['step' => 8, 'name' => 'order_type_active', 'passed' => $orderTypeOk];
        if (!$orderTypeOk) {
            return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => 8];
        }

        return ['cash_flow_id' => $cashflowId, 'checks' => $checks, 'failed_step' => null];
    }

    /**
     * 為某條失敗 chain 產生修復建議。
     *
     * @param  array<string, mixed>|null  $chain
     * @return array<string, mixed>
     */
    protected function suggestForFailure(?array $chain): array
    {
        if ($chain === null) {
            return [
                'type' => 'no_candidate_cashflow',
                'message' => '沒有任何 cash_flow_banks 提供此 bank',
                'command' => null,
            ];
        }

        return $this->suggestionForStep($chain['failed_step']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function suggestionForStep(?int $step): array
    {
        return match ($step) {
            1 => [
                'type' => 'feed_missing',
                'message' => 'cash_flow_banks 缺此 bank，可能 sync 跳過或 provider 沒回傳',
                'command' => 'php artisan bank:audit-feed --currency=X',
            ],
            2 => [
                'type' => 'agent_cash_flow_missing',
                'message' => 'Agent 沒有對應的 type=3 AgentCashFlow',
                'command' => 'php artisan agent:add-agent-cash-flow --agent=N --cashflow=M --currency=X',
            ],
            3 => [
                'type' => 'agent_cash_flow_disabled',
                'message' => 'AgentCashFlow status=0，需啟用',
                'command' => null,
            ],
            4 => [
                'type' => 'available_banks_missing',
                'message' => 'available_banks 漏 bank_code',
                'command' => 'php artisan agent:add-agent-cash-flow --agent=N --cashflow=M --currency=X',
            ],
            5 => [
                'type' => 'available_bank_entry_disabled',
                'message' => 'available_banks entry status=0',
                'command' => null,
            ],
            6 => [
                'type' => 'banks_row_missing_for_group',
                'message' => 'banks 表沒有對應 agent_group_id 的 row',
                'command' => 'php artisan agent:add-agent-cash-flow --agent=N --cashflow=M --currency=X',
            ],
            7 => [
                'type' => 'banks_disabled',
                'message' => 'banks.status=0，需啟用',
                'command' => null,
            ],
            8 => [
                'type' => 'order_type_disabled',
                'message' => 'OrderType 停用，需後台啟用',
                'command' => null,
            ],
            default => ['type' => 'unknown', 'message' => '未知失敗', 'command' => null],
        };
    }
}
