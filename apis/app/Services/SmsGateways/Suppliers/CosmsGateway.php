<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Facades\BotNotify;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class CosmsGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        // 簡訊商資訊
        $info = $smsGatewayInfo->info;
        $account = $info->Account ?? '';
        $password = [REDACTED] ?? '';
        $secretKey = md5($password);
        $appId = $info->AppId ?? '';

        $url = rtrim($smsGatewayInfo->url, '/') . '/smsapi/sendSms';

        $data = [
            'account' => $account,
            'secretKey' => $secretKey,
            'appId' => $appId,
            'numbers' => $mobile,
            'content' => $content,
        ];

        // 請求簡訊商
        $result = $this->sendRequest($url, $data);

        if ($result['status'] == 1004) {
            $message = '【Cosms 餘額不足】 ' . PHP_EOL .
                '名稱:' . $appId;
            BotNotify::send($message);
        }

        // 檢查返回結果
        $transactionId = $this->getTransactionId($result, $mobile);

        return SmsResultData::from([
            'status' => $transactionId ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);

    }

    /**
     * 檢查返回結果
     *
     * stats     0:Success
     *           1000:账号或秘钥错误
     *           1001:参数不允许为空
     *           1002:账号禁用
     *           1003:账号未认证
     *           1004:余额不足
     *           1005:号码长度限制
     *           1006:号码格式异常
     *           1007:appId 不存在
     *           1008:appId 不可用
     *           1009:号码长度限制
     */
    protected function getTransactionId($result, $mobile): string
    {
        if (isset($result['status']) && $result['status'] == '0') {
            $list = $result['data']['arrayList'] ?? [];
            foreach ($list as $row) {
                if ($row['result'] == '0' && $row['number'] == $mobile) {
                    return $row['smsId'] ?? '';
                }
            }
        }

        return '';
    }

    protected function sendRequest(string $url, array $data)
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->asJson()
            ->withoutVerifying()  // cosms 的 SSL 憑證不合法，所以需要忽略驗證
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
