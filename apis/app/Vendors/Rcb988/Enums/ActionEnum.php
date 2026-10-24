<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988\Enums;

enum ActionEnum: string
{
    case GET_BALANCE = 'getBalance';
    case BET = 'bet';
    case ADJUST_BET = 'adjustBet';
    case SETTLE = 'settle';
    case RESETTLE = 'resettle';
    case CANCEL_BET = 'cancelBet';
    case UNSETTLE = 'unsettle';
    case VOID_BET = 'voidBet';
    case VOID_SETTLE = 'voidSettle';
    case UNVOID_BET = 'unvoidBet';
    case UNVOID_SETTLE = 'unvoidSettle';
}
