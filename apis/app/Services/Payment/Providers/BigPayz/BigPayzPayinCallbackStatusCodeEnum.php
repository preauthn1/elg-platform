<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

/**
 * BigPayz Payin callback status (POST to HTTPPostURL).
 * Doc: 1 = Success/Accepted, 2 = Rejected, 3 = Approved.
 */
enum BigPayzPayinCallbackStatusCodeEnum: int
{
    case SUCCESS = 1;
    case REJECTED = 2;
    case APPROVED = 3;

    public function isSuccess(): bool
    {
        return $this === self::SUCCESS || $this === self::APPROVED;
    }
}
