<?php

declare(strict_types=1);

namespace App\Enums\Vendor;

enum VendorTransferResultEnum
{
    case SUCCESS;
    case FAILED;
    case TIMEOUT;
}
