<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BigPayz;

/**
 * BigPayz common error_code meanings.
 *
 * 0   = Operation Success
 * 1   = Invalid/Incorrect Transaction
 * 2   = Duplicate Transaction
 * 3   = Invalid Parameter
 * 4   = Transaction Is Process
 * 5   = Agent Disable
 * 401 = Insufficient balance
 * 1001 = Operation Failed (Retry)
 * 1002 = System Maintenance (Retry)
 */
enum BigPayzErrorCodeEnum: int
{
    case SUCCESS = 0;
    case INVALID_TRANSACTION = 1;
    case DUPLICATE_TRANSACTION = 2;
    case INVALID_PARAMETER = 3;
    case TRANSACTION_IN_PROCESS = 4;
    case AGENT_DISABLED = 5;
    case INSUFFICIENT_BALANCE = 401;
    case OPERATION_FAILED_RETRY = 1001;
    case SYSTEM_MAINTENANCE_RETRY = 1002;

    public static function fromValue(int $code): self
    {
        return self::tryFrom($code) ?? self::OPERATION_FAILED_RETRY;
    }

    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }

    public function shouldRetry(): bool
    {
        return $this === self::OPERATION_FAILED_RETRY || $this === self::SYSTEM_MAINTENANCE_RETRY;
    }
}
