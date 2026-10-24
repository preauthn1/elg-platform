<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Enums\CurrencyEnum;
use PHPUnit\Framework\TestCase;

/**
 * 幣別契約守衛（多幣別錢包 第零期／階段 0.3 交付）。
 *
 * 三份來源必須互相對得上：
 *   database/data/currencies.php        幣別主檔的種子資料
 *   app/Enums/CurrencyEnum.php          程式端的幣別列舉
 *   database/data/currency_aliases.php  vendor_infos.currency 自由字串 → 標準幣別的對照
 *
 * vendor_infos.currency 是 varchar(5) 的自由字串，同時裝了三種東西：標準幣別、
 * 單位變體（KVND＝千分制越南盾）、以及主檔查無的代碼。要為它加 currency_id 就得
 * 先有一張「字串 → 標準幣別」的對照表，那張表就是 currency_aliases.php。
 *
 * php artisan test tests/Unit/Data/CurrencyContractTest.php
 */
class CurrencyContractTest extends TestCase
{
    public function test_enum_matches_currencies_seed_data(): void
    {
        $seed = [];
        foreach (require self::repoPath('database/data/currencies.php') as $row) {
            $seed[(int) $row['id']] = (string) $row['code'];
        }

        $enum = [];
        foreach (CurrencyEnum::cases() as $case) {
            $enum[$case->value] = $case->name;
        }

        ksort($seed);
        ksort($enum);

        $this->assertSame($seed, $enum, sprintf(
            "CurrencyEnum 與 currencies.php 不一致。\n"
            . '主檔 %d 筆、Enum %d 筆。多幣別上線後 Enum 缺項會讓該幣別的錢包建不出來。',
            count($seed),
            count($enum),
        ));
    }

    public function test_every_standard_code_exists_in_enum(): void
    {
        $enumNames = array_map(static fn (CurrencyEnum $c): string => $c->name, CurrencyEnum::cases());

        $missing = array_values(array_diff($this->aliases()['standard'], $enumNames));

        $this->assertSame([], $missing, sprintf(
            "currency_aliases.php 的 standard 清單有 CurrencyEnum 沒有的代碼：\n  %s\n"
            . '已進主檔的標準幣別必須在 Enum 內；尚未進主檔的請放 pending_standard。',
            implode("\n  ", $missing),
        ));
    }

    public function test_pending_standard_codes_are_not_yet_in_enum(): void
    {
        $enumNames = array_map(static fn (CurrencyEnum $c): string => $c->name, CurrencyEnum::cases());

        $alreadyIn = array_values(array_intersect($this->aliases()['pending_standard'], $enumNames));

        $this->assertSame([], $alreadyIn, sprintf(
            "以下代碼已進 CurrencyEnum，應從 pending_standard 移到 standard：\n  %s\n"
            . 'pending_standard 的語意是「已知的標準幣別，但主檔還沒建」，補進主檔後要同步搬家。',
            implode("\n  ", $alreadyIn),
        ));
    }

    public function test_unit_variants_map_to_standard_codes_only(): void
    {
        $aliases = $this->aliases();
        $standard = array_flip($aliases['standard']);
        $problems = [];

        foreach ($aliases['unit_variant'] as $variant => $target) {
            if (!isset($standard[$target])) {
                $problems[] = sprintf('%s → %s（目標不在 standard 清單內）', $variant, $target);
            }

            if ($variant === $target) {
                $problems[] = sprintf('%s 指向自己，單位變體必須映射到標準幣別', $variant);
            }
        }

        $this->assertSame([], $problems, sprintf(
            "單位變體的映射目標必須是標準幣別：\n  %s\n"
            . 'KVND 必須指向 VND、KIDR 必須指向 IDR。指向自己會讓報表出現兩列同幣別、'
            . '尺度差 1000 倍且不能相加。',
            implode("\n  ", $problems),
        ));
    }

    public function test_normalize_map_is_case_folded(): void
    {
        $problems = [];

        foreach ($this->aliases()['normalize'] as $raw => $normalized) {
            if ((string) $raw === $normalized) {
                $problems[] = sprintf('%s 的正規化結果與原值相同，不需列入 normalize', $raw);
            }
        }

        $this->assertSame([], $problems, sprintf(
            "normalize 只該收「需要改寫才對得上」的值（如 kIDR → KIDR）：\n  %s",
            implode("\n  ", $problems),
        ));
    }

    public function test_every_alias_section_is_disjoint(): void
    {
        $aliases = $this->aliases();
        $overlaps = [];

        $sections = [
            // normalize 的 key 是原始寫法（kIDR／HKB），也不能同時出現在別段——否則稽核先正規化再分類，
            // 留在別段的舊 key 會變成永遠讀不到的死條目（2026-09-07 HKB 從 unresolved 搬到 normalize 時補上）
            'normalize' => array_keys($aliases['normalize']),
            'standard' => $aliases['standard'],
            'pending_standard' => $aliases['pending_standard'],
            'crypto' => $aliases['crypto'],
            'non_currency' => $aliases['non_currency'],
            'unit_variant' => array_keys($aliases['unit_variant']),
            'unresolved' => array_keys($aliases['unresolved']),
        ];

        foreach ($sections as $nameA => $codesA) {
            foreach ($sections as $nameB => $codesB) {
                if ($nameA >= $nameB) {
                    continue;
                }

                foreach (array_intersect($codesA, $codesB) as $code) {
                    $overlaps[] = sprintf('%s 同時出現在 %s 與 %s', $code, $nameA, $nameB);
                }
            }
        }

        $this->assertSame([], $overlaps, sprintf(
            "同一個幣別代碼只能屬於一個分類，否則回填時會取到不確定的結果：\n  %s",
            implode("\n  ", $overlaps),
        ));
    }

    /** @return array<string, mixed> */
    private function aliases(): array
    {
        return require self::repoPath('database/data/currency_aliases.php');
    }

    /**
     * 本測試不啟動 Laravel（繼承純 PHPUnit TestCase），database_path() 不可用，
     * 比照 BankRegistryParityTest 以 __DIR__ 回推專案根目錄。
     */
    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
