<?php

declare(strict_types=1);

namespace App\Vendors\WESW;

enum WeswBetRecordStatusEnum: int
{
    case DEBIT = 1;
    case CREDIT = 2;
    case ROLLBACK = 3;
    case RESETTLEMENT = 4;
}
