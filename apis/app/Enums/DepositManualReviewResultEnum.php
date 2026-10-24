<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * deposit_manual_reviews.result：充值訂單在金流商那邊的處理結果
 *
 * 每個結果對應固定的 allow_credit（allowsCredit()），寫入一律經 DepositManualReview::createPending()／resolve()，
 * 兩欄不會互相矛盾。值上限 30 字元，對應 varchar(30)。
 */
enum DepositManualReviewResultEnum: string
{
    /** 待處理：營運還沒在金流商後台處理，不可上分 */
    case PENDING = 'pending';

    /** 退回付款人：等於拒收，充值訂單改狀態 2，不可上分 */
    case REFUNDED_TO_PAYER = 'refunded_to_payer';

    /** 退到營運方白名單地址：等於平台收下這筆錢，由營運按手動上分 */
    case REFUNDED_TO_MERCHANT = 'refunded_to_merchant';

    /** 舊代理後台的手動上分與重置狀態能不能執行 */
    public function allowsCredit(): bool
    {
        return $this === self::REFUNDED_TO_MERCHANT;
    }

    /** 是否已有處理結果；有結果才寫 resolved_at */
    public function isResolved(): bool
    {
        return $this !== self::PENDING;
    }
}
