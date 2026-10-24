<?php

declare(strict_types=1);

namespace App\Enums;

enum WithdrawalOrderStatusEnum: int
{
    /**
     * 訂單建立
     */
    case ORDER_CREATED = 0;
    /**
     * 待審核
     */
    case PENDING_REVIEW = 1;
    /**
     * 失敗
     */
    case FAILED = 2;
    /**
     * 通過
     */
    case APPROVED = 3;
    /**
     * 已轉帳（人工／非三方出款路徑的終態）
     *
     * ⚠️ 代付「成功」不只這一個值：同一站可能與 THIRD_PARTY_SUCCESS(24) 並用，且兩條路徑
     * 都在常態運作（非新舊交替）。查「某個銀行碼有沒有成功送出過」一律看 status 的完整
     * 分布（GROUP BY status），只拿單一值當成功判準會把有效碼誤判為無效。
     */
    case TRANSFERRED = 4;
    /**
     * 退款
     */
    case REFUND = 9;
    /**
     * 代付提交中
     */
    case PAYMENT_SUBMITTED = 21;
    /**
     * 代付處理中
     */
    case PAYMENT_PROCESSING = 22;
    /*
     * 23 已退役，禁止重用：原為「三方查證中」，target/tickets#4137 廢除（結果未明改為停留 21），
     * 六站存量於 2026-09-16 實查歸零後移除本 case。舊系統後台仍保有 23 的處理端點，
     * 歷史訂單的變更紀錄（change_logs）也會出現 23；重用會讓這些舊紀錄被誤讀成新狀態。
     */
    /**
     * 三方成功（與 TRANSFERRED(4) 可能並用，判「成功」不可只看其中一個，說明見該 case）
     */
    case THIRD_PARTY_SUCCESS = 24;
    /**
     * 三方失敗
     */
    case THIRD_PARTY_FAILED = 29;
}
