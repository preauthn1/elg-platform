<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\ZPay;

use App\Exceptions\ApiException;

enum ZPayStatusCodeEnum: int
{
    case ON = 1;
    case VOIDED = 2;
    case FAILED = 3;
    case REJECTED = 4;
    case PROCESSING = 5;
    case PENDING = 6;
    case FORFEIT = 7;
    case REFUNDED = 8;

    /**
     * 依狀態碼取得 Enum
     *
     * @throws ApiException
     */
    public static function fromValue(int $status): self
    {
        $statusEnum = self::tryFrom($status);

        return $statusEnum ?? throw new ApiException(sprintf('ZPay unknown status code: %s', (string) $status));
    }

    /**
     * 是否成功
     */
    public function isSuccess(): bool
    {
        return $this === self::ON;
    }

    /**
     * 是否失敗
     */
    public function isFailed(): bool
    {
        return match ($this) {
            self::VOIDED, self::FAILED, self::REJECTED, self::FORFEIT, self::REFUNDED => true,
            default => false,
        };
    }

    public function isPending(): bool
    {
        return match ($this) {
            self::PROCESSING, self::PENDING => true,
            default => false,
        };
    }
}
