<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TgPay;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractDriver;

class Driver extends AbstractDriver
{
    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        // 代收
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);

        // 先不支援，有必要再加上
        // $this->balance = new Balance($agentCashFlow);
    }

    // public function balance(): BalanceResponseData
    // {
    //     return $this->balance->handle();
    // }
}
