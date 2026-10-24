<?php

declare(strict_types=1);

namespace App\Services\Payment\Concerns;

use App\Helpers\Common;

/**
 * 三方金額欄位正規化：只接受數值型 scalar，其餘（缺欄、'N/A'、陣列）回 null 不硬轉——
 * 非數值進 numAdd 會拋 bc 錯誤，硬 (string) 會違反外部數值入境紀律。
 */
trait NormalizesThirdPartyAmounts
{
    protected function normalizeAmount(mixed $value): ?string
    {
        return is_numeric($value) ? Common::numAdd('0', (string) $value, 4) : null;
    }
}
