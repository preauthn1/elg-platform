<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Exceptions\ApiException;
use App\Models\Country;
use App\Models\SmsGatewayGroupDetail;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class FgGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $member = $smsSendData->member;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        // sms_gateway_group_details
        $agent = $member->agent;
        $agentGroupId = $agent->sms_gateway_group_id;
        // 取得 setting 欄位
        $smsGatewayGroupDetail = SmsGatewayGroupDetail::query()
            ->where('agent_group_id', $agentGroupId)
            ->first();
        $time = $smsGatewayGroupDetail->info['token_expired'] ?? 6;

        // 透過電話找出國碼
        $country = Country::query()
            ->whereRaw("'" . $mobile . "' like concat(countries.country_code, '%')")
            ->first();

        if (!$country) {
            throw new ApiException('Unable to match phone country code');
        }

        // 手機號移除國碼
        $mobile = substr($mobile, strlen((string) $country->country_code));

        // 簡訊商資訊
        $info = $smsGatewayInfo->info;

        $url = $smsGatewayInfo->url;

        $data = [
            'no' => $info->temp_no,
            'phone' => $mobile,
            'variables' => $content . ',' . $time,
            'country_id' => $this->mapCountryId($country->id, $info->country_id),
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

    /**
     * 檢查返回結果
     *
     * code         0 成功
     *              30001 讯息内容包含敏感词
     *              30002 手机号码格式错误
     *              30003 资料未包含手机号码
     *              30006 字数超过限制 (MAX: 69)
     *
     * data         發送成功時提供
     *              no 批次号
     *              content 訊息內容
     *              phones 電話號碼
     */
    protected function getTransactionId($result): string
    {
        if (isset($result['code']) && $result['code'] == '0') {
            return $result['data']['no'] ?? '';
        }

        return '';
    }

    protected function sendRequest(string $url, array $data, object $info)
    {
        $action = class_basename(static::class);

        $headers = [
            'Authorization' => 'Bearer ' . $info->token,
        ];

        $result = Http::logAction($action)
            ->asForm()
            ->withHeaders($headers)
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
