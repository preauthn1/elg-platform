<?php

declare(strict_types=1);

namespace App\Vendors\MegaH5SW;

enum OperatorStatusEnum: int
{
    /** 成功 */
    case OK = 200;

    /** 无效的玩家/密码 */
    case INVALID_PLAYER = 900404;

    /** 运营商ID错误 */
    case INVALID_OPERATOR_ID = 900405;

    /** 传入请求信息不完整 */
    case INCOMING_REQUEST_INFO_INCOMPLETE = 900406;

    /** 无效的签名 */
    case INVALID_SIGNATURE = 900407;

    /** 重复交易 */
    case DUPLICATE_TRANSACTION = 900409;

    /** 找不到交易 */
    case BET_TRANSACTION_NOT_FOUND = 900415;

    /** 玩家已停用 */
    case PLAYER_INACTIVE = 900416;

    /** CancelBetNSettle 被拒絕（廠商將持續重送直到成功） */
    case CANCEL_BET_N_SETTLE_REJECTED = 900417;

    /** 余额不足 */
    case INSUFFICIENT_BALANCE = 900605;

    /** 内部服务器错误 */
    case INTERNAL_SERVER_ERROR = 900500;

    /** 最大派彩已达到 */
    case MAX_PAYOUT_REACHED = 800401;
}
