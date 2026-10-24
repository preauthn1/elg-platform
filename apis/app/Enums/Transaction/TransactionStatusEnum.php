<?php

declare(strict_types=1);

namespace App\Enums\Transaction;

enum TransactionStatusEnum: int
{
    case STATUS_SUCCESS = 1000;
    case STATUS_FAIL = 0; // 遊戲商轉入或轉出成功前的狀態或失敗
    case STATUS_REVIEW = 9;  // 未審核前 狀態先保持在9
    case STATUS_REJECT = -9;  // 拒絕
    case UNKNOWN_TRANSFER_FAILED = 8888; // 不知名轉帳失敗狀態, 如連線逾時
    case STATUS_GAMING = 999;  // 遊戲中 狀態先保持在999 (VW only)
    case STATUS_TO_VENDOR_FAILED_REFUND = 1111; // 失敗已退款
    case STATUS_TO_VENDOR_FAILED_RECYCLE = 1112; // 失敗已回收
}
