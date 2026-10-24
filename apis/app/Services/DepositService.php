<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;

class DepositService
{
    public function __construct(
        protected AgentService $agentService,
    ) {}

    /**
     * 查詢首存訂單
     */
    public function getFirstDeposit(int $memberId): ?Order
    {
        return Order::where('member_id', $memberId)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->orderBy('pay_time')
            ->first();
    }

    /**
     * 確保代理支援 $orderTypeId 關聯的支付渠道
     */
    public function getOrderType(Agent $agent, int $orderTypeId): ?OrderType
    {
        return OrderType::query()
            ->where('id', $orderTypeId)
            // ->whereHas('agentCashFlow', function ($query) use ($agent) {
            //     $query->where('agent_group_id', $agent->cash_flow_group_id);
            //     $query->where('status', 1);
            // })
            // ->whereHas('agentCashFlow.agentCashFlowOrderTypes', function ($query) use ($agent) {
            //     $query->where('status', 1);
            // })
            ->with([
                'cashFlow' => function ($query) {
                    $query->where('status', 1);
                },
                'agentCashFlow' => function ($query) use ($agent) {
                    $query->where('agent_cash_flows.agent_group_id', $agent->cash_flow_group_id);
                    $query->where('agent_cash_flows.status', 1);
                },
                'agentCashFlow.agentCashFlowOrderTypes' => function ($query) {
                    $query->where('status', 1);
                },
            ])
            ->first();
    }

    /**
     * 檢查代理配置，是否需要設定支付密碼
     */
    public function shouldSetPaymentPassword(Agent $agent, Member $member): bool
    {
        $agentSetting = $this->agentService->getAgentSettings($agent);

        $checkPaymentPasswordInWalletPage = (int) ($agentSetting[AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_CHECK_TIMING_SETTING] ?? 0) === 1;

        if (!$checkPaymentPasswordInWalletPage) {
            return false;
        }

        $hasFirstDeposit = $this->getFirstDeposit($member->id);

        if ($member->balance > 0 || $hasFirstDeposit) {
            if (!$member->payment_password) {
                return true;
            }
        }

        return false;
    }

    /**
     *  檢查代理配置，是否必須完成實名認證
     */
    public function requireRealName(Agent $agent, Member $member): bool
    {
        $agentSetting = $this->agentService->getAgentSettings($agent);

        // 代理設定必須完成實名認證
        $agentSettingFirstNameCheckEnabled = (int) $agentSetting[AgentCustomizedSettingKeyEnum::DIFFERENTIATE_FIRST_NAME_MEMBER] === 1;

        if ($agentSettingFirstNameCheckEnabled) {
            return trim($member->first_name) != '';
        }

        return true;
    }
}
