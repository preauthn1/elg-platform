<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

/**
 * processPayout() 的 interpretResult closure 回傳值：
 * 將三方原始回應解讀為「是否受理 + 三方單號 + 錯誤訊息」。
 */
final readonly class PayoutResult
{
    public function __construct(
        public bool $isSuccess,
        public string $thirdPartyOrderNo = '',
        public string $errorMessage = '',
    ) {}
}
