<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Ap8Pay;

use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\AbstractDriver;
use App\Services\Payment\Contracts\SupportsDepositQuery;
use App\Services\Payment\Data\QueryOrderRequestData;
use App\Services\Payment\Data\QueryOrderResponseData;

/**
 * Ap8Pay（AutoPay）馬來西亞代收，**僅代收無代付**。
 *
 * 回調無驗簽，來源驗證與金額判定全靠 DepositCallback 的三道防線，見該類 docblock。
 */
class Driver extends AbstractDriver implements SupportsDepositQuery
{
    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected QueryOrder $queryOrderMethod;

    public function __construct(
        AgentCashFlow $agentCashFlow,
    ) {
        parent::__construct($agentCashFlow);

        // 代收
        $this->deposit = new Deposit($agentCashFlow);
        $this->queryOrderMethod = new QueryOrder($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow, $this->queryOrderMethod);
    }

    public function queryOrder(Order $order): QueryOrderResponseData
    {
        return $this->queryOrderMethod->handle(QueryOrderRequestData::from([
            'type' => 'deposit',
            'order' => $order,
        ]));
    }
}
