<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\CashFlowEnum;
use App\Models\Agent;
use App\Models\BankAccount;
use App\Models\Param;

/**
 * 會員開洗分彈窗的前置資料（target/tickets#4438）：交易維護狀態、可選的銀行帳號。
 *
 * 由 API_Agent 移植，舊接口保留給舊後台 WEB_AgentBackend：
 *   - `system_maintenance/transactions`：params 有一筆啟用中的 transaction_maintains 就算維護中
 *   - `bank/dropdownAccountList`：操作者主帳號支付組別、自助開分金流底下啟用中的銀行帳號
 * 開洗分本身（API_Agent memberTransaction）由 #4333 批次 2 搬，屆時放在同一個 `member/transaction` 前綴下。
 */
class MemberTransactionOptionService
{
    /** params.type：交易維護開關（後台系統維護頁設定） */
    private const string PARAM_TYPE_TRANSACTION_MAINTAINS = 'transaction_maintains';

    /** params.status：啟用中 */
    private const int PARAM_STATUS_ENABLED = 1;

    /** bank_accounts.status：啟用中 */
    private const int BANK_ACCOUNT_STATUS_ENABLED = 1;

    public function __construct(
        private readonly AgentPermissionScopeService $scopeService,
    ) {}

    /** 交易是否維護中：維護中前端不顯示開洗分入口 */
    public function isTransactionMaintaining(): bool
    {
        return Param::query()
            ->where('type', self::PARAM_TYPE_TRANSACTION_MAINTAINS)
            ->where('status', self::PARAM_STATUS_ENABLED)
            ->exists();
    }

    /**
     * 開洗分可選的銀行帳號：操作者主帳號（子帳號取 host）的支付組別、自助開分金流底下啟用中的帳號。
     * 存入、取出各自用 is_deposit／is_withdrawal 篩，前端分兩個下拉。
     *
     * @return list<array{account_id: int, account_nickname: string|null, is_deposit: int, is_withdrawal: int}>
     */
    public function bankAccounts(Agent $operator): array
    {
        $cashFlowGroupId = Agent::query()->whereKey($this->scopeService->hostId($operator))->value('cash_flow_group_id');
        if ($cashFlowGroupId === null) {
            return [];
        }

        return BankAccount::query()
            ->where('status', self::BANK_ACCOUNT_STATUS_ENABLED)
            ->whereHas('orderType.agentCashFlowOrderTypes.agentCashFlow', function ($query) use ($cashFlowGroupId): void {
                $query->where('cash_flow_id', CashFlowEnum::DEPOSIT_AUTO_DEPOSIT)
                    ->where('agent_group_id', $cashFlowGroupId);
            })
            ->orderBy('id')
            ->get(['id', 'account_nickname', 'is_deposit', 'is_withdrawal'])
            ->map(static fn (BankAccount $account): array => [
                'account_id' => (int) $account->id,
                'account_nickname' => $account->account_nickname,
                'is_deposit' => (int) $account->is_deposit,
                'is_withdrawal' => (int) $account->is_withdrawal,
            ])
            ->values()
            ->all();
    }
}
