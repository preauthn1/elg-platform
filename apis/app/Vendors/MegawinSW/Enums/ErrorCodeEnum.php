<?php

declare(strict_types=1);

namespace App\Vendors\MegawinSW\Enums;

class ErrorCodeEnum
{
    const SUCCESS = '0000';

    const PARAM_ERROR = 2000;

    const DOMAIN_ERROR = 2001;

    const USER_NOT_FOUND = 2002;

    const SITE_ID_NOT_FOUND = 2003;

    const MERCHANT_ID_NOT_FOUND = 2004;

    const FIRM_ID_NOT_FOUND = 2005;

    const USER_DISABLED = 2006;

    const LOGO_CHANGE_DISABLED = 2007;

    const DECRYPT_ERROR = 2008;

    const NO_MORE_DATA = 2015;

    const TRANSFER_MAINTENANCE = 2100;

    const TRANSFER_PROCESSING = 2101;

    const TRANSFER_FAILED = 2102;

    const ORDER_EXISTS = 2103;

    const ORDER_NOT_FOUND = 2104;

    const INSUFFICIENT_BALANCE = 2105;

    const EXCEED_LIMIT = 2106;

    const SYSTEM_BUSY = 9999;
}
