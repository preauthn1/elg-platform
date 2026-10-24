<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class IbGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        $info = $smsGatewayInfo->info;

        $url = rtrim($smsGatewayInfo->url, '/') . '/sms/1/text/single';

        $content = isset($info->prefix) ? $info->prefix . ' ' . $content : $content;

        $data = [
            'from' => 'InfoSMS',
            'to' => $mobile,
            'text' => $content,
        ];

        $result = $this->sendRequest($url, $data, $info);

        $status = 2;

        if (isset($result['messages'])) {
            $status = ($result['messages'][0]['status']['id'] == 7) ? 1 : 2;
        }

        return SmsResultData::from([
            'status' => $status,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => '',
        ]);

    }

    /**
     * InfoBip 簽名
     */
    protected function getInfoBipSign($info): string
    {
        $user = $info->user ?? '';
        $password = [REDACTED] ?? '';
        $string = $user . ':' . $password;

        return base64_encode($string);
    }

    protected function sendRequest(string $url, array $data, $info): array
    {
        $action = class_basename(static::class);

        $headers = [
            'Authorization:Basic ' . $this->getInfoBipSign($info),
        ];

        $result = Http::logAction($action)
            ->asJson()
            ->withHeaders($headers)
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
