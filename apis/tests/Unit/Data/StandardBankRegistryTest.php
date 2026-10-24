<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Services\CashFlow\Bank\StandardBankRegistry;
use PHPUnit\Framework\TestCase;

/**
 * 標準銀行分檔（app/Console/Commands/CashFlow/Data/banks/{幣別}.php）的結構不變式。
 *
 * 分檔是名稱正規化（StandardBankNameHelper / BankMismatchAuditor 讀 standard_name／
 * aliases／keywords）與銀行身分（SyncStandardBanksSeeder 讀 code／name／swift 落
 * standard_banks 表）的單一來源。三條不變式：
 *
 * 1. 每筆條目必備六個欄位——seeder 與 auditor 各取所需，缺鍵即靜默漏資料。
 * 2. code 必為 `{幣別}_{名稱}` 前綴格式且唯一——upsert 鍵 (code, currency_id) 靠它
 *    不殘留舊列；前綴與 currency_id 互為校驗；BankStandardMappingService::
 *    loadConfigKeywords 以 code 為鍵，破格式的碼會讓 keyword 比對靜默回空。
 * 3. 同幣別內 name／standard_name／alias 不得被多家爭用（以寫進 standard_banks 的啟用列為準）——matchExact 只接受唯一
 *    命中，被爭用的名稱會整組放棄比對，是靜默失效（SCB／VCB／Boost 讓渡見
 *    2026-08-28 的 curation）。
 *
 * 前身 BankRegistryParityTest 守的是「兩份重疊清單」的落差；分檔合併後單一來源，
 * 涵蓋率棘輪已無對象，轉型為本測試。
 *
 * php artisan test tests/Unit/Data/StandardBankRegistryTest.php
 */
class StandardBankRegistryTest extends TestCase
{
    private const array CURRENCIES = ['IDR', 'INR', 'MYR', 'SGD', 'VND'];

    private const array REQUIRED_KEYS = ['code', 'standard_name', 'name', 'swift', 'aliases', 'keywords'];

    public function test_every_entry_carries_required_fields(): void
    {
        $violations = [];

        foreach ($this->registries() as $currency => $entries) {
            foreach ($entries as $i => $entry) {
                $missing = array_diff(self::REQUIRED_KEYS, array_keys($entry));

                if ($missing !== []) {
                    $violations[] = sprintf('%s #%d（%s）缺 %s', $currency, $i, $entry['standard_name'] ?? '?', implode('、', $missing));
                }
            }
        }

        $this->assertSame([], $violations, sprintf(
            "分檔條目缺必備欄位（seeder 與 auditor 各取所需，缺鍵即靜默漏資料）：\n  %s",
            implode("\n  ", $violations),
        ));
    }

    public function test_codes_carry_their_currency_prefix_and_are_unique(): void
    {
        $violations = [];
        $seen = [];

        foreach ($this->registries() as $currency => $entries) {
            foreach ($entries as $entry) {
                $code = (string) $entry['code'];

                if (!str_starts_with($code, $currency . '_') || $code === $currency . '_') {
                    $violations[] = sprintf('%s：%s 未帶幣別前綴', $currency, $code);
                }

                if (isset($seen[$code])) {
                    $violations[] = sprintf('%s：%s 重複', $currency, $code);
                }
                $seen[$code] = true;
            }
        }

        $this->assertSame([], $violations, sprintf(
            "standard code 必須是 `{幣別}_{名稱}` 前綴格式且唯一：upsert 鍵 (code, currency_id)\n"
            . "靠它不殘留舊列、keyword 比對以 code 為鍵、前綴與 currency_id 互為校驗：\n  %s",
            implode("\n  ", $violations),
        ));
    }

    public function test_type_when_present_is_a_known_enum_value(): void
    {
        $valid = array_column(\App\Enums\StandardBankTypeEnum::cases(), 'value');
        $violations = [];

        foreach ($this->registries() as $currency => $entries) {
            foreach ($entries as $entry) {
                if (array_key_exists('type', $entry) && !in_array($entry['type'], $valid, true)) {
                    $violations[] = sprintf('%s：%s type=%s', $currency, $entry['code'], var_export($entry['type'], true));
                }
            }
        }

        $this->assertSame([], $violations, sprintf(
            "type 必須是 StandardBankTypeEnum 的值（未標＝銀行帳號）：\n  %s",
            implode("\n  ", $violations),
        ));
    }

    /**
     * 檢查的是寫進 standard_banks 的啟用列（StandardBankRegistry::dbEntries），不是分檔原文：
     * 已併入別家的舊條目會停用，它的名稱會併進現在那家；只寫進 DB 的條目會從其他列取走自己的名稱。
     */
    public function test_no_ambiguous_name_or_alias_within_currency(): void
    {
        $collisions = [];

        foreach ($this->registries() as $currency => $entries) {
            $owners = [];
            $dbEntries = StandardBankRegistry::dbEntries(array_map(
                static fn (array $entry): array => [...$entry, 'currency' => $currency],
                $entries,
            ));

            foreach ($dbEntries as $entry) {
                if ($entry['retired']) {
                    continue;
                }

                $labels = array_merge([$entry['name'], $entry['standard_name']], $entry['aliases'] ?? []);

                foreach ($labels as $label) {
                    $owners[$this->normalize((string) $label)][(string) $entry['code']] = true;
                }
            }

            foreach ($owners as $label => $codes) {
                if (count($codes) > 1) {
                    $collisions[] = sprintf('%s「%s」→ %s', $currency, $label, implode(', ', array_keys($codes)));
                }
            }
        }

        $this->assertSame([], $collisions, sprintf(
            '分檔有名稱／別名被同幣別多家爭用，BankStandardMappingService::matchExact'
            . " 只接受唯一命中，這些名稱會全數對不到 standard：\n  %s",
            implode("\n  ", $collisions),
        ));
    }

