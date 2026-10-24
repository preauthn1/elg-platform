<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class IctGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;

        // $info = $smsGatewayInfo->info;

        $url = $smsGatewayInfo->url;

        $data = [
            'action' => 'send_otp',
            'destination' => $smsSendData->mobile,
            'otp' => $smsSendData->content,
        ];

        // 請求簡訊商
        $result = $this->sendRequest($url, $data);

        // 檢查返回結果
        $transactionId = $this->getTransactionId($result);

        return SmsResultData::from([
            'status' => $transactionId ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);

    }

    /**
     * 檢查返回結果
     */
    protected function getTransactionId($result): string
    {
        if (isset($result['data']['sms_id'])) {
            return $result['data']['sms_id'];
        }

        return '';
    }

    protected function sendRequest(string $url, array $data): array
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
