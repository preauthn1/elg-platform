<?php

declare(strict_types=1);

namespace App\Enums\Payment;

/**
 * 三方代付查單結果三態（target/tickets#4137 擴充點）。
 */
enum PayoutQueryOutcome
{
    /** 三方有單（已受理／處理中／成功）——附三方單號時可自動補單號轉 22，未附單號仍交人工 */
    case FOUND_ACCEPTED;

    /** 三方明確查無此單——不自動改單，交人工定案（可能只是入帳延遲） */
    case NOT_FOUND;

    /** 查詢失敗／回應無法解讀——不可推斷，交人工 */
    case UNKNOWN;
}
