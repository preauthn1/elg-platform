<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 漂移比對的監測對象，也就是 drift_baselines.subject_key 的合法值。
 *
 * 值是穩定的邏輯鍵，**不得改用 class 名稱（FQCN）**：class 會改名搬家，FQCN 入庫會讓
 * 歷史列一夕失聯。值長度上限 50，對齊 drift_baselines.subject_key 的欄位定義。
 *
 * 新增監測對象＝新增一個 case，不必改資料表。
 */
enum DriftSubjectEnum: string
{
    /** FPay 代付銀行清單（三方端點 POST /wallet/withdraw_bank_list）；scope_key ＝代付設定 id */
    case FPAY_WITHDRAW_BANK_LIST = 'fpay_withdraw_bank_list';

    /**
     * 代付對照表 cash_flow_banks 的銀行碼與 banks.code 脫節的清單（`bank:audit-cfb-code`）；
     * 一站一份，scope_key 固定為 `all`
     */
    case CFB_CODE_DRIFT = 'cfb_code_drift';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
