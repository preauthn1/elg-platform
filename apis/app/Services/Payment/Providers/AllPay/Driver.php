<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AllPay;

use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\AbstractDriver;
use App\Services\Payment\Contracts\SupportsDepositQuery;
use App\Services\Payment\Data\QueryOrderRequestData;
use App\Services\Payment\Data\QueryOrderResponseData;

/**
 * AllPay 聚合金流。APIs 這邊只實作代收（本目錄沒有 Withdrawal／WithdrawalCallback）；cash_flows 的 W_ALL
 * 條目在各白標仍是 status=1 且有活綁定，代付由舊系統發起（cash_flow_id 在分界線 62 以內），不是已退場。
 *
 * 白標 cash_flows.name 帶 [A] 前綴代表該渠道是透過 AllPay 聚合取得的：那是人工加的名稱前綴，
 * 不是系統欄位，只有部分白標有標，不可拿它當跨白標的自動判準。
 */
class Driver extends AbstractDriver implements SupportsDepositQuery
{
    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected QueryOrder $queryOrder;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);
        $this->queryOrder = new QueryOrder($agentCashFlow);
    }

    public function queryOrder(Order $order): QueryOrderResponseData
    {
        $data = QueryOrderRequestData::from([
            'type' => 'deposit', // 代收
            'order' => $order,
        ]);

        return $this->queryOrder->handle($data);
    }
}
