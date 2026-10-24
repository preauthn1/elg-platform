<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use App\Enums\Payment\PayoutQueryOutcome;

/**
 * SupportsPayoutQuery::queryPayout() 的回傳值（target/tickets#4137 擴充點）：
 * 將三方查單回應解讀為「三態結果 + 三方單號」。
 */
final readonly class PayoutQueryResult
{
    public function __construct(
        public PayoutQueryOutcome $outcome,
        public string $thirdPartyOrderNo = '',
    ) {}
}
