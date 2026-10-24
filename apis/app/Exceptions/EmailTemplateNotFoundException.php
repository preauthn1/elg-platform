<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * 驗證碼郵件查無樣板（該語系與全語系預設列都不存在）。
 *
 * 比照 {@see SmsGatewayException}：刻意不繼承 ApiException（ApiException 於 AlertGate 一律靜音），
 * 告警與 log 走框架 report 管線，玩家端由 bootstrap/app.php render() 統一回泛化訊息 error_message.2003。
 *
 * ⚠️ AlertGate 的降噪 key 是「例外建構位置 file:line」，因此必須在失敗點就地 `new`，不可集中到共用 helper 內建構。
 */
class EmailTemplateNotFoundException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $memberId,
        public readonly string $email,
        public readonly int $emailGatewayGroupId,
        public readonly int $typeId,
        public readonly int $languageId,
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
        return [
            'member_id' => $this->memberId,
            'email' => $this->email,
            'email_gateway_group_id' => $this->emailGatewayGroupId,
            'type_id' => $this->typeId,
            'language_id' => $this->languageId,
        ];
    }
}
