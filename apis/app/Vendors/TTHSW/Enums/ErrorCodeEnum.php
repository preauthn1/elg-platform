<?php

declare(strict_types=1);

namespace App\Vendors\TTHSW\Enums;

enum ErrorCodeEnum: int
{
    case ERROR = -1;
    case SUCCESS = 0;
    case PLAYER_NOT_FOUND = 201;
    case PLAYER_INACTIVE = 202;
    case PLAYER_SESSION_EXPIRED = 203;
    case INSUFFICIENT_FUND = 301;
    case STAKE_OUT_OF_RANGE = 302;
    case STAKE_EXCEED_DAILY_LIMIT = 303;
    case GAME_ROUND_NOT_EXIST = 401;
    case GAME_ROUND_DUPLICATED = 402;
    case GAME_ROUND_REFUNDED = 403;
    case GAME_ROUND_TRANSACTION_NOT_EXIST = 501;
    case GAME_ROUND_TRANSACTION_DUPLICATED = 502;
    case GAME_ROUND_TRANSACTION_REFUNDED = 503;
    case RESERVE_TRANSACTION_NOT_EXIST = 601;
    case RESERVE_TRANSACTION_DUPLICATED = 602;
    case RESERVE_TRANSACTION_REFUNDED = 603;
    case RESERVE_TRANSACTION_RELEASED = 604;
    case INSUFFICIENT_RESERVE = 605;
    case REFUND_ORIGINAL_TRANSACTION_NOT_EXIST = 701;
    case BAD_PARAMETER = 905;
    case INVALID_SIGN = 907;

    public function getMessage(): string
    {
        return match ($this) {
            self::ERROR => 'Error',
            self::SUCCESS => 'Success',
            self::PLAYER_NOT_FOUND => 'Player not found',
            self::PLAYER_INACTIVE => 'Player inactive',
            self::PLAYER_SESSION_EXPIRED => 'Player session expired',
            self::INSUFFICIENT_FUND => 'Insufficient fund',
            self::STAKE_OUT_OF_RANGE => 'Stake out of range',
            self::STAKE_EXCEED_DAILY_LIMIT => 'Stake exceed daily limit',
            self::GAME_ROUND_NOT_EXIST => 'Game round not exist',
            self::GAME_ROUND_DUPLICATED => 'Game round duplicated',
            self::GAME_ROUND_REFUNDED => 'Game round refunded',
            self::GAME_ROUND_TRANSACTION_NOT_EXIST => 'Game round transaction not exist',
            self::GAME_ROUND_TRANSACTION_DUPLICATED => 'Game round transaction duplicated',
            self::GAME_ROUND_TRANSACTION_REFUNDED => 'Game round transaction refunded',
            self::RESERVE_TRANSACTION_NOT_EXIST => 'Reserve transaction not exist',
            self::RESERVE_TRANSACTION_DUPLICATED => 'Reserve transaction duplicated',
            self::RESERVE_TRANSACTION_REFUNDED => 'Reserve transaction refunded',
            self::RESERVE_TRANSACTION_RELEASED => 'Reserve transaction released',
            self::INSUFFICIENT_RESERVE => 'Insufficient reserve',
            self::REFUND_ORIGINAL_TRANSACTION_NOT_EXIST => 'Refund original transaction not exist',
            self::BAD_PARAMETER => 'Bad parameter',
            self::INVALID_SIGN => 'Invalid sign',
        };
    }
}
