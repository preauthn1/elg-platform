<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988\Enums;

enum ErrorCodeEnum: string
{
    /** 系統繁忙 */
    case SYSTEM_BUSY = '9998';

    /** 失敗 */
    case FAIL = '9999';

    /** 成功 */
    case SUCCESS = '0000';

    /** 平台不存在 */
    case NO_PLATFORM = '11';

    /** 無效的用戶ID */
    case INVALID_USER_ID = '1000';

    /** 帳戶已存在 */
    case ACCOUNT_EXISTS = '1001';

    /** 帳戶不存在 */
    case ACCOUNT_NOT_EXISTS = '1002';

    /** 無效的貨幣 */
    case INVALID_CURRENCY = '1004';

    /** 語言不存在 */
    case LANGUAGE_NOT_EXISTS = '1005';

    /** PT設定為空 */
    case PT_SETTING_EMPTY = '1006';

    /** 無效的PT設定 */
    case INVALID_PT_SETTING = '1007';

    /** 無效的令牌 */
    case INVALID_TOKEN = [REDACTED]';

    /** 無效的時區 */
    case INVALID_TIMEZONE = '1009';

    /** 無效的金額 */
    case INVALID_AMOUNT = '1010';

    /** 無效的TX代碼 */
    case INVALID_TX_CODE = '1011';

    /** 有待處理的轉移 */
    case HAS_PENDING_TRANSFER = '1012';

    /** 帳戶被鎖定 */
    case ACCOUNT_LOCKED = '1013';

    /** 帳戶被暫停 */
    case ACCOUNT_SUSPENDED = '1014';

    /** TX代碼已操作 */
    case TX_CODE_ALREADY_OPERATED = '1016';

    /** TX代碼不存在 */
    case TX_CODE_NOT_EXISTS = '1017';

    /** 餘額不足 */
    case NOT_ENOUGH_BALANCE = '1018';

    /** 沒有資料 */
    case NO_DATA = '1019';

    /** 無效的日期時間格式 */
    case INVALID_DATETIME_FORMAT = '1024';

    /** 無效的交易狀態 */
    case INVALID_TRANSACTION_STATUS = '1025';

    /** 無效的投注限制 */
    case INVALID_BET_LIMIT = '1026';

    /** 無效的憑證 */
    case INVALID_CERTIFICATE = '1027';

    /** 無法繼續 */
    case UNABLE_TO_PROCEED = '1028';

    /** 無效的IP */
    case INVALID_IP = '1029';

    /** 無效的設備 */
    case INVALID_DEVICE = '1030';

    /** 系統維護 */
    case SYSTEM_MAINTENANCE = '1031';

    /** 重複登入 */
    case DUPLICATE_LOGIN = '1032';

    /** 無效的遊戲 */
    case INVALID_GAME = '1033';

    /** 無效的時間 */
    case INVALID_TIME = '1034';

    /** 無效的代理ID */
    case INVALID_AGENT_ID = '1035';

    /** 無效的參數 */
    case INVALID_PARAMETERS = '1036';

    /** 無效的客戶設定 */
    case INVALID_CUSTOMER_SETTING = '1037';

    /** 重複交易 */
    case DUPLICATE_TRANSACTION = '1038';

    /** 交易不存在 */
    case TRANSACTION_NOT_FOUND = '1039';

    /** 請求超時 */
    case REQUEST_TIMEOUT = '1040';

    /** HTTP狀態錯誤 */
    case HTTP_STATUS_ERROR = '1041';

    /** HTTP回應為空 */
    case HTTP_RESPONSE_EMPTY = '1042';

    /** 投注取消 */
    case BET_CANCELED = '1043';

    /** 無效的投注 */
    case INVALID_BET = '1044';

    /** 添加聲明失敗 */
    case ADD_STATEMENT_FAILED = '1045';

    /** 轉移失敗 */
    case TRANSFER_FAILED = '1046';

    /** 遊戲維護 */
    case GAME_MAINTENANCE = '1047';

    /** 無效的用戶名 */
    case INVALID_USERNAME = '1050';

    /** 無效的平台 */
    case INVALID_PLATFORM = '1054';

    /** 參數不存在 */
    case PARAMETER_NOT_FOUND = '1056';
}
