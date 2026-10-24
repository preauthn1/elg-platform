<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Exceptions\ApiException;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class GrowixGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;

        $info = $smsGatewayInfo->info;

        if (isset($info->accessId) && empty($info->accessId)) {
            throw new ApiException('Missing accessId');
        }

        if (isset($info->accessToken) && empty($info->accessToken)) {
            throw new ApiException('Missing accessToken');
        }

        $url = $smsGatewayInfo->url . '/api/v1/index.php';

        $data = [
            'module' => '/sms/sendOTP',
            'accessId' => $info->accessId,
            'accessToken' => $info->accessToken,
            'mobile' => $smsSendData->mobile,
            'otp' => $smsSendData->content,
        ];

        $result = $this->sendRequest($url, $data);

        $status = match ($result['status']) {
            'SUCCESS' => 1,
            'ERROR' => 2,
            default => 0
        };

        return SmsResultData::from([
            'status' => $status,
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
