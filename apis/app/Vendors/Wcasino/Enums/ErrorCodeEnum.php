<?php

declare(strict_types=1);

namespace App\Vendors\Wcasino\Enums;

enum ErrorCodeEnum: int
{
    // 所有接口
    case SUCCESS = 0;                   // 成功
    case UNKNOWN_ERROR = -1000;         // 系统未知异常，请联系
    case INTERNAL_ERROR = -1001;        // 系统内部异常，请联系
    case PARAM_ERROR = -1002;           // 参数错误，请检查所传参数
    case APPID_ERROR = -1005;           // Appid 错误
    case APPID_NOT_EXIST = -1006;       // Appid 不存在，请联系
    case SIGN_ERROR = -1007;            // 签名错误，请检查签名
    case EXPIRED = -1008;               // 过期的
    case TOO_FREQUENT = -1009;          // 请求太频繁
    case IP_NOT_ALLOW = -1010;          // 非法 IP
    case ILLEGAL_DOMAIN = -1041;        // 非法域名

    // 用户接口
    case NOT_REGISTERED = -1011;        // 未注册，请先注册
    case USER_NOT_EXIST = -1012;        // 用户不存在
    case USER_EXIST = -1013;            // 用户已经存在
    case USER_FORBIDDEN = -1014;        // 用户被禁止
    case PASSWORD_ERROR = -1015;        // 用户密码错误
    case LIMIT_NOT_EXIST = -1022;       // 限额不存在

    // 钱包接口
    case INSUFFICIENT_BALANCE = -1020;   // 用户余额不足
    case AMOUNT_NOT_ZERO = -1021;       // 存取款金额为非零数
    case DEDUCT_INSUFFICIENT = -1030;    // 扣款金额不足

    // 验证码
    case CAPTCHA_ERROR = -1040;         // 验证码错误

    // 游戏相关错误
    case BALANCE_INSUFFICIENT = -3002;   // 馀额不足
    case BET_EXCEED_LIMIT = -3003;      // 下注金额已超过您的下注设定
    case ACCOUNT_FORBIDDEN = -3004;      // 您的帐户无法进行游戏
    case WIN_LIMIT_REACHED = -3006;     // 达到赢取金额限度
    case LOSS_LIMIT_REACHED = -3007;    // 达到损失金额限度
    case BET_LIMIT_NOT_SET = -3008;     // 您的下注限度尚未设定
    case BET_LIMIT_CHANGED = -3009;     // 您的下注限度已变更
    case GAME_SUSPENDED = -3014;        // 本游戏暂停服务
    case ERROR_4003 = -3017;            // 发生错误 4003
    case ERROR_4004 = -3018;            // 发生错误 4004
    case ERROR_5003 = -3020;            // 发生错误 5003
}
