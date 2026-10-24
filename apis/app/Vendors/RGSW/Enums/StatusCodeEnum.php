<?php

declare(strict_types=1);

namespace App\Vendors\RGSW\Enums;

enum StatusCodeEnum: int
{
    case SUCCESS = 0;
    case MISSING_REQUIRED_PARAMETER = 42291;
    case ACCOUNT_ERROR = 42293;
    case INSUFFICIENT_BALANCE = 42295;
    case RECORD_NOT_FOUND = 42296;
    case FLOW_NUMBER_IS_EXIST = 42297;
    case TRANSACTION_FAILED = 42298;
}
