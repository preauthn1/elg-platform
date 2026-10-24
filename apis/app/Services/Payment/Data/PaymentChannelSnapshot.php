<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

/**
 * SupportsPaymentChannelQuery 的回傳值：商戶層欄位全 nullable——
 * PayMate /setting 有、SurePay bankquery 沒有，沒有就是 null，消費端自行判空呈現。
 * raw 為遮罩後的三方原始回應（進 DTO 前必過 Common::maskCredentials）。
 */
final readonly class PaymentChannelSnapshot
{
    /**
     * @param  list<PaymentChannel>  $channels
     * @param  array<array-key, mixed>  $raw
     */
    public function __construct(
        public ?string $merchantStatus,
        public ?string $depositStatus,
        public ?string $payoutStatus,
        public ?string $availableBalance,
        public ?string $totalBalance,
        public array $channels,
        public array $raw,
    ) {}
}
