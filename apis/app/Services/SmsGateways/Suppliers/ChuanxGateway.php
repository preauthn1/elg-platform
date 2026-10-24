<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

/**
 * @see https://example.invalid/:w:/r/personal/mars_terpro_com1/_layouts/15/Doc.aspx?sourcedoc=%7BAD53192C-3F0A-4204-92C0-3D82A7F3B27D%7D&file=Jiangsu_Chuanxin_cloud_international_SMS_HTTP_interface_document.docx&action=default&mobileredirect=true
 *
 * code   00000    提交成功
 *        F0001    参数appkey未填写
 *        F0002    参数appcode未填写
 *        F0003    参数phone未填写
 *        F0004    参数sign未填写
 *        F0005    参数timestamp未填写
 *        F0006    appkey不存在
 *        F0007    账号已经关闭
 *        F0008    sign检验错误
 *        F0009    账号下没有业务
 *        F0010    业务不存在
 *        F0011    手机号码超过1000个
 *        F0012    timestamp不是数字
 *        F0013    timestamp过期超过5分钟
 *        F0014    请求ip不在白名单内
 *        F0015    余额不足
 */
class ChuanxGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;

        // 簡訊商配置
        $info = $smsGatewayInfo->info;

        $url = rtrim($smsGatewayInfo->url, '/') . '/batch/v2';

        $mobile = $smsSendData->mobile;
        $content = isset($info->prefix) ? $info->prefix . ' ' . $smsSendData->content : $smsSendData->content;

        $data = [
            // 应用key
            'appkey' => $info->appkey ?? '',
            // 应用秘钥
            'appsecret' => $info->appsecret ?? '',
            'appcode' => $info->appcode ?? '',
            // 手机号码, 短信内容长度不超过1000个字（包括1000字）,每个英文或阿拉伯字符算1个字
            // Q:电话需要带国码? A:0084加手机号码
            'phone' => '00' . $mobile,
            // 下发短信内容
            'msg' => $content,
            // 扩展号，该参数是显示在接收手机上的主叫尾号，可用于上行信息匹配，若不填写则认为不进行扩展（不填写不影响发送）
            'extend' => '',
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
     *
     * {
     *   "code": "00000",
     *   "desc": "提交成功",
     *   "uid": "fe0304d3aae84c7ea69e92fc873c5517",
     *   "result": [
     *     {
     *       "status": "00000",
     *       "phone": "84586061296",
     *       "desc": "提交成功"
     *     }
     *   ]
     * }
     */
    protected function getTransactionId($result): string
    {
        if (isset($result['code']) && $result['code'] == '00000') {
            return $result['uid'] ?? '';
        }

        return '';
    }

    protected function sendRequest(string $url, array $data): array
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->get($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
