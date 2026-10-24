<?php

declare(strict_types=1);

namespace App\Services\Currency;

use App\Enums\Currency\CurrencyCodeStatusEnum;
use App\Enums\CurrencyEnum;
use App\Models\Currency;
use RuntimeException;

/**
 * 把 vendor_infos.currency 這類自由字串對到 currencies 主檔的 currency_id。
 *
 * 解析鏈固定三跳：normalize（修寫法差異）→ unit_variant（計價單位變體對回標準幣別）
 * → CurrencyEnum ∩ currencies 主檔。稽核（currency:audit-vendor-info）與回填
 * （currency:backfill-vendor-info）都必須走這一個類別，兩邊對同一個字串才會得到同一個 id。
 *
 * 「對得到」要兩邊都有：CurrencyEnum 有同名 case，**且** 主檔在該 id 真的是這個 code。
 * 只看 Enum 會在「版控已補 case、目標庫 seeder 還沒跑」的站開綠燈，回填出指不到主檔的
 * 孤兒 currency_id（該欄無外鍵、無 CHECK，沒有第二道會擋）。
 *
 * 對照表與主檔在建構時一次載入、之後只讀：主檔由 fromContract() 從當前連線查一次，
 * 解析器本身不再碰資料庫。
 *
 * @knowledge rules/currency-and-wallet-currency
 */
final class CurrencyCodeResolver
{
    /**
     * @param  array<array-key, mixed>  $aliases  database/data/currency_aliases.php 的內容
     * @param  array<int, string>  $masterCodesById  currencies 主檔的 id → code
     */
    public function __construct(
        private readonly array $aliases,
        private readonly array $masterCodesById,
    ) {}

    /**
     * 讀 alias 契約檔與當前連線的 currencies 主檔建解析器。
     *
     * 主檔是 currency_id 的合法值來源；Enum 只是它在程式裡的鏡像，兩邊可能脫鉤
     * （版控先補了 case、該站 seeder 還沒跑）。跑在哪個連線就查哪個庫。
     *
     * @throws RuntimeException 對照表不是陣列時
     */
    public static function fromContract(): self
    {
        $aliases = require database_path('data/currency_aliases.php');

        if (!is_array($aliases)) {
            throw new RuntimeException('幣別對照表格式不正確，預期取得陣列');
        }

        /** @var array<int, string> $codes */
        $codes = Currency::query()->pluck('code', 'id')->all();

        return new self($aliases, $codes);
    }

    public function resolve(string $raw): CurrencyCodeResolution
    {
        // normalize 修掉寫法差異（kIDR → KIDR）；unit_variant 再把計價單位變體
        // 對到它代表的標準幣別（KVND → VND）。currency_id 取後者——變體的尺度差
        // 由 vendor_infos.rate 吸收，不靠獨立的幣別 id 表達。
        $normalized = $this->lookup('normalize', $raw) ?? $raw;
        $resolved = $this->lookup('unit_variant', $normalized) ?? $normalized;

        $enumId = $this->enumIdOf($resolved);
        $masterCode = $enumId === null ? null : ($this->masterCodesById[$enumId] ?? null);
        $currencyId = $masterCode === $resolved ? $enumId : null;

        return new CurrencyCodeResolution(
            raw: $raw,
            normalized: $normalized,
            resolved: $resolved,
            enumId: $enumId,
            masterCode: $masterCode,
            currencyId: $currencyId,
            status: $this->classify($normalized, $resolved, $enumId, $masterCode),
        );
    }

    /**
     * 判定這個代碼「是什麼」，順序由窄到寬。
     *
     * 🔴 non_currency 與 crypto 必須排在 currency_id 檢查之前：FON／GCASH／PayMaya
     *    與 USDT／TON／BUSD 都在 CurrencyEnum 裡，若先看 currency_id 就會全部落進
     *    mapped，那兩個分類永遠顯示不出來。
     *    ⚠️ USDT 曾於 2026-09-09 依決議㉖ 改 normalize 成 USD，2026-09-17 決議㉚ 撤回：
     *    它回到 crypto 分類，解析結果帶自己的 currency_id，不再對應 USD。
     *
     * 三個清單型分類同時比對 normalized 與 resolved：目前對照表的 unit_variant
     * 目標都落在 standard，兩者判定結果一致；但若日後新增指向 pending_standard
     * 的變體（例如 KPHP → PHP），只比 normalized 會把「主檔待補」誤判成「代碼不明」。
     *
     * @param  int|null  $enumId  CurrencyEnum 同名 case 的 id
     * @param  string|null  $masterCode  currencies 主檔在該 id 的 code；無此列為 null
     */
    private function classify(string $normalized, string $resolved, ?int $enumId, ?string $masterCode): CurrencyCodeStatusEnum
    {
        return match (true) {
            $this->lookup('unresolved', $normalized) !== null => CurrencyCodeStatusEnum::UNRESOLVED,
            $this->listed('non_currency', $normalized, $resolved) => CurrencyCodeStatusEnum::NON_CURRENCY,
            $this->listed('crypto', $normalized, $resolved) => CurrencyCodeStatusEnum::CRYPTO,
            $enumId !== null && $masterCode === $resolved => CurrencyCodeStatusEnum::MAPPED,
            $enumId !== null && $masterCode === null => CurrencyCodeStatusEnum::ENUM_ONLY,
            $enumId !== null => CurrencyCodeStatusEnum::ID_MISMATCH,
            $this->listed('pending_standard', $normalized, $resolved) => CurrencyCodeStatusEnum::PENDING_SEED,
            default => CurrencyCodeStatusEnum::UNKNOWN,
        };
    }

    /**
     * 取 CurrencyEnum 中名稱相符的 id。
     *
     * CurrencyEnum 是 backed by int、沒有 fromName()，只能走 cases() 比對 name；
     * tryFrom() 吃的是 id 不是幣別名稱，不能拿來查這個。
     */
    private function enumIdOf(string $name): ?int
    {
        foreach (CurrencyEnum::cases() as $case) {
            if ($case->name === $name) {
                return $case->value;
            }
        }

        return null;
    }

    /**
     * 讀對照表中「代碼 => 值」型分類的對應值。
     */
    private function lookup(string $group, string $code): ?string
    {
        $map = $this->aliases[$group] ?? [];

        if (!is_array($map)) {
            return null;
        }

        $value = $map[$code] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * 判斷任一個代碼是否列在「純清單」型分類中。
     */
    private function listed(string $group, string ...$codes): bool
    {
        $list = $this->aliases[$group] ?? [];

        if (!is_array($list)) {
            return false;
        }

        foreach ($codes as $code) {
            if (in_array($code, $list, true)) {
                return true;
            }
        }

        return false;
    }
}
