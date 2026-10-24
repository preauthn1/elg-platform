<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class BulkGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        // 簡訊商資訊
        $info = $smsGatewayInfo->info;

        $url = rtrim($smsGatewayInfo->url, '/') . '/v3_0/send.php';

        $data = [
            'user' => isset($info->AppKey) ? urlencode($info->AppKey) : '',
            'pass' => isset($info->AppSecret) ? urlencode($info->AppSecret) : '',
            // 最多150个字符
            // 短信接收端手机号码, 必须为国际号码格式(不带"+"符号)
            // 欲批量发送多个接受端手机号码, 可用英文逗号(,)分开每个手机号
            // 譬如: 60123240066,60102200533,60166600267
            'to' => $mobile,
            // 'from' => '',  //SMS的源名称, 最多只接受11个字母或数字字符. [不适用于马来西亚]
            // 短信内容. 如果MT发往马来西亚手机号(号码开端60), 系统将在短信内容开端自动加上"RM0 ". 因此短信内容的总字符数将消耗4个字符, 费用则相应地扣除
            'text' => isset($info->prefix) ? $info->prefix . ' ' . $content : $content,
        ];

        // 請求簡訊商
        $result = $this->sendRequest($url, $data);

        // 檢查返回結果
        $transactionId = $this->getTransactionId($result);

        return SmsResultData::from([
            'status' => $transactionId ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);

    }

    /**
     * 檢查返回結果
     */
    protected function getTransactionId($result): string
    {
        if (isset($result['code']) && $result['code'] == '200') {
            return $result['ref'] ?? '';
        }

        return '';
    }

    protected function sendRequest(string $url, array $data)
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->get($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
