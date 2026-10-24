<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Sure;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractDriver;
use App\Services\Payment\Data\BalanceResponseData;

class Driver extends AbstractDriver
{
    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Balance $balance;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);
        $this->balance = new Balance($agentCashFlow);
    }

    public function balance(): BalanceResponseData
    {
        return $this->balance->handle();
    }
}
