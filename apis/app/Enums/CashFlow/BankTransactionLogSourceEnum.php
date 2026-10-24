<?php

declare(strict_types=1);

namespace App\Enums\CashFlow;

/**
 * bank_transaction_logs.source（銀行帳戶異動來源）。
 */
enum BankTransactionLogSourceEnum: int
{
    case AGENT_DEPOSIT = 1;        // 代理開分
    case AGENT_WITHDRAWAL = 2;     // 代理洗分
    case SELF_DEPOSIT = 3;         // 自助上分（order_id ＝ orders.id）
    case SELF_WITHDRAWAL = 4;      // 自助洗分（order_id ＝ withdrawal_orders.id）
}
