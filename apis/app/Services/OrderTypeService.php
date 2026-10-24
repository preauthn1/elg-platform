<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\CashFlowEnum;
use App\Enums\General\CacheTtl;
use App\Enums\LanguageEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * @knowledge rules/payment-channel-management
 */
class OrderTypeService
{
    public function __construct(
        private AgentService $agentService,
    ) {}

    public function getAutoDeposit(Agent $agent, int $languageId): ?object
    {
        $agentCashFlow = $this->getAgentCashFlow(
            agent: $agent,
            cashFlowId: CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
            settingKey: AgentCustomizedSettingKeyEnum::AUTODESPOSITE_BUTTON_VISIBLE
        );

        if (!$agentCashFlow) {
            // Log::error('AutoDeposit agentCashFlow not found', [
            //     'agent_id' => $agent->id,
            //     'agent_group_id' => $agent->cash_flow_group_id,
            //     'cash_flow_id' => CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
            // ]);

            return null;
        }

        $name = $this->agentService->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::LABEL_TEXT_AUTO_DEPOSIT
        );

        // TODO 這是暫時寫法，需要優化
        // 圖片先做寫死,有需要更改再放進DB
        if (in_array($agent->cash_flow_group_id, [70, 93])) {
            $image = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/wintoto/bank.png';
        } elseif (in_array($agent->cash_flow_group_id, [95, 116, 674])) {
            $image = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/cj7/v2_bank.png';
        } else {
            $image = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/topup.png';
        }

        $info = $agentCashFlow->info;
        $minAmount = $info['min_amount'] ?? $info['MinAmount'] ?? 0;
        $maxAmount = $info['max_amount'] ?? $info['MaxAmount'] ?? 0;

