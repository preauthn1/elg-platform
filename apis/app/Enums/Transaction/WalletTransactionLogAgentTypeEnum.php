<?php

declare(strict_types=1);

namespace App\Enums\Transaction;

/**
 * wallet_transaction_log_agents.type（代理佔成錢包異動類型）。
 */
enum WalletTransactionLogAgentTypeEnum: int
{
    case TRANSFER_IN = 1;           // 轉入
    case TRANSFER_OUT = 2;          // 轉出
    case PROFIT_SHARE_SETTLE = 3;   // 佔成結算
}
