<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp
 */
class WaGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        // 簡訊商配置
        $info = $smsGatewayInfo->info;
        $apiKey = [REDACTED] ?? '';
        $sender = $info->sender ?? '';

        $url = rtrim($smsGatewayInfo->url, '/') . '/send-message';

        $data = [
            'api_key' => $apiKey,
            'sender' => $sender,
            'number' => $mobile,
            'message' => $content,
        ];

        // 請求簡訊商
        $result = $this->sendRequest($url, $data);

        return SmsResultData::from([
            'status' => (isset($result['status']) && $result['status']) ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => '',
        ]);

    }

    protected function sendRequest(string $url, array $data): array
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
