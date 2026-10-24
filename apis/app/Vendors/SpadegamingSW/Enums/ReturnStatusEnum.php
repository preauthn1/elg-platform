<?php

declare(strict_types=1);

namespace App\Vendors\SpadegamingSW\Enums;

enum ReturnStatusEnum: int
{
    case SUCCESS = 0;
    case SYSTEM_ERROR = 1;
    case INVALID_REQUEST = 2;
    case SERVICE_INACCESSIBLE = 3;
    case RELATED_ID_NOT_FOUND = 109;
    case MERCHANT_NOT_FOUND = 10113;
    case MERCHANT_SUSPEND = 10116;
    case ACCT_NOT_FOUND = 50100;
    case ACCT_INACTIVE = 50101;
    case ACCT_SUSPEND = 50103;
    case INSUFFICIENT_BALANCE = 50110;
    case AMOUNT_INVALID = 50113;
}
