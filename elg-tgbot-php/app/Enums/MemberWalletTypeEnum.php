<?php

namespace App\Enums;

enum MemberWalletTypeEnum: int
{
    case MAIN = 1;
    case GAME = 2;
    case ROLLING_CHIP = 1002;
}
