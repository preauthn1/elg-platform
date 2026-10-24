<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AllPay;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\QueryOrderRequestData;
use App\Services\Payment\Data\QueryOrderResponseData;
use App\Services\Payment\Enums\ProviderOrderStatusEnum;

class QueryOrder extends AbstractMethod
{
    use AllPayTrait;

    protected AgentCashFlow $agentCashFlow;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(QueryOrderRequestData $data): QueryOrderResponseData
    {
        $service = ($data->type === 'deposit') ? 'QRH5' : 'PAYOUT';
        $order = $data->order;

        $params = [
            'service' => $service, // QRH5: 存款订单, PAYOUT: 出款订单
            'orderNo' => $order->order_no,
            // 'gbpOrderNo' => $order->third_party_order_no, // 与 orderNo 二择一
        ];

        $result = $this->sendRequest('/query', $params);

        // 外層信封（resCode）在 sendRequest 已驗證並於失敗時拋出，此處 $result 為內層業務 JSON：
        // code === '0000' 才代表查詢成功、status 才有意義（api.md「查詢響應」範例即 code 0000
        // 與 status SUCCESS 併存）；業務層失敗（code 非 0000）時 status 不可信，維持 ERROR
        // 並由 errorMessage 帶出 msg
        $orderStatus = ProviderOrderStatusEnum::ERROR;
        if (isset($result['code'], $result['status']) && $result['code'] === '0000') {
            $orderStatus = match ($result['status']) {
                'SUCCESS' => ProviderOrderStatusEnum::SUCCESS,
                'PROCESSING',
                'PAYING' => ProviderOrderStatusEnum::PROCESSING,
                'FAIL' => ProviderOrderStatusEnum::FAIL,
                default => ProviderOrderStatusEnum::ERROR,
            };
        }

        return QueryOrderResponseData::from([
            'type' => $data->type,
            'orderNo' => $order->order_no,
            'orderStatus' => $orderStatus,
            'errorMessage' => $result['msg'] ?? '',
        ]);
    }
}
