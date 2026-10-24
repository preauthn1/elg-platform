<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Sure;

use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\BalanceResponseData;
use Illuminate\Support\Facades\Http;

class Balance extends AbstractMethod
{
    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(): BalanceResponseData
    {
        $params = [
            'merchant' => $this->info['MerchantID'],
        ];

        $url = trim($this->info['APIUrl'], '/') . '/merchant/balance';

        $result = Http::asJson()
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->info['ApiKey'],
            ])
            ->post($url, $params)
            ->throw()
            ->json();

        // {
        //   "contractname": "Surepay88",
        //   "domain": "example.invalid",
        //   "inbal": "737928394.09",
        //   "outbal": "100990.14",
        //   "status": 1,
        //   "statdesc": "success"
        // }

        return BalanceResponseData::from([
            'merchantStatus' => $result['status'],
            'depositBalance' => $result['inbal'] ?? null,
            'withdrawBalance' => $result['outbal'] ?? null,
        ]);
    }
}
