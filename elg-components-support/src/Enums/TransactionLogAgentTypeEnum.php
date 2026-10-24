<?php

namespace target\support\Enums;

class TransactionLogAgentTypeEnum
{
    const DEPOSIT = 1; // 開分
    const WITHDRAWAL = 2; // 洗分
    const AGENT = 3; // 全民代理
    const AGENT_COMMISSION = 4; // 代理傭金派發
    const WITHDRAWAL_REFUND = 5; // 洗分退還
    const MAIN_WALLET_TO_AGENT_WALLET = 6; // 主錢包轉入佔成錢包
    const MAIN_WALLET_TO_AGENT_WALLET_REFUND = 7; // 主錢包轉入佔成錢包退還
    const AGENT_WALLET_TO_MAIN_WALLET = 8; // 佔成錢包轉入主錢包
}
