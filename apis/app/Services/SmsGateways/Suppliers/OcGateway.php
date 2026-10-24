<?php

declare(strict_types=1);

namespace App\Services\SmsGateways\Suppliers;

use App\Data\SmsResultData;
use App\Data\SmsSendData;
use App\Facades\BotNotify;
use App\Services\SmsGateways\AbstractGateway;
use App\Services\SmsGateways\SmsGatewayInterface;
use Illuminate\Support\Facades\Http;

class OcGateway extends AbstractGateway implements SmsGatewayInterface
{
    public function send(SmsSendData $smsSendData): SmsResultData
    {
        $smsGatewayInfo = $smsSendData->smsGatewayInfo;
        $mobile = $smsSendData->mobile;
        $content = $smsSendData->content;

        $info = $smsGatewayInfo->info;
        $email = $info->email ?? '';
        $secret = [REDACTED] ?? '';
        $content = isset($info->prefix) ? $info->prefix . ' ' . $content : $content;
        $leftCreditAlert = $info->left_credit_alert ?? 100; // 餘額不足警示值

        $url = $smsGatewayInfo->url;

        $data = [
            'method' => 1, // 1:立即發送, 2:預約發送
            'sms_msg' => $content,
            'phone' => $mobile,
            'email' => $email,
            'secret' => $secret,
        ];

        // 請求簡訊商
        $result = $this->sendRequest($url, $data);

        // 檢查返回結果
        $transactionId = $this->getTransactionId($result, $leftCreditAlert);

        return SmsResultData::from([
            'status' => $transactionId ? 1 : 2,
            'response' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'transactionId' => $transactionId,
        ]);

    }

    /**
     * 檢查返回結果
     *
     * stats            True/False 發送成功或失敗
     * error_code       000 成功
     *                  001 參數錯誤
     *                  002 預約時間錯誤
     *                  003 預約時間已過期
     *                  004 發送名稱錯誤
     *                  005 發送名稱過長
     *                  006 發送名稱非正確店號
     *                  101 帳號不存在
     *                  102 帳號已停用
     *                  103 Secret 錯誤
     *                  104 IP 位址不允許
     *                  201 無任何點數
     *                  202 號碼錯誤或所屬國家未開通
     *                  203 該國家點數不足
     *                  204 無正確收件者
     *                  205 收件者超過 250 筆
     * error_msg        發送結果說明，請參考 error_code
     * data             發送成功時提供
     * sms_id           訊息 ID
     * sent_num         發送比數
     * access_ip        存取 IP
     * use_credit_list  使用點數及剩餘點數
     */
    protected function getTransactionId($result, $leftCreditAlert): string
    {

        if (isset($result['stats']) && $result['stats'] == '000') {
            $data = $result['data'];
            $transactionId = $data['sms_id'] ?? '';

            // 低於警告值(預設100)時發送餘額不足通知
            $this->checkAndNotifyLowCredit($data['use_credit_list'][0] ?? [], $leftCreditAlert);

            return $transactionId;
        }

        return '';
    }

    protected function checkAndNotifyLowCredit(array $row, $leftCreditAlert): void
    {
        if (isset($row['left_credits']) && $row['left_credits'] < $leftCreditAlert) {
            $message = '【1code餘額不足】 ' .
                '國家名稱:' . ($row['country_name'] ?? '') . '(' . ($row['country_code'] ?? '') . ') ' .
                '電話前綴:' . ($row['prefix'] ?? '') .
                '目前餘額為：' . $row['left_credits'];
            BotNotify::send($message);
        }
    }

    protected function sendRequest(string $url, array $data): array
    {
        $action = class_basename(static::class);

        $result = Http::logAction($action)
            ->asForm()
            ->post($url, $data)
            ->throw()
            ->json();

        return $result;
    }
}
