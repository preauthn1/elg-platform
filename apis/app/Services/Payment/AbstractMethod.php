<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\AgentCashFlow;
use App\Services\Payment\Traits\CallbackSourceGuardTrait;
use App\Services\Payment\Traits\CredentialGuardTrait;
use App\Services\Payment\Traits\DepositCallbackTrait;
use App\Services\Payment\Traits\DriverTrait;
use App\Services\Payment\Traits\RecordsThirdPartyExchangeTrait;
use App\Services\Payment\Traits\WithdrawalCallbackTrait;
use App\Services\Payment\Traits\WithdrawalPayoutTrait;

abstract class AbstractMethod
{
    use CallbackSourceGuardTrait;
    use CredentialGuardTrait;
    use DepositCallbackTrait;
    use DriverTrait;
    use RecordsThirdPartyExchangeTrait;
    use WithdrawalCallbackTrait;
    use WithdrawalPayoutTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    /**
     * 回調成功響應文本（各金流商可覆蓋此屬性）
     */
    protected string|array $notifySuccessText = 'success';

    /**
     * 回調失敗響應文本（各金流商可覆蓋此屬性）
     */
    protected string|array $notifyFailText = 'fail';

    /**
     * 在 Driver.php 注入代理的金流商配置 $agentCashFlow->info
     */
    public function setInfo(array $info): void
    {
        $this->info = $info;
    }
}
