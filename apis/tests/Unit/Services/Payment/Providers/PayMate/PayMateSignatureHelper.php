<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayMate;

/**
 * 測試用：依 api.md 1.1/2.1/4.2 簽名規則生成合法 signMsg，
 * 與 PayMateTrait::generateSign() 邏輯一致，供組建 Http::fake 回應與 Notify request 使用
 */
trait PayMateSignatureHelper
{
    private function paymateSign(array $fields, string $md5Key): string
    {
        ksort($fields, SORT_STRING);

        $signStr = '';
        foreach ($fields as $key => $value) {
            $signStr .= $key . ':' . $value . '&';
        }
        $signStr .= 'key:' . $md5Key;

        return strtoupper(md5($signStr));
    }

    private function buildResponseEnvelope(
        array $innerResponse,
        string $merchantNo,
        string $md5Key,
        string $resCode = '000',
        string $resMsg = 'success'
    ): array {
        $fields = [
            'merchantNo' => $merchantNo,
            'resCode' => $resCode,
            'resMsg' => $resMsg,
            'response' => base64_encode(json_encode($innerResponse)),
            'version' => '1.0',
        ];

        $fields['signMsg'] = $this->paymateSign($fields, $md5Key);

        return $fields;
    }

    private function buildNotifyPayload(
        array $content,
        string $md5Key,
        string $notifyTime = '20260101120000'
    ): array {
        $fields = [
            'content' => base64_encode(json_encode($content)),
            'notifyTime' => $notifyTime,
            'version' => '1.0',
        ];

        $fields['signMsg'] = $this->paymateSign($fields, $md5Key);

        return $fields;
    }
}
