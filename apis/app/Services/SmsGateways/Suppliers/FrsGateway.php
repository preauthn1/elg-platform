<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Facades\BotNotify;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class FrsGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        $info = $smsGatewayInfo->info;

        $method = strtolower($info->Method ?? 'get');
        $time = time();
        $apiUsername = $info->Username ?? '';
        $apiPassword = [REDACTED] ?? '';
        $templateId = (int) ($info->TemplateId ?? 0);
        $action = $info->Action ?? 'MARKETING';
        $codeId = $this->mapCountryId($smsGatewayInfo['country_id'], $info->CountryId);

        // 簽名
        $sign = md5($apiUsername . $apiPassword . $time);

        $url = sprintf(
            '%sv1/sms/sendbatchsms?account=%s&sign=%s&datetime=%s',
            $smsGatewayInfo->url,
            $apiUsername,
            $sign,
            $time
        );

        $data = [
            'numbers' => substr($mobile, strlen($smsGatewayInfo['country_code'])), // 要去除國碼
            'codeId' => $codeId, // 见下国家国码参数表
            'action' => $action, // 业务类型, 营销:MARKETING 验证:VERIFY 通知:NOTIFICATION
        ];

        // 短信模板和 content 任选一种即可!
        if ($templateId > 0) {
            $data['templateId'] = $templateId;
            $data['templateParams'] = [$content];
        } else {
            $data['content'] = $content;
        }

        // 請求簡訊商
        $result = $this->sendRequest($method, $url, $data);

        if (isset($result['status']) && $result['status'] == -10) {
            $message = '【FRS餘額不足】 ' . PHP_EOL .
                '名稱:' . $apiUsername;
            BotNotify::send($message);
        }

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
     * 短信接收号码，多个号码之间以英文逗号分隔（GET请求一次最多1000个,POST请求一次最多10000个）
     * 号码无需添加国码，电话号码 (多笔请使用 [逗号] 隔开)
     * 注：若号段和国码一样必须携带国码
     *
     * stats        0:提交成功
     *              1:发送成功
     *              2:发送失败
     *              3:未找到通道
     *              4:国码错误
     *              5:黑号码限制
     *              -1:认证错误
     *              -2:IP访问受限
     *              -3:短信内容含有敏感字符
     *              -4:短信内容为空
     *              -5:短信内容过长
     *              -6:不是模板的短信或大陆、香港、澳门、台湾方向未报备
     *              -7:号码个数过多
     *              -8:号码为空
     *              -9:号码异常
     *              -10:该通道余额不足，不能满足本次发送
     *              -11:定时时间格式不对
     *              -12:由于平台的原因，批量提交出错，请与管理员联系
     *              -13:用户被锁定
     *              -14:查询id异常
     *              -15:请求频率过快
     *              -16:超出时间范围限制
     *              -17:短信模版不能为空
     *              -18:国码ID不能为空
     *              -19:国码ID错误
     * data         提交短信成功的数组,数组中依次包含“提交成功的个数、提交失败的个数、提交短信成返回的批次号”，均为字符串
     */
    protected function getTransactionId($result): string
    {
        if (isset($result['status']) && $result['status'] == '0') {
            $data = $result['data'];
            // 因為一次只提交一筆, 所以驗 success=1 就好
            if ($data['success'] == 1) {
                return $data['batchNo'];
            }
        }

        return '';
    }

    protected function sendRequest(string $method, string $url, array $data)
    {
        $action = class_basename(static::class);

        // 文檔沒寫, 但 headers 需加上 User-Agent, 否則會報錯
        $headers = [
            'User-Agent' => '123456',
        ];

        $result = Http::logAction($action)
            ->asForm()
            ->withHeaders($headers)
            ->{$method}($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
