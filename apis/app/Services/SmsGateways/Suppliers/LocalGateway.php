<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Exception;
use Illuminate\Support\Str;

class LocalGateway extends AbstractGateway implements SmsGatewayInterface
{
    /**
     * 本地模擬
     *
     * @throws Exception
     */
    public function send(SmsSendData $smsSendData): SmsResultData
    {

        // 模擬成功
        $transactionId = Str::uuid()->toString();

        return SmsResultData::from([
            'status' => 1, // 模擬成功
            'response' => json_encode(['status' => 1, 'transactionId' => $transactionId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);
    }
}
