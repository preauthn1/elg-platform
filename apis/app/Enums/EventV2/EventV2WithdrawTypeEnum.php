<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

/**
 * 活動 V2 領取去向（event_v2_details.withdraw_type，參加時凍結進 event_v2_user_joins.withdraw_type）。
 *
 * 在 AbstractEventV2::join() 參加當下依此分岔：
 * - MAIN_WALLET：既有行為。獎金進活動錢包，withdraw() 領取時實領金額加進會員主錢包，寫 transaction_logs type 71。
 * - SCORE：target/tickets#4231。積分與活動錢包無關：數量由提款限制公式（或 withdraw_config.tiers 階梯）算出，
 *   參加當下經 MemberScoreService::grant() 發成一批、紀錄即 COMPLETE；不進活動錢包、不算三道門、
 *   不動主錢包、不寫 type 71；有效期讀 withdraw_config.expire（小時）。
 *
 * 必須：新增去向只加 case，不改既有 case 的值（DB 預設 1、舊後台不送此欄位皆依賴 MAIN_WALLET = 1）。
 * 必須：分岔一律讀 event_v2_user_joins 上的凍結值，禁止讀 event_v2_details 現值。
 * 必須：積分型不開放的活動類型以 handler_class 判定（SCORE_UNSUPPORTED_HANDLERS），禁止寫死 event_v2.id
 *   ——測試庫、dev、正式的類型 id 不同。
 */
enum EventV2WithdrawTypeEnum: int
{
    case MAIN_WALLET = 1; // 主錢包（預設、既有行為）

    case SCORE = 2; // 積分

    /**
     * 積分型不提供的活動類型（PM 規則，2026-09-23 Max 定案回到 9 種）：
     * 三種陣列型獎勵配置的儲值活動不派發積分，後台建立／更新一律擋下。
     * 只擋寫入端：技術上 join() 對任何類型都能發積分，這裡是產品範圍不是技術限制。
     *
     * @var list<string> event_v2.handler_class
     */
    public const array SCORE_UNSUPPORTED_HANDLERS = [
        'DailyDepositRangeService', // 每日區間儲值
        'WeeklyDepositService', // 每週累積儲值
        'DailyDepositComboService', // 每日儲值組合
        'AngpowService', // 紅包：積分是門票不是獎品，只發主錢包（target/tickets#4234，PM 2026-09-24）
    ];

    public function isScore(): bool
    {
        return $this === self::SCORE;
    }

    /**
     * 此去向可否用在指定活動類型。handler_class 為 null（類型不存在）交給後續「活動類型不存在」處理，這裡不擋。
     */
    public function supportsHandler(?string $handlerClass): bool
    {
        if (!$this->isScore() || $handlerClass === null) {
            return true;
        }

        return !in_array($handlerClass, self::SCORE_UNSUPPORTED_HANDLERS, true);
    }
}
