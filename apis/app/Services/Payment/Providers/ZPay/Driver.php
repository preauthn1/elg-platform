<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\ZPay;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractDriver;

class Driver extends AbstractDriver
{
    protected Deposit $deposit;

    protected DepositCallback $depositCallback;

    protected Withdrawal $withdrawal;

    protected WithdrawalCallback $withdrawalCallback;

    /**
     * 初始化 ZPay Driver
     *
     * @param  AgentCashFlow  $agentCashFlow  代理金流設定
     */
    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        // 代收
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);

        // 代付
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);
    }
}
