<?php

declare(strict_types=1);

namespace App\Enums\Lobby;

enum VendorStatusEnum: int
{
    case OPEN = 1;

    case COMING_SOON = 2;

    case MAINTENANCE = 3;

    case FORBIDDEN = 4;

    case EVENT_V2_FORBIDDEN = 5;
}
