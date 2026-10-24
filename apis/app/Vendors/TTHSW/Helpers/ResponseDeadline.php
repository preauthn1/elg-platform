<?php

declare(strict_types=1);

namespace App\Vendors\TTHSW\Helpers;

use App\Vendors\TTHSW\Exceptions\ResponseDeadlineException;
use Illuminate\Http\Request;

/**
 * 以 PHP 收到請求的時間為起點計算回應期限
 *
 * @knowledge rules/vendor-tthsw
 */
final class ResponseDeadline
{
    public function __construct(
        private readonly int $startMs,
        private readonly int $deadlineMs,
    ) {}

    public static function fromRequest(Request $request, int $deadlineMs): self
    {
        $startMs = (int) floor((float) $request->server('REQUEST_TIME_FLOAT', microtime(true)) * 1000);

        return new self($startMs, $deadlineMs);
    }

    public function elapsedMs(?int $nowMs = null): int
    {
        return ($nowMs ?? self::nowMs()) - $this->startMs;
    }

    /**
     * 超過期限即拋例外
     */
    public function assert(?int $nowMs = null): void
    {
        $elapsedMs = $this->elapsedMs($nowMs);

        if ($elapsedMs > $this->deadlineMs) {
            throw new ResponseDeadlineException($elapsedMs, $this->deadlineMs);
        }
    }

    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
