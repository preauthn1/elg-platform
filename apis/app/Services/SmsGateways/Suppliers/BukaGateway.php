<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class BukaGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        // 簡訊商資訊
        $info = $smsGatewayInfo->info;

        $url = rtrim($smsGatewayInfo->url, '/') . '/sendSms';

        $data = [
            'appId' => $info->AppId ?? '',
            'numbers' => $mobile,
            'content' => $content,
        ];

        // 請求簡訊商
        $result = $this->sendRequest($url, $data, $info);

        // 檢查返回結果
        $transactionId = $this->getTransactionId($result);

        return SmsResultData::from([
            'status' => $transactionId ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);

    }

    /*
     * 檢查返回結果
     *
     * {
     *      "status": "0",
     *      "reason": "success",
     *      "success": "1",
     *      "fail": "0",
     *      "array": [
     *          {
     *              "msgId": "2306051655331199457",
     *              "number": "6289687949693",
     *              "orderId": null
     *          }
     *      ]
     *  }
     */
    protected function getTransactionId($result): string
    {
        if (isset($result['status']) && $result['status'] == '0') {
            return $result['array'][0]['msgId'] ?? '';
        }

        return '';
    }

    protected function sendRequest(string $url, array $data, object $info)
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->withHeaders($this->prepareRequestHeaders($info))
            ->get($url, $data)
            ->throw()
            ->json();

        return $result;
    }

    /**
     * 整理請求頭
     */
    protected function prepareRequestHeaders(object $info): array
    {
        $timestamp = time();

        $sign = md5(($info->ApiKey ?? '') . ($info->ApiSecret ?? '') . $timestamp);

        return [
            'Content-Type' => 'application/json;charset=UTF-8',
            'Sign' => $sign,
            'Timestamp' => $timestamp,
            'Api-Key' => $info->ApiKey ?? '',
        ];
    }
}
