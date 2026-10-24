<?php

declare(strict_types=1);

namespace App\Vendors\SexySlotCq9SW\Enums;

/**
 * sexyslotcq9sw_bet_records.type（該 round 最後事件）與
 * sexyslotcq9sw_deal_records.type（該筆 mtcode 自身的事件）共用同一組值。
 *
 * 只涵蓋會實際寫入這兩張表的動作；CheckPlayer/Balance 純查詢不寫記錄，
 * 體彩 Batch 系列（Bets/Refunds/Cancel/Wins/Amends/Amend）依 api.md 標記未實作，不在此列。
 */
enum BetRecordStatusEnum: int
{
    case BET = 1;
    case ENDROUND = 2;
    case ROLLOUT = 3;
    case ROLLIN = 4;
    case REFUND = 5;
    case DEBIT = 6;
    case CREDIT = 7;
    case TAKEALL = 8;
    case PAYOFF = 9;
}
