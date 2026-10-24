<?php

declare(strict_types=1);

namespace App\Enums\Payment;

enum WithdrawalCallbackOutcome
{
    case SUCCESS;        // 明確成功 → THIRD_PARTY_SUCCESS (24)
    case FAILURE;        // 明確失敗 → THIRD_PARTY_FAILED (29)
    case INDETERMINATE;  // 不明確 / 未知 / 中間態 → 維持 PAYMENT_PROCESSING (22)
}
