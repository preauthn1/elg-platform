<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CashFlowTypeEnum;
use App\Enums\General\CacheTtl;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Models\WithdrawalOrder;
use Exception;
use Illuminate\Support\Facades\Cache;

/**
 * @knowledge rules/agent-self-deposit
 * @knowledge rules/deposit-order-fees
 */
class OrderService
{
    public function getLatestOrder(Member $member, CashFlowTypeEnum $cashFlowType, array $status = [0]): ?Order
    {
        $agent = $member->agent;

        // 快取符合條件的 agent_cash_flow_id，避免多層 EXISTS 子查詢
        $cacheKey = "agent_cash_flow_ids:{$agent->cash_flow_group_id}:{$cashFlowType->value}";
        $validAgentCashFlowIds = Cache::remember($cacheKey, CacheTtl::MOMENT->value, fn () => AgentCashFlow::query()
            ->where('agent_group_id', $agent->cash_flow_group_id)
            ->whereRelation('cashFlow', 'type', $cashFlowType->value)
            ->pluck('id')
            ->toArray()
        );

        if (empty($validAgentCashFlowIds)) {
            return null;
        }

        return Order::query()
            ->where('member_id', $member->id)
            ->whereIn('agent_cash_flow_id', $validAgentCashFlowIds)
            ->whereHas('orderImages')
            ->whereIn('status', $status)
            ->latest('id')
            ->first();
    }

    public function getOrder(string $type, string $orderNo)
    {
        if ($type == 'deposit') {
            return Order::query()
                ->where('order_no', $orderNo)
                ->with(['agentCashFlow', 'orderType'])
                ->first();
        }

        if ($type == 'withdraw') {
            return WithdrawalOrder::query()
                ->where('order_no', $orderNo)
                ->with(['agentCashFlow', 'orderType'])
                ->first();
        }

        return null;
    }

    /**
     * 建立我司存款訂單, 得到訂單號後請求三方取得代收網址或 QRCode
     */
    public function createOrder(OrderType $orderType, AgentCashFlow $agentCashFlow, Member|Agent $member, string $amount, array $extraContent = []): ?Order
    {
        $memberField = match (true) {
            $member instanceof Agent => ['agent_id' => $member->id, 'member_id' => 0],
            $member instanceof Member => ['agent_id' => 0, 'member_id' => $member->id],
        };

        $point = Common::numMul($amount, (string) ($agentCashFlow->rate ?? 1), 4);
        $percentage = Common::numMul($point, (string) ($agentCashFlow->tax_percentage ?? 0), 4); // 浮動手續費
        $tax = Common::numAdd((string) ($agentCashFlow->tax ?? 0), $percentage, 4); // 固定手續費 + 浮動手續費
        $point = Common::numSub($point, $tax, 4); // 實際獲得點數

        $data = [
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $orderType->currency_id,
            ...$memberField,
            'amount' => Common::numFloor($amount, 4),
            'rate' => $agentCashFlow->rate ?? 1,
            'tax' => $tax,
            'point' => $point,
            // payment_cost（支付商手續費）於回調成功時由 PaymentService::updateMemberBalance 寫入，建單不算
            'balance' => 0,
            'callback' => Common::depositCallbackUrl($agentCashFlow->id),
            'status' => 0, // 三方回調 external 後更新
            'ip' => Common::getIp(),
            'third_party_order_no' => '',
            'extra_content' => $extraContent ? json_encode($extraContent) : null,
            'remark' => '',
            'admin' => 0,
        ];

        $maxAttempts = 3;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $data['order_no'] = $this->getOrderNo();
            $order = Order::create($data);
            if ($order) {
                return $order;
            }
        }

        throw new Exception('Failed to create order after multiple attempts.');
    }

    public function getOrderNo(): string
    {
        $date = date('Ymd');
        $random = random_int(100000, 999999);
        $micro = substr(sprintf('%01.4f', microtime(true)), -4);

        return $date . $random . $micro;
    }
}
