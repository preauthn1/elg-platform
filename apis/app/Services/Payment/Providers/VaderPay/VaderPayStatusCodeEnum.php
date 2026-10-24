<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VaderPay;

use App\Exceptions\ApiException;

enum VaderPayStatusCodeEnum: int
{
    case PENDING = 0;
    case SUCCESS = 1;
    case REJECTED = 2;
    case APPROVED = 3;

    /**
     * 依狀態碼取得 Enum
     *
     * @throws ApiException
     */
    public static function fromValue(int $status): self
    {
        $statusEnum = self::tryFrom($status);

        return $statusEnum ?? throw new ApiException(sprintf('VaderPay unknown status code: %s', (string) $status));
    }

    public function isPayinSuccess(): bool
    {
        return $this === self::SUCCESS;
    }

    public function isPayinFailed(): bool
    {
        return $this === self::REJECTED;
    }

    public function isPayinPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isPayoutSuccess(): bool
    {
        return match ($this) {
            self::SUCCESS, self::APPROVED => true,
            default => false,
        };
    }

    public function isPayoutFailed(): bool
    {
        return $this === self::REJECTED;
    }

    public function isPayoutPending(): bool
    {
        return $this === self::PENDING;
    }
}
