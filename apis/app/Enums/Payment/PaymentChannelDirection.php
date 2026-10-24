<?php

declare(strict_types=1);

namespace App\Enums\Payment;

/**
 * 三方通道的資金方向。SurePay bankquery 的 banktype D/W 映射於此；
 * PayMate /setting 的 PAYOUT 線路為 Payout、其餘為 Deposit。
 */
enum PaymentChannelDirection: string
{
    case Deposit = 'deposit';
    case Payout = 'payout';
}
