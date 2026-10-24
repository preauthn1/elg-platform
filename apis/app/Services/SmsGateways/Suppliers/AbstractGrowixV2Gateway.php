<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Exceptions\ApiException;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

/**
 * Growix v2 API（api.otp.expert）共用邏輯，SMS／WhatsApp 只差 request 的 type 值。
 */
abstract class AbstractGrowixV2Gateway extends AbstractGateway implements SmsGatewayInterface
{
    abstract protected function getMessageType(): string;

    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;

        /** @var \stdClass $info */
        $info = $smsGatewayInfo->info;

        if (isset($info->ApiKey) && empty($info->ApiKey)) {
            throw new ApiException('Missing ApiKey');
        }

        $url = $smsGatewayInfo->url . '/api/send';

        // 格式化 mobile 為國際格式 E.164 format
        $mobile = $smsSendData->mobile;
        if (strpos($mobile, '+') !== 0) {
            $mobile = '+' . $mobile;
        }

        // 格式化 OTP, 只保留數字
        $otp = $smsSendData->content;
        $otp = preg_replace('/[^0-9]/', '', $otp);

        $data = [
            'type' => $this->getMessageType(),
            'recipient' => $mobile,
            'message' => $otp,
        ];

        $headers = [
            'X-API-KEY' => $info->ApiKey,
        ];

        $result = $this->sendRequest($url, $data, $headers);
        /* response example
        {
        "message": "Message sent successfully.",
        "final_cost": 0.0175,
        "remaining_credits": 57636.5633,
        "country_code": "61"
        }
        */
        // 檢查返回結果
        if (isset($result['message']) && str_contains(strtolower($result['message']), 'success')) {
            $status = 1;
        } else {
            $status = 2;
        }

        return SmsResultData::from([
            'status' => $status,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => '',
        ]);

    }

    protected function sendRequest(string $url, array $data, array $headers = [])
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->asJson()
            ->withHeaders($headers)
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
