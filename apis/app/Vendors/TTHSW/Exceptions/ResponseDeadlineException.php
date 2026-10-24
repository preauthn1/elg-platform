<?php

declare(strict_types=1);

namespace App\Vendors\TTHSW\Exceptions;

use RuntimeException;

/**
 * 回應期限內未完成處理，交易須 rollback 並回錯誤
 */
class ResponseDeadlineException extends RuntimeException
{
    public function __construct(
        public readonly int $elapsedMs,
        public readonly int $deadlineMs,
    ) {
        parent::__construct("Response deadline exceeded ({$elapsedMs}ms > {$deadlineMs}ms)");
    }
}
