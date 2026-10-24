<?php

declare(strict_types=1);

namespace App\Vendors\SpadegamingSW\Enums;

enum TransferTypeEnum: int
{
    case PLACE_BET = 1;
    case CANCEL = 2;
    case PAYOUT = 4;
    case PAYOUT_SPECIAL_GAME = 5;
    case BONUS = 7;
}
