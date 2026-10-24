<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * 積分餘額不足（target/tickets#4233）。
 *
 * MemberScoreService::deduct() 在餘額不足時拋出，整筆擋下、不做部分扣除。
 * 訊息走 error_message.score.insufficient 語系（依請求語系翻譯，帶可用／要求數）；
 * available／requested 另以屬性保留原值，供呼叫端（活動 V2、CLI）自行組訊息或對映錯誤碼。
 */
class InsufficientScoreException extends ApiException
{
    public function __construct(
        public readonly string $available,
        public readonly string $requested,
    ) {
        parent::__construct(
            __('error_message.score.insufficient', [
                'available' => $available,
                'requested' => $requested,
            ]),
            400,
        );
    }
}
