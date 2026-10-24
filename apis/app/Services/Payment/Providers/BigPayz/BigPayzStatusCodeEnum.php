<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

use App\Exceptions\ApiException;

/**
 * BigPayz Payin V2 status (Start response / transinfo).
 * Doc: 0=initiated, 1=accepted, -1=rejected, -2=error, -3=pending
 */
enum BigPayzStatusCodeEnum: int
{
    case INITIATED = 0;
    case ACCEPTED = 1;
    case REJECTED = -1;
    case ERROR = -2;
    case PENDING = -3;

    /**
     * 依狀態碼取得 Enum
     *
     * @throws ApiException
     */
    public static function fromValue(int $status): self
    {
        $statusEnum = self::tryFrom($status);

        return $statusEnum ?? throw new ApiException(sprintf('BigPayz unknown status code: %s', (string) $status));
    }

    /**
     * 從 API 回傳的 status 解析為 Enum，缺失或無效時回傳 ERROR。
     */
    public static function fromResponse(mixed $status): self
    {
        $value = $status !== null && $status !== '' ? (int) $status : null;
        $enum = $value !== null ? self::tryFrom($value) : null;

        return $enum ?? self::ERROR;
    }

    public function isInitiated(): bool
    {
        return $this === self::INITIATED;
    }

    public function isSuccess(): bool
    {
        return $this === self::ACCEPTED;
    }

    public function isFailed(): bool
    {
        return match ($this) {
            self::REJECTED, self::ERROR => true,
            default => false,
        };
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }
}
