<?php

declare(strict_types=1);

namespace App\Services\Payment\Enums;

/**
 * {@see \App\Models\OrderRequestLog} 的判定結果，只有兩種形狀：`ok` 與 `failed:<原因>`。
 *
 * 這欄記的是**我方對這次往返的判定**，不是三方回應的轉述——三方回了什麼原封不動在 `response`。
 * 之所以非存不可：回調被防線擋下時，我方回給三方的 body 全都是 `FAIL`，
 * 從 `response` 完全分不出是哪一道擋的。
 *
 * 🔴 禁止在呼叫端塞自由文字。三方自己的錯誤類型（各家不同、無法枚舉）走
 * {@see self::providerFailure()} 統一加前綴，取 `type`／`code` 這類欄位，**不要取 message**
 * ——message 是會變的自由文字，而且原文已經在 `response` 裡。
 */
enum OrderRequestResultEnum: string
{
    case OK = 'ok';

    /** 防線 1：來源 IP 不在 cash_flows.whitelist */
    case IP_NOT_WHITELISTED = 'failed:ip_not_whitelisted';

    /** 驗簽不過（有驗簽的金流商才會出現，例 TngPin） */
    case INVALID_SIGNATURE = 'failed:invalid_signature';

    /** 我方憑證沒配好、無法驗簽；對外沿用與驗簽失敗相同的回應，不洩露真實原因 */
    case CREDENTIALS_NOT_CONFIGURED = 'failed:credentials_not_configured';

    /** 防線 2：訂單屬於別的金流商戶 */
    case ANOTHER_CHANNEL = 'failed:another_channel';

    case ORDER_NOT_FOUND = 'failed:order_not_found';

    /** 防線 3：查單打不通 */
    case QUERY_FAILED = 'failed:query_failed';

    /** 防線 3：查回來的商戶訂單號不是本單 */
    case QUERY_ANOTHER_ORDER = 'failed:query_another_order';

    /** 防線 3：三方尚未終態（PENDING／PROCESSING），訂單不動、等三方重送。不是真的失敗 */
    case NOT_FINALIZED = 'failed:not_finalized';

    /** 防線 3：查單回報 SUCCESS 卻沒帶金額，沒有可信的上分依據 */
    case NO_AMOUNT = 'failed:no_amount';

    /** 訂單已處理過（冪等保護） */
    case ALREADY_PROCESSED = 'failed:already_processed';

    /** 建單響應缺 data.url */
    case NO_URL = 'failed:no_url';

    /** 三方同步回應判這筆失敗（未受理、或回終態失敗狀態），細節看 response 原文 */
    case PROVIDER_REJECTED = 'failed:provider_rejected';

    /** 連不上、逾時、或其他未預期例外 */
    case EXCEPTION = 'failed:exception';

    /**
     * 三方回報的錯誤類型（例 Ap8Pay 的 `NotFound`、`APIValidationError`）加上統一前綴。
     */
    public static function providerFailure(string $type): string
    {
        return 'failed:' . $type;
    }
}
