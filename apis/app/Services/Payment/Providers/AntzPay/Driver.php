<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AntzPay;

use App\Models\AgentCashFlow;
use App\Services\Payment\Providers\Kira\Driver as KiraDriver;

/**
 * AntzPay ＝ Kira 的 Gateway v1 同源協定（規格見 docs/integrations/payments/AntzPay/），
 * 全部類別以薄繼承殼沿用 Kira 實作；差異僅代付的明確拒絕判定（業務碼白名單）與交易號前綴 ANTZ。
 * getDriverName() 依目錄名解析，log、錯誤訊息與 token 快取 key 自動歸屬 AntzPay。
 */
class Driver extends KiraDriver
{
    public function __construct(AgentCashFlow $agentCashFlow)
    {
        parent::__construct($agentCashFlow);

        // 覆蓋父類建構的 Kira 實例，使 driver name／log action 正確歸屬 AntzPay
        $this->deposit = new Deposit($agentCashFlow);
        $this->depositCallback = new DepositCallback($agentCashFlow);
        $this->withdrawal = new Withdrawal($agentCashFlow);
        $this->withdrawalCallback = new WithdrawalCallback($agentCashFlow);
    }
}
