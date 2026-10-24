<?php

declare(strict_types=1);

namespace App\Enums\Transaction;

/**
 * transaction_log_agents.status：帳變結果（migration 註解「狀態0:失敗，1:成功」）。
 *
 * 與會員端的 TransactionStatusEnum（成功＝1000）值域不同，不可混用。
 */
enum TransactionLogAgentStatusEnum: int
{
    /** 失敗 */
    case FAILED = 0;

    /** 成功 */
    case SUCCESS = 1;
}
