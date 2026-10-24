<?php

declare(strict_types=1);

namespace App\Vendors\RGSW\Enums;

/**
 * 注單被事後改動的原因，會原字串寫進 `rgsw_bet_records.info` 的 `corrections[].type`。
 *
 * 值沿用改用 enum 之前就已經在生產資料裡的字面，所以既有紀錄相容。
 * 注意這裡的 case 與 BetRecordStatusEnum 的 DEBIT / CREDIT / SUMBET 不同一回事：
 * 調整類回調刻意不動注單狀態（見 RgswController），那三個狀態值目前沒有任何路徑會寫入。
 */
enum CorrectionTypeEnum: string
{
    case PAYOUT = 'payout';

    case END_ROUND = 'endRound';

    case REFUND = 'refund';

    case DEBIT = 'debit';

    case CREDIT = 'credit';

    case SUM_BET = 'sumBet';

    /**
     * 判斷「這是不是同一筆調整的重送」時，除了金額之外還要逐字比對的請求欄位。
     *
     * 只列出該端點真正用來決定「動到哪一局」的欄位，而不是比對整包 payload——
     * 整包比對看似更嚴格，實際上是危險的：RG 哪天在請求裡多加一個會變動的欄位
     * （trace id、時間戳之類，生產上的 payout 請求就帶了每筆都不同的 payout_time），
     * 比對就永遠不會相等，守衛靜默失效、重複入帳，而且不留任何訊號。
     * 只比對關鍵欄位則相反：失效方向是誤擋（玩家少拿、可補、有 warning 可查）。
     *
     * `payout` / `endRound` 不走重送守衛（它們只收 status = BET 的注單，靠狀態擋重送），
     * 回 null 讓守衛在被誤用時直接放行而不是比對空集合、把每一筆都當成重送。
     *
     * @return string[]|null
     */
    public function identityKeys(): ?array
    {
        return match ($this) {
            self::REFUND => ['order_sn'],
            self::DEBIT, self::CREDIT, self::SUM_BET => ['bet_no', 'order_sn'],
            self::PAYOUT, self::END_ROUND => null,
        };
    }
}
