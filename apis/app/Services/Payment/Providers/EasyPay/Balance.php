<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EasyPay;

use App\Exceptions\ApiException;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\BalanceResponseData;

class Balance extends AbstractMethod
{
    use EasyPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(): BalanceResponseData
    {
        $result = $this->sendRequest('/merchant/check_balance');

        // sendRequest() 不再把非 2xx 轉成例外（見 EasyPayTrait），失敗信封會走到這裡；
        // 不擋的話 data.balance 取不到就靜默回 null，後台看起來像「查得到、但餘額是空的」
        if (($result['status'] ?? null) !== 1) {
            throw new ApiException(
                $this->getDriverName() . ' balance error: ' . ($result['code'] ?? 'Unknown error')
            );
        }

        return BalanceResponseData::from([
            'merchantStatus' => 1,
            'depositBalance' => $result['data']['balance'] ?? null,
            'withdrawBalance' => null,
        ]);
    }
}
