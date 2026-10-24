<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * deposit_manual_reviews.reason：充值訂單需要人工確認的原因
 *
 * 新增原因時在此加 case（值上限 30 字元，對應 varchar(30)）。
 * 舊代理後台只看 allow_credit、不看原因，加原因不必改 API_Agent。
 */
enum DepositManualReviewReasonEnum: string
{
    /** 金流商把這筆存款標記為風險交易（CCPayment isFlaggedAsRisky），款不在商戶餘額，不可直接上分 */
    case RISKY_TRANSACTION = 'risky_transaction';
}
