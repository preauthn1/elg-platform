<?php

declare(strict_types=1);

namespace App\Vendors\WESW;

enum WeswErrorCodeEnum: int
{
    case PROVIDER_OK = 200;
    case PROVIDER_BAD_REQUEST = 400;
    case CERTIFICATE_ERROR = 401;
    case INSUFFICIENT_BALANCE = 402;
    case USER_NOT_FOUND = 404;
    case REPEAT_TRANSACTION = 409;
    case BET_RECORD_NOT_FOUND = 410;
    case INTERNAL_SERVER_ERROR = 500;
}
