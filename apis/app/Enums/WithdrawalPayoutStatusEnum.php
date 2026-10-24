<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 代付記錄狀態（WithdrawalPayoutRecord::is_success_payout）。
 *
 * 負值依生命週期順序排列：magnitude 越大代表失敗發生越晚。
 * 判斷式語意：< 0 失敗、= 0 pending、= 1 成功。
 */
enum WithdrawalPayoutStatusEnum: int
{
    /**
     * 請求三方失敗：timeout / 連線例外（未取得回應，最早）
     */
    case REQUEST_FAILED = -1;
    /**
     * 三方同步回應拒絕
     */
    case THIRD_PARTY_REJECTED = -2;
    /**
     * 三方回調確認失敗（最晚）
     */
    case CALLBACK_FAILED = -3;
    /**
     * 已建立，等待結果（初始狀態）
     */
    case PENDING = 0;
    /**
     * 回調確認撥款成功
     */
    case SUCCESS = 1;
}
