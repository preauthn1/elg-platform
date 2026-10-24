<?php

namespace App\Enums\Db;

enum ErrorCodeEnum: int
{
    case SUCCESS = 200;

    // 可預期的錯誤或業務邏輯錯誤
    case INVALID_SIGN = 40001; // 驗證簽名失敗
    case NO_AVAILABLE_WALLET = 40002; // 沒有可用的錢包
    case PARAMETER_FORMAT_ERROR = 40003; // 參數格式錯誤
    case ORDER_NUMBER_REPEAT = 40004; // 訂單號重複
    case INVALID_ADDRESS = 40005; // 錢包地址無效
    case BALANCE_NOT_ENOUGH = 40006; // 代付錢包餘額不足
    case ORDER_NOT_FOUND = 40007; // 訂單不存在
    case MERCHANT_NOT_FOUND = 40008; // 商家不存在
    case CURRENCY_NOT_SUPPORTED = 40009; // 幣別不支援
    case WALLET_DEPLOYMENT_STATUS_ERROR = 40010; // 錢包部屬狀態錯誤

    // 不可預期的錯誤或系統錯誤
    case SYSTEM_ERROR = 500; // 系統錯誤
    case SYSTEM_BUSY = 50001; // 系統繁忙
}
