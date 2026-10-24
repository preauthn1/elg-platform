<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AntzPay;

use App\Services\Payment\Providers\Kira\Withdrawal as KiraWithdrawal;

/**
 * AntzPay 代付＝Kira 代付（Gateway v1 同源協定），沿用 Kira 實作；
 * getDriverName() 依目錄名解析，log 與錯誤訊息自動標示 AntzPay。
 *
 * @knowledge rules/payout-submit-and-status
 */
class Withdrawal extends KiraWithdrawal
{
    /**
     * 業務碼「明確拒絕」白名單：確定請求未建單，可安全判 -2、訂單轉三方失敗(29)可重新發起。
     * 0＝業務拒絕（文檔未載；比照 Kira 實測，兩家為同源協定）、
     * 401＝認證失敗（api.md §11，請求未受理）。
     * 400 與其他碼沒有 AntzPay 的依據證明不會出款，一律視為結果未知（訂單停留 21）。
     *
     * 不沿用 Kira 的「業務碼非 200 一律拒絕」：那只有 KIRAPAY 的書面確認（target/tickets#4393），
     * AntzPay 未取得同等依據。
     */
    private const REJECT_CODES = [0, 401];

    protected string $orderPrefix = 'ANTZ';

    protected function isRejectedBusinessCode(int $code): bool
    {
        return in_array($code, self::REJECT_CODES, true);
    }
}
