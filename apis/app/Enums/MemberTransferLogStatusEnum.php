<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * member_transfer_logs.status。
 *
 * 注意：migration 欄位註解寫「0:失敗, 1:成功」，但**與線上實際慣例不符**——舊系統
 * (API_Agent) 寫入成功值為 1000，SR07T 轉帳明細表亦以 status=1000 判定成功。
 * 曾因採信該註解將成功值定為 1，導致 2026-08-12 起經本專案轉帳的紀錄全被報表判為
 * 失敗（工單 4166）。成功值一律採 1000，與 {@see Transaction\TransactionStatusEnum} 一致。
 */
enum MemberTransferLogStatusEnum: int
{
    /** 失敗 */
    case FAILED = 0;
    /** 成功（對齊舊系統與 SR07T 報表判定值，不可改回 1） */
    case SUCCESS = 1000;
}
