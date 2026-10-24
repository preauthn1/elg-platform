<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * 簡訊發送失敗（挑不到簡訊商／查無樣板／三方回傳失敗）。
 *
 * 刻意不繼承 {@see ApiException}——ApiException 於 {@see \App\Services\BotNotify\AlertGate} 一律靜音。
 * 告警與 log 全交由框架 report 管線：`bootstrap/app.php` 的 report() → AlertGate（分類 → 降噪 → 發 TG），
 * 預設 logging 則吃 {@see context()}。玩家端回應由同檔 render() 統一回泛化訊息，不洩三方細節。
 *
 * ⚠️ AlertGate 的降噪 key 是「例外建構位置 file:line」（30 分鐘一窗），
 * 因此**必須在各失敗點就地 `new`**，不可集中到共用 helper 內建構——否則所有失敗原因會共用同一個冷卻窗互相遮蔽。
 */
class SmsGatewayException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $memberId,
        public readonly string $mobile,
        public readonly int $smsGatewayGroupId,
        public readonly ?int $gatewayInfoId = null,
        public readonly ?string $gatewayCode = null,
        public readonly ?string $response = null,
    ) {
        parent::__construct($message);
    }

    /**
     * 預設 logging 的結構化 context（Laravel 於 report 時自動合併）。
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'member_id' => $this->memberId,
            'mobile' => $this->mobile,
            'sms_gateway_group_id' => $this->smsGatewayGroupId,
            'sms_gateway_info_id' => $this->gatewayInfoId,
            'gateway_code' => $this->gatewayCode,
            'response' => $this->response,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * TG 告警內容（AlertGate traceInfo），SP 為客服／維運排查用的檢查指令。
     *
     * @return array<string, mixed>
     */
    public function alertContext(): array
    {
        return array_filter([
            'member' => $this->memberId,
            'mobile' => $this->mobile,
            'sms_group' => $this->smsGatewayGroupId,
            'gateway_code' => $this->gatewayCode,
            'SP' => sprintf("call ADMIN_check_sms_gateway('%s', %d, 0)", $this->mobile, $this->smsGatewayGroupId),
            'response' => $this->response,
        ], fn (mixed $value): bool => $value !== null);
    }
}
