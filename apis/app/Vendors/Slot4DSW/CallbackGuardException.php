<?php

declare(strict_types=1);

namespace App\Vendors\Slot4DSW;

use Exception;

/**
 * 回調前置驗證（callAction guard）失敗時拋出，
 * 由 Slot4DSWController::callAction 的 catch 轉為對應 errorResponse（api.md §7.3.2）。
 */
class CallbackGuardException extends Exception
{
    public function __construct(
        public readonly OperatorStatusEnum $status,
        public readonly string $description = '',
    ) {
        parent::__construct($description !== '' ? $description : $status->name);
    }
}