    /**
     * retired_into 必須指向同幣別、仍在使用的條目，否則舊名會併進不存在或已停用的列，
     * bank:sync-mappings 對不到任何標準銀行。cash-flow:sync 看到的條目要保留舊條目、
     * 排除只寫進 DB 的條目，金流商代碼才不會改掛到別列平台銀行（理由見 StandardBankRegistry）。
     */
    public function test_retired_and_db_only_entries_keep_cash_flow_sync_unchanged(): void
    {
        $violations = [];

        foreach ($this->registries() as $currency => $entries) {
            $byCode = array_column($entries, null, 'code');

            foreach ($entries as $entry) {
                $target = $entry[StandardBankRegistry::RETIRED_INTO] ?? null;
                $hidden = ($entry[StandardBankRegistry::SYNC_VISIBLE] ?? true) === false;

                if ($target !== null && (!isset($byCode[$target]) || isset($byCode[$target][StandardBankRegistry::RETIRED_INTO]))) {
                    $violations[] = sprintf('%s：%s 併入的 %s 不存在或也已併入別家', $currency, $entry['code'], $target);
                }

                if ($target !== null && $hidden) {
                    $violations[] = sprintf('%s：%s 不可同時標 retired_into 與 sync_visible=false', $currency, $entry['code']);
                }
            }

            $syncCodes = array_column(StandardBankRegistry::syncEntries($entries), 'code');
            foreach ($entries as $entry) {
                $hidden = ($entry[StandardBankRegistry::SYNC_VISIBLE] ?? true) === false;

                if ($hidden === in_array($entry['code'], $syncCodes, true)) {
                    $violations[] = sprintf('%s：%s 在 cash-flow:sync 的可見性不對', $currency, $entry['code']);
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));
        $this->assertSame('IDR_ACEHSYARIAH', array_column($this->registries()['IDR'], null, 'code')['IDR_ACEH'][StandardBankRegistry::RETIRED_INTO]);
    }

    /**
     * cash-flow:sync 把 sync_visible=false 條目擁有的名稱當成比對不到（StandardBankNameHelper::matchBankName）。
     * 這份名稱若和可見條目自己的 name／standard_name 撞名，那家一般戶就整個對不到、金流商碼停在停用而斷付。
     * 比對用 StandardBankNameHelper::normalizeString 的規則（去幣別前綴、空白與符號），這裡照抄。
     */
    public function test_hidden_labels_never_block_a_visible_bank_own_name(): void
    {
        $normalize = static fn (string $name): string => strtolower((string) preg_replace(
            '/[\s\-_\'.()\[\]]+/',
            '',
            (string) preg_replace('/^\([A-Z]{3}\)\s*-\s*/', '', $name),
        ));
        $violations = [];

        foreach ($this->registries() as $currency => $entries) {
            $hidden = array_flip(array_map($normalize, StandardBankRegistry::hiddenLabels($entries)));

            foreach (StandardBankRegistry::syncEntries($entries) as $entry) {
                foreach ([$entry['name'], $entry['standard_name']] as $own) {
                    if (isset($hidden[$normalize($own)])) {
                        $violations[] = sprintf('%s：%s 的「%s」被只寫進 DB 的條目擋下', $currency, $entry['code'], $own);
                    }
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", $violations));

        $idrHidden = StandardBankRegistry::hiddenLabels($this->registries()['IDR']);
        $this->assertContains('BPD Kalimantan Barat UUS', $idrHidden);
        $this->assertNotContains('Bank Kalbar', $idrHidden);
    }

    /**
     * 新加坡 Maybank 是兩個法人，金流商碼表也分成兩個碼：母行全名對母行分行條目
     * （SGD_MAYBANK_CORP），「Maybank Singapore」對子公司條目（SGD_MAYBANK）。
     * 兩者共用一個標準銀行時，同群組兩列並存會讓 agent_banks 投影整組跳過。
     */
    public function test_sgd_maybank_branch_and_subsidiary_stay_separate(): void
    {
        $byCode = array_column($this->registries()['SGD'], null, 'code');
        $labelsOf = fn (string $code): array => array_map(
            fn (string $label): string => $this->normalize($label),
            array_merge([$byCode[$code]['name'], $byCode[$code]['standard_name']], $byCode[$code]['aliases']),
        );

        foreach (['(SGD) - Malayan Banking Berhad', '(SGD) - Malayan Banking Berhad, Singapore Branch'] as $name) {
            $this->assertContains($this->normalize($name), $labelsOf('SGD_MAYBANK_CORP'), "{$name} 應歸母行分行條目");
        }
        $this->assertContains($this->normalize('(SGD) - Maybank Singapore'), $labelsOf('SGD_MAYBANK'));
        $this->assertNotContains($this->normalize('(SGD) - Maybank Singapore'), $labelsOf('SGD_MAYBANK_CORP'));
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function registries(): array
    {
        static $cache = null;

        if ($cache === null) {
            $cache = [];

            foreach (self::CURRENCIES as $currency) {
                $cache[$currency] = require self::repoPath(
                    sprintf('app/Console/Commands/CashFlow/Data/banks/%s.php', $currency),
                );
            }
        }

        return $cache;
    }

    /** 純 config 測試不啟動框架，路徑自行由 repo 根目錄組出（不用 base_path()）。 */
    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    /**
     * 對齊 BankStandardMappingService::normalizeName——剝幣別前綴後轉小寫。
     */
    private function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/^\([A-Z]{3}\)\s*-\s*/', '', trim($name))));
    }
}
