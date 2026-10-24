<?php

declare(strict_types=1);

namespace App\Vendors\TTHSW\Enums;

enum TransactionTypeEnum: int
{
    case BET = 1;
    case WIN = 2;
    case RESETTLE = 3;
    case CANCEL_ROUND = 4;
    case END_ROUND = 5;
    case FORCE_END_ROUND = 6;
    case GIFT = 7;
    case ADJUST = 8;
    case CANCEL_TRANSACTION = 9;
    case RESERVE = 10;
    case RELEASE_RESERVE = 11;
    case RESERVE_BET = 12;
    case GAME_RESULT = 13;
    case PROMO_WIN = 14;
    case DEBIT = 15;
    case CREDIT = 16;
}
