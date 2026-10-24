<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TtPay;

use App\Models\AgentCashFlow;
use App\Services\Payment\Providers\DgPay\Driver as DgPayDriver;

/**
 * TTPay ＝ DGPay 換名白牌，API 規格、簽名與回調行為完全相同（規格見 docs/integrations/payments/DgPay/）。
 * 全部類別以薄繼承殼沿用 DgPay 實作；getDriverName() 依目錄名解析，log 與錯誤訊息自動標示 TtPay。
 */
class Driver extends DgPayDriver
{
    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        // 覆蓋父類建構的 DgPay 實例，使 driver name／log action 正確歸屬 TtPay
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);
    }
}
