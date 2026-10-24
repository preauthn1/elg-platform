<?php

declare(strict_types=1);

namespace App\Enums\Transaction;

/**
 * transaction_log_agents.type：代理帳變類型。
 *
 * 1-10 移植自舊系統 API_Agent 的 TransactionLogAgentTypeEnum（值與語意不可改，
 * 舊代理後台報表按這些值篩選顯示）；新型別自 11 起接續。
 */
enum TransactionLogAgentTypeEnum: int
{
    /** 開分 */
    case DEPOSIT = 1;

    /** 洗分 */
    case WITHDRAWAL = 2;

    /** 全民代理（代理模式獎勵三層返水） */
    case AGENT = 3;

    /** 代理傭金派發 */
    case AGENT_COMMISSION = 4;

    /** 分桶獎勵 */
    case SHAREHOLDER_REWARD = 5;

    /** 主錢包轉入佔成錢包 */
    case MAIN_WALLET_TO_AGENT_WALLET = 6;

    /** 主錢包轉入佔成錢包退還 */
    case MAIN_WALLET_TO_AGENT_WALLET_REFUND = 7;

    /** 佔成錢包轉入主錢包 */
    case AGENT_WALLET_TO_MAIN_WALLET = 8;

    /** 佔成獎勵 */
    case COMMISSION_REWARD = 9;

    /** 洗分退還 */
    case WITHDRAWAL_REFUND = 10;

    /** GGR 分潤發放（推廣代理結算 ggr_settlements.payable_amount 入帳，target/tickets#4121 批次七） */
    case GGR_PROFIT_PAYOUT = 11;
}
