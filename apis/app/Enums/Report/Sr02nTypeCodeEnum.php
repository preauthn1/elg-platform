<?php

declare(strict_types=1);

namespace App\Enums\Report;

/**
 * SR02 CASE 產出的字串型「操作」代碼（前端以 `transaction_type.<code>` 翻譯）。
 *
 * 其餘列的操作沿用數值型別（transaction_logs.type 值域）。
 */
enum Sr02nTypeCodeEnum: string
{
    case THIRD_PARTY_DEPOSIT = 'third_party_deposit';  // 三方充值
    case CS_PAYOUT = 'cs_payout';                      // 自助洗分客服付款（客服提現）
    case THIRD_PARTY_PAYOUT = 'third_party_payout';    // 三方代付出款
    case TRANSFER_TO_AGENT = 'transfer_to_agent';      // 代理額度開分
    case DEDUCT_FROM_AGENT = 'deduct_from_agent';      // 代理額度洗分
}
