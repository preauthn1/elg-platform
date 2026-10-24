<?php

declare(strict_types=1);

namespace App\Vendors\SexySlotCq9SW\Enums;

/**
 * CQ9 Wallet API 錯誤碼：HTTP 一律 200，錯誤碼放在 status.code
 *
 * @see docs/integrations/vendors/SexySlotCq9SW/api.md「Wallet API 錯誤碼」
 */
enum StatusCodeEnum: int
{
    case SUCCESS = 0;
    case ACTION_ERROR = 1002;
    case PARAMETER_ERROR = 1003;
    case TIME_FORMAT_ERROR = 1004;
    case INSUFFICIENT_BALANCE = 1005;
    case PLAYER_NOT_FOUND = 1006;
    case RECORD_NOT_FOUND = 1014;
    case SERVER_ERROR = 1100;
}
