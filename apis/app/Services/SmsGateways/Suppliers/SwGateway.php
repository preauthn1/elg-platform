<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

/**
 * @see https://example.invalid/file/Broadcast_API_V1.2.pdf
 */
class SwGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;

        $info = $smsGatewayInfo->info;

        $url = rtrim($smsGatewayInfo->url, '/') . '/ReceiveSMS.aspx';

        $content = isset($info->prefix) ? $info->prefix . ' ' . $smsSendData->content : $smsSendData->content;

        $data = [
            'ContentType' => 1, // 1:ASCII text, 2:UCS formatted
            'MsgText' => $content,
            'MobileNumber' => $smsSendData->mobile,
            'Username' => $info->username ?? '',
            'Password' => $info->password ?? '',
        ];

        $result = $this->sendRequest($url, $data);

        return SmsResultData::from([
            'status' => ($result[0] == '1') ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $result[1] ?? '',
        ]);

    }

    protected function sendRequest(string $url, array $data): array
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->asForm()
            ->post($url, $data)
            ->throw();

        $result = explode(':', $result->body());

        return $result;
    }
}
