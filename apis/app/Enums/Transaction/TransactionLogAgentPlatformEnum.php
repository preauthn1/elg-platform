<?php

declare(strict_types=1);

namespace App\Enums\Transaction;

/**
 * transaction_log_agents.platform：帳變的操作平台。
 *
 * 1/2 為舊系統既有值（migration 註解「操作平台1:客服，2:代理後台」，欄位
 * default 1）；SYSTEM 為排程／系統流程自行產生、無人工操作者的帳變（GGR
 * 分潤發放，target/tickets#4121 批次七）。
 */
enum TransactionLogAgentPlatformEnum: int
{
    /** 系統排程（無人工操作者） */
    case SYSTEM = 0;

    /** 客服後台 */
    case CUSTOMER_SERVICE = 1;

    /** 代理後台 */
    case AGENT_BACKEND = 2;
}
