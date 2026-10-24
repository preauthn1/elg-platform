<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EasyPay;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractDriver;

class Driver extends AbstractDriver
{
    protected Balance $balance;

    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Withdrawal $withdrawal;

    protected WithdrawalCallback $withdrawalCallback;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        $this->balance = new Balance($agentCashFlow);

        // 代收
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);

        // 代付
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);
    }
}
