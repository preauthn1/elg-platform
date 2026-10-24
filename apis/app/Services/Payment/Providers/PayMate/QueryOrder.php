<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayMate;

use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\QueryOrderRequestData;
use App\Services\Payment\Data\QueryOrderResponseData;
use App\Services\Payment\Enums\ProviderOrderStatusEnum;

class QueryOrder extends AbstractMethod
{
    use PayMateTrait;

    protected AgentCashFlow $agentCashFlow;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 主動反查 PayMate 訂單狀態（見 api.md 3.3 訂單查詢 Query，端點 /query）。
     *
     * service：代收 QRH5、代付 PAYOUT。
     * orderNo 為我方送出時的訂單號——代收即 order->order_no；代付為送出 /payout 時的
     * transaction_no（存於 WithdrawalPayoutRecord，WithdrawalOrder 本身不留存），故代付需反查該筆記錄。
     */
    public function handle(QueryOrderRequestData $data): QueryOrderResponseData
    {
        $isDeposit = $data->type === 'deposit';
        $order = $data->order;

        // 收窄型別：代收取 Order->order_no、代付反查最新 payout record 的 transaction_no
        if ($isDeposit) {
            $orderNo = $order instanceof Order ? $order->order_no : '';
        } else {
            $orderNo = $order instanceof WithdrawalOrder ? $this->resolvePayoutTransactionNo($order) : '';
        }

        if ($orderNo === '') {
            return QueryOrderResponseData::from([
                'type' => $data->type,
                'orderNo' => '',
                'orderStatus' => ProviderOrderStatusEnum::ERROR,
                'errorMessage' => 'query order number not found',
            ]);
        }

        $result = $this->sendRequest('/query', [
            'service' => $isDeposit ? 'QRH5' : 'PAYOUT',
            'orderNo' => $orderNo,
        ]);

        // 見 api.md 3.3：code=0000 查詢成功後 status 才有效（PENDING/PROCESSING/PAYING/SUCCESS/FAIL）
        $orderStatus = ProviderOrderStatusEnum::ERROR;
        if (($result['code'] ?? null) === '0000' && isset($result['status'])) {
            $orderStatus = match ($result['status']) {
                'SUCCESS' => ProviderOrderStatusEnum::SUCCESS,
                'PROCESSING', 'PAYING' => ProviderOrderStatusEnum::PROCESSING,
                'PENDING' => ProviderOrderStatusEnum::PENDING,
                'FAIL' => ProviderOrderStatusEnum::FAIL,
                default => ProviderOrderStatusEnum::ERROR,
            };
        }

        return QueryOrderResponseData::from([
            'type' => $data->type,
            'orderNo' => $orderNo,
            'orderStatus' => $orderStatus,
            'errorMessage' => $result['msg'] ?? '',
        ]);
    }

    /**
     * 反查代付送出時的 transaction_no（我方送 PayMate 的 orderNo），取最新一筆。
     */
    private function resolvePayoutTransactionNo(WithdrawalOrder $order): string
    {
        $record = WithdrawalPayoutRecord::query()
            ->where('withdrawal_order_id', $order->id)
            ->where('agent_cash_flow_id', $this->agentCashFlow->id)
            ->latest('id')
            ->first();

        return $record->transaction_no ?? '';
    }
}
