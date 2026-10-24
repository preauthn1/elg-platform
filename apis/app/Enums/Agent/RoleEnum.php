<?php

declare(strict_types=1);

namespace App\Enums\Agent;

enum RoleEnum: int
{
    case ADMIN = 1;
    case SPECIAL_ACCOUNT = 2;
    case GENERAL_AGENT = 11;
    case CUSTOMER_SERVICE = 12;
    case FINANCE = 13;
    case AGENT = 21;
}
