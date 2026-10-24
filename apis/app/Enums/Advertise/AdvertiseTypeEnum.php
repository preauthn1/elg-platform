<?php

declare(strict_types=1);

namespace App\Enums\Advertise;

enum AdvertiseTypeEnum: int
{
    case NOT_LINK = 0;
    case GAME_TYPE = 1;
    case GAME = 2;
    case VENDOR = 3;
    case EXTERNAL_URL = 4;
    case INTERNAL_URL = 5;
}
