<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Enums\Payment\PaymentChannelDirection;

/**
 * 三方端單一通道的標準化快照。金額欄位一律已過 Common::numAdd('0', ..., 4) 正規化，
 * 三方未回傳的欄位為 null（不偽造）。PII（戶名／銀行帳號）不入本 DTO，只留在遮罩後的 raw。
 */
final readonly class PaymentChannel
{
    /**
     * @param  string  $name  通道識別（PayMate＝payments[].name；SurePay＝iban 通道碼）
     * @param  list<string>  $supportBankCodes  該通道**可收付的**三方銀行短代碼；掃碼類與
     *                                          三方未提供此清單者為空陣列
     * @param  ?string  $accountBankName  該通道自身收付款帳戶的開戶行（SurePay bankquery 的
     *                                    `bankname`）。與 $supportBankCodes 語意不同、刻意分欄：
     *                                    開戶行不代表「能出款到哪些銀行」，混為一談會讓代付
     *                                    銀行比對誤報整批缺口。三方未提供則為 null。
     */
    public function __construct(
        public string $name,
        public ?PaymentChannelDirection $direction,
        public ?string $status,
        public ?string $minAmount,
        public ?string $maxAmount,
        public array $supportBankCodes,
        public ?string $todayLimit = null,
        public ?string $totalAmount = null,
        public ?string $accountBankName = null,
    ) {}
}
