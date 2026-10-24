<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Enums\Currency\CurrencyCodeStatusEnum;

/**
 * 一個原始幣別字串經 CurrencyCodeResolver 解析後的完整結果。
 *
 * 保留每一跳的中間值（normalized／resolved／enumId／masterCode），稽核輸出與回填的
 * 跳過原因都直接取用，不必各自重算。
 */
final readonly class CurrencyCodeResolution
{
    public function __construct(
        /** 資料表裡的原始字串，原樣保留（含大小寫） */
        public string $raw,
        /** 經 normalize 改寫後的代碼（kIDR → KIDR、HKB → HKD） */
        public string $normalized,
        /** 經 unit_variant 對照後的標準幣別代碼（KVND → VND） */
        public string $resolved,
        /** CurrencyEnum 同名 case 的 id；無同名 case 為 null */
        public ?int $enumId,
        /** currencies 主檔在 enumId 那一列的 code；主檔無此列或 enumId 為 null 時為 null */
        public ?string $masterCode,
        /** 可寫進 currency_id 的值；Enum 與主檔任一邊對不上即為 null */
        public ?int $currencyId,
        public CurrencyCodeStatusEnum $status,
    ) {}
}
