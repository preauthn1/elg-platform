<?php

declare(strict_types=1);

namespace App\Vendors\PegasusSW;

enum OperatorStatusEnum: int
{
    /** 成功 */
    case OK = 0;
    /** SessionToken、身分验证不合法，用户身分签过期 */
    case INVALID_SIGNATURE = 401002;
    /** 钱包余额不足 */
    case INSUFFICIENT_BALANCE = 403203;
    /** 参数输入不合法、格式不合法 */
    case INVALID_PARAMETER = 403001;
    /** 注单单号重覆 */
    case DUPLICATE_TRANSACTION = 500029;
    /** 注單状态不合法 (e.g. 重复结算) */
    case INVALID_STATUS = 403601;
    /** 注單狀態已是取消狀態 (cancel) */
    case BET_ALREADY_CANCELLED = 403612;
    /** 查无注单 */
    case BET_NOT_FOUND = 404016;
    /** 系统错误，系统异常，其他错误 */
    case INTERNAL_SERVER_ERROR = 500001;
}
