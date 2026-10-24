<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class TrfGateway extends AbstractGateway implements SmsGatewayInterface
{
    protected string $supplier = 'TraffiLink';

    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;

        // 簡訊商配置
        $info = $smsGatewayInfo->info;

        $url = rtrim($info?->ApiUrl ?? $smsGatewayInfo->url, '/') . '/sendsms';

        $data = [
            'account' => $info->ApiKey,         // account
            'password' => $info->ApiSecret,     // password
            // 'sender' => config('app.name'),
            'content' => $smsSendData->content,
            'smstype' => 0,
            'numbers' => $smsSendData->mobile,  // 接收訊息者
        ];

        $result = $this->sendRequest($url, $data);

        // 非零代表 API 回傳錯誤，直接回傳失敗，不嘗試讀取 array 鍵
        if (!isset($result['status']) || $result['status'] !== 0) {
            return SmsResultData::from([
                'status' => 2,
                'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'transactionId' => '',
            ]);
        }

        // getTransactionId
        $transactionId = '';
        foreach ($result['array'] ?? [] as $row) {
            if (!isset($row[0], $row[1])) {
                continue;
            }
            if ($data['numbers'] === $row[0]) {
                $transactionId = $row[1];
                break;
            }
        }

        return SmsResultData::from([
            'status' => 1,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);

    }

    protected function sendRequest(string $url, array $data): mixed
    {
        $action = $this->supplier . '_sendSms';

        $result = Http::logAction($action)
            ->asJson()
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
