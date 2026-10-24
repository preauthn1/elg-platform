<?php

declare(strict_types=1);

namespace App\Enums\Game;

enum GameTypeEnum: int
{
    case LIVE_CASINO = 1;
    case SLOTS = 2;
    case POKER = 3;
    case LOTTERY = 4;
    case FISHING = 5;
    case COKE_FIGHTING = 6;
    case ARCADE = 15;
    case HORSE_RACING = 16;
    case SPORTS = 18;
    case E_SPORTS = 19;
    case GD = 20; // 豪龍
    case RUN_LIGHT = 21;
    case REWARD = 91;
    case EVENT_AND_OTHERS = 98;
    case MULTIPLAYER = 99;
    case TABLE = 100;
}
