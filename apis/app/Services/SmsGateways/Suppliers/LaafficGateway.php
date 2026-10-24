<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

/**
 * https://example.invalid/api/sms/sendSms/
 */
class LaafficGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        // 簡訊商配置
        $info = $smsGatewayInfo->info;
        $appId = $info->AppId ?? 0;
        $apiKey = [REDACTED] ?? '';
        $apiSecret = [REDACTED] ?? '';

        $url = rtrim($smsGatewayInfo->url, '/') . '/sendsms';

        $headers = $this->generateHeaders($apiKey, $apiSecret);

        $data = [
            'appId' => $appId,
            'numbers' => $mobile,
            'content' => $content,
            'senderId' => '',
        ];

        $result = $this->sendRequest($url, $data, $headers);

        $transactionId = $this->getTransactionId($result);

        return SmsResultData::from([
            'status' => $transactionId ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);

    }

    /**
     * 檢查返回結果並取得交易 ID
     */
    private function getTransactionId(array $result): string
    {
        if (!isset($result['status'], $result['success']) || $result['status'] !== '0' || $result['success'] !== '1') {
            return '';
        }

        return $result['array'][0]['msgId'] ?? '';
    }

    /**
     * 生成請求標頭
     */
    protected function generateHeaders(string $apiKey, string $apiSecret): array
    {
        $timestamp = (string) time();

        $sign = md5(sprintf('%s%s%s', $apiKey, $apiSecret, $timestamp));

        return [
            'Sign' => $sign,
            'Timestamp' => $timestamp,
            'Api-Key' => $apiKey,
        ];
    }

    /**
     * 發送請求
     */
    protected function sendRequest(string $url, array $data, array $headers): array
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
