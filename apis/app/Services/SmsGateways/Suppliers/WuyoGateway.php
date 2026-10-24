<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * 無憂 / Wuyo 簡訊一對一發送
 * sign: MD5(username + timestamp + MD5(password))，timestamp 毫秒
 *
 * @see wuyo.txt（專案根目錄）
 */
class WuyoGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        $info = $smsGatewayInfo->info;
        $username = (string) ($info->username ?? '');
        $password = [REDACTED] ($info->password ?? '');
        $timestamp = Carbon::now()->getTimestampMs();

        $url = rtrim($smsGatewayInfo->url, '/') . '/sms/api/sendMessageMass';

        $sign = $this->computeSign($username, $timestamp, $password);

        $payload = [
            'userName' => $username,
            'content' => $content,
            'phoneList' => [$mobile],
            'timestamp' => $timestamp,
            'sign' => $sign,
        ];

        $result = $this->sendRequest($url, $payload);
        // code 只有 0 才算成功，其他都算失敗
        $code = (string) ($result['code'] ?? '');
        $transactionId = (string) ($result['msgId'] ?? '');

        return SmsResultData::from([
            'status' => $code === '0' ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);

    }

    /**
     * MD5(username + timestamp + MD5(password))
     */
    protected function computeSign(string $username, int $timestamp, string $password): string
    {
        return md5($username . (string) $timestamp . md5($password));
    }

    protected function sendRequest(string $url, array $data): array
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->asJson()
            ->withHeaders([
                'Accept' => 'application/json',
            ])
            ->post($url, $data)
            ->throw()
            ->json();

        return is_array($result) ? $result : [];
    }
}