        return (object) [
            'order_type_id' => 0,
            'currency_id' => (int) config('custom.currency_id'),
            'name' => $name,
            'type' => 2,
            'cf_id' => CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
            'cf_name' => $agentCashFlow->name, // AutoDeposit
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'rate' => $agentCashFlow->rate,
            'image_path' => $image,
            'order_type_info' => '',
            'settings' => $this->getCustomizeSettings(CashFlowEnum::DEPOSIT_AUTO_DEPOSIT, $agent->cash_flow_group_id),
        ];
    }

    public function getAutoWithdrawal(Agent $agent, int $languageId): ?object
    {
        $agentCashFlow = $this->getAgentCashFlow(
            agent: $agent,
            cashFlowId: CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL,
            settingKey: AgentCustomizedSettingKeyEnum::WITHDRAWAL_BUTTON_VISIBLE
        );

        if (!$agentCashFlow) {
            // Log::error('AutoWithdrawal agentCashFlow not found', [
            //     'agent_id' => $agent->id,
            //     'agent_group_id' => $agent->cash_flow_group_id,
            //     'cash_flow_id' => CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL,
            // ]);

            return null;
        }

        $name = $this->agentService->getSpecificAgentSetting(
            $agent,
            AgentCustomizedSettingKeyEnum::LABEL_TEXT_WITHDRAWAL
        );

        // TODO 這是暫時寫法，需要優化
        // 圖片先做寫死,有需要更改再放進DB
        if (in_array($agent->cash_flow_group_id, [70, 93])) {
            $image = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/wintoto/withdrawal.png';
        } elseif (in_array($agent->cash_flow_group_id, [95, 116, 674])) {
            $image = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/cj7/Withdrawal.png';
        } else {
            $image = 'https://example.invalid/cdn-betmonsters-com-cdn-bucket-online/withdrawal.png';
        }

        $info = $agentCashFlow->info;
        $minAmount = $info['min_amount'] ?? $info['MinAmount'] ?? 0;
        $maxAmount = $info['max_amount'] ?? $info['MaxAmount'] ?? 0;

        return (object) [
            'order_type_id' => 0,
            'currency_id' => (int) config('custom.currency_id'),
            'name' => $name,
            'type' => 2,
            'cf_id' => CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL,
            'cf_name' => $agentCashFlow->name, // Auto Withdrawal
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'rate' => $agentCashFlow->rate,
            'image_path' => $image,
            'order_type_info' => '',
            'settings' => $this->getCustomizeSettings(CashFlowEnum::WITHDRAW_AUTO_WITHDRAWAL, $agent->cash_flow_group_id),
        ];
    }

    /**
     * 取可用支付渠道（以 agent_cash_flow_order_types 為主表）
     *
     * 全鏈為 index-backed semi-join（2026-08-07 TPower/VWorld EXPLAIN：
     * cf(idx_cash_flows_type) → acf(idx_agent_group_cash_flow) → acfot(idx_cashflow_status_type)
     * → ot(PK)，無 duplicate weedout 臨時表）；orderType / cashFlow 的 status 為
     * 即時檢查（各層 status 都是獨立開關，不依賴任何 status 同步機制）。
     *
     * @return Collection<int, AgentCashFlowOrderType>
     */
    public function getOrderTypes(Agent $agent, int $languageId): Collection
    {
        $cacheKey = CacheKeys::orderTypes($agent->cash_flow_group_id, $languageId);

        return Cache::safeRemember($cacheKey, CacheTtl::ONE_MINUTE->value, function () use ($agent, $languageId) {
            return AgentCashFlowOrderType::query()
                ->where('status', 1)
                ->withWhereHas('agentCashFlow', function ($q) use ($agent) {
                    $q->where('agent_group_id', $agent->cash_flow_group_id)
                        ->where('status', 1)
                        ->select(['id', 'cash_flow_id', 'rate']);
                    // type 以權威欄 cash_flows.type 過濾（騎驗 status 的同一次 PK 點查，零額外成本；
                    // 不用 acf.cash_flow_type——該 denorm 欄可為 NULL 且靠每日排程補償，不支撐即時查詢）
                    $q->withWhereHas('cashFlow', function ($q) {
                        $q->where('status', 1)
                            ->where('type', 1)
                            ->select(['id', 'name']);
                    });
                })
                ->withWhereHas('orderType', function ($q) {
                    $q->where('status', 1);
                })
                ->with([
                    'agentCashFlowOrderTypeNames' => function ($q) use ($languageId) {
                        $q->whereIn('language_id', array_unique([$languageId, LanguageEnum::EN->value]))
                            ->select('id', 'type_id', 'language_id', 'name');
                    },
                ])
                ->orderBy('id')
                ->get()
                // 同 order_type 多列設定（同金流商多商戶號＝合法配置）時取最早建立（最小 id）；
                // 舊查詢無排序、重複時行為未定義，此為契約化的確定性規則
                ->unique('order_type_id')
                ->sortByDesc('sorts')
                ->values();
        });
    }

    private function getAgentCashFlow(Agent $agent, int $cashFlowId, int $settingKey): ?AgentCashFlow
    {
        $checkCustomized = $this->agentService->getSpecificAgentSetting(
            $agent,
            $settingKey
        );

        if ($checkCustomized != 1) {
            return null;
        }

        $agentCashFlow = AgentCashFlow::query()
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->where('cash_flow_id', $cashFlowId)
            ->where('status', 1)
            ->whereHas('cashFlow', function ($query) use ($cashFlowId) {
                $query->where('id', $cashFlowId);
                $query->where('status', 1);
            })
            ->first();

        return $agentCashFlow;
    }

    private function getCustomizeSettings(int $cashFlowId, int $agentGroupId): string
    {
        $row = AgentCashFlowOrderType::where('status', 1)
            ->whereHas('orderType', function ($query) {
                $query->where('key', '--')
                    ->where('status', 1);
            })
            ->whereHas('agentCashFlow', function ($query) use ($agentGroupId, $cashFlowId) {
                $query->where('agent_group_id', $agentGroupId)
                    ->where('cash_flow_id', $cashFlowId)
                    ->where('status', 1);
            })
            ->select('id', 'settings')
            ->first();

        return $row->settings ?? '';
    }
}
