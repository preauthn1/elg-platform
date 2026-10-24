<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\B3Wallet;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractDriver;

class Driver extends AbstractDriver
{
    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Withdrawal $withdrawal;

    protected WithdrawalCallback $withdrawalCallback;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);
    }
}
