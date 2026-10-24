<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class SgGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;

        $info = $smsGatewayInfo->info;

        $url = $smsGatewayInfo->url . 'api_send.php';

        $content = isset($info->prefix) ? $info->prefix . ' ' . $smsSendData->content : $smsSendData->content;

        $data = [
            'method' => 1, // 1:立即發送，2:預約發送
            'sms_msg' => $content,
            'phone' => $smsSendData->mobile,
            'username' => $info->username ?? '',
            'password' => $info->password ?? '',
        ];

        $result = $this->sendRequest($url, $data);

        return SmsResultData::from([
            'status' => ($result['stats'] == 1) ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => '',
        ]);

    }

    protected function sendRequest(string $url, array $data)
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
