<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use PHPUnit\Framework\TestCase;

/**
 * canonical BR 表清單的結構守衛。
 *
 * 多幣別錢包 第零期（階段 0.2）交付物。三份來源給出三個數字（DB 136／vendors.php 123／
 * 舊盤點 126），本 registry 是定版後的唯一權威——第三期 3.1（2026-09-03 決議⑭後改為
 * 「TBR 兩欄的來源規則」，BR 表不加欄位）與 writer registry 都以它為輸入。
 *
 * 🔴 本測試只驗「版控內自洽」：條目結構完整、分類與 vendor_ids 不互相矛盾、各分類筆數
 *    符合定版時的實測結果。**它不比對真實表名**——registry 是六站聯集，而每一站的實際
 *    表名集合都不同，那件事只有連得上資料庫的 artisan bet-record:audit-tables 做得到。
 *
 * php artisan test tests/Unit/Data/BetRecordTableRegistryParityTest.php
 */
class BetRecordTableRegistryParityTest extends TestCase
{
    private const array VALID_KINDS = ['vendor', 'event', 'non_bet_record', 'orphan', 'registry_only'];

    /** 必須帶非空 vendor_ids 的分類；其餘分類一律要求空陣列。 */
    private const array KINDS_REQUIRING_VENDOR_IDS = ['vendor', 'registry_only'];

    /**
     * owner 的值域。
     *
     * 🔴 有這條斷言是因為初版把 133 筆全標成 APIs，而值域裡宣告的 RD-Studio-01 一次都沒用上——
     *    值域只寫在註解裡、沒有任何測試守，所以錯得沒被發現（2026-08-31 圓桌審查抓到）。
     * ⚠️ API_Agent 經四個舊 repo 全掃確認對注單表零寫入，不列入值域。
     */
    private const array VALID_OWNERS = ['APIs', 'RD-Studio-01', 'API_External', '待查'];

    /**
     * 一律不登記的表（與 artisan bet-record:audit-tables 的 EXCLUDED_TABLES 同一口徑）。
     *
     * 🔴 兩邊必須同步，而這條測試就是那個「必須」的執行者——原本兩個檔案各寫一段紅字互相叮嚀，
     *    但沒有任何機器守衛：實測把命令的 SQL `NOT IN` 拿掉後，7 支測試全綠、假差異照樣冒出來。
     */
    private const array NEVER_REGISTERED_PREFIXES = ['total', 'summary'];

    /**
     * 六站生產庫「實際存在的表」條目數，取聯集（2026-08-31 實測），已排除 total 與 summary。
     *
     * 🔴 這是**聯集**不是單站張數——沒有任何一站是 138 張：
     *     VWorld／TPower／Alfawin77／Me77／VPlus33  各 136 張，表名逐字相同
     *     Yes2Win                                  138 張，多出 bolakk4d0217、bolakk4d_0217
     *                                              （COUNT(*) 精確計數 0 列、結構與正式表逐欄相同、
     *                                                四個舊 repo 與 APIs 皆零引用 ⇒ 判為備份表）
     *    因此跨白標稽核時，前五站各會報出 2 張「registry 有、本站沒有」，那是預期行為。
     *
     * 🔴 total 與 summary 一律排除，與 artisan bet-record:audit-tables 的 SQL `NOT IN`
     *    保持同一口徑；改動其中一邊就必須同時改另一邊，否則會報出假差異。
     */
    private const int PRODUCTION_TABLE_UNION_COUNT = 138;

    /** vendors.php 有 tname 但六站生產庫皆無對應表者（coke_fighting／testgame／bngsw／aigamingsw／suncity）。 */
    private const int REGISTRY_ONLY_COUNT = 5;

    public function test_every_entry_has_complete_shape(): void
    {
        $problems = [];

        foreach ($this->registry() as $prefix => $entry) {
            // 逐項無條件驗型別。刻意不用 isset()——它對 null 回傳 false，會讓
            // 'kind' => null 這種寫法整條跳過檢查而漏網（2026-08-31 代碼複查抓到）。
            if (!array_key_exists('kind', $entry) || !is_string($entry['kind'])) {
                $problems[] = sprintf('%s 的 kind 缺漏或不是字串', $prefix);
            } elseif (!in_array($entry['kind'], self::VALID_KINDS, true)) {
                $problems[] = sprintf('%s 的 kind「%s」不在值域內', $prefix, $entry['kind']);
            }

            if (!array_key_exists('vendor_ids', $entry) || !is_array($entry['vendor_ids'])) {
                $problems[] = sprintf('%s 的 vendor_ids 缺漏或不是陣列', $prefix);
            }

            $problems = [...$problems, ...$this->ownerProblems($prefix, $entry)];

            // currency_source 與 note 允許 null（尚未確認／無備註），但有值時必須是字串。
            foreach (['currency_source', 'note'] as $nullable) {
                if (!array_key_exists($nullable, $entry)) {
                    $problems[] = sprintf('%s 缺少 %s', $prefix, $nullable);
                } elseif ($entry[$nullable] !== null && !is_string($entry[$nullable])) {
                    $problems[] = sprintf('%s 的 %s 必須是字串或 null', $prefix, $nullable);
                }
            }
        }

        $this->assertSame([], $problems, "tables.php 條目結構不完整：\n  " . implode("\n  ", $problems));
    }

    public function test_registry_matches_expected_entry_counts(): void
    {
        $registry = $this->registry();

        $registryOnly = array_filter(
            $registry,
            static fn (array $entry): bool => ($entry['kind'] ?? null) === 'registry_only',
        );

        // 分開斷言三個數字，任何一邊被改動都指得出來——只鎖總數的話，
        // 「刪一筆生產表、補一筆 registry_only」這種等量替換會靜默通過。
        $this->assertCount(self::REGISTRY_ONLY_COUNT, $registryOnly, sprintf(
            'registry_only 條目應為 %d 筆（vendors.php 有 tname 但六站皆無對應表）',
            self::REGISTRY_ONLY_COUNT,
        ));

        $this->assertCount(self::PRODUCTION_TABLE_UNION_COUNT, array_diff_key($registry, $registryOnly), sprintf(
            "標記為「生產庫存在」的條目應為 %d 筆（2026-08-31 六站實測的聯集）。\n"
            . '生產庫實際新增或移除表時，請先跑 artisan bet-record:audit-tables 確認，'
            . '再同步本 registry 與 PRODUCTION_TABLE_UNION_COUNT。',
            self::PRODUCTION_TABLE_UNION_COUNT,
        ));

        $this->assertCount(
            self::PRODUCTION_TABLE_UNION_COUNT + self::REGISTRY_ONLY_COUNT,
            $registry,
            'registry 總筆數應等於「生產庫存在」加上 registry_only，出現落差代表有條目被重複或遺漏',
        );
    }

    /**
     * owner 欄的完整檢查。
     *
     * owner 是陣列而非單值字串：遊戲商逐家遷移，APIs 接手後舊系統的排程／路由未必同步收掉，
     * 雙寫是現行常態（例：ace333）。
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function ownerProblems(string $prefix, array $entry): array
    {
        if (!array_key_exists('owner', $entry) || !is_array($entry['owner']) || $entry['owner'] === []) {
            return [sprintf('%s 的 owner 缺漏或不是非空陣列', $prefix)];
        }

        $problems = [];

        foreach ($entry['owner'] as $owner) {
            if (!is_string($owner) || !in_array($owner, self::VALID_OWNERS, true)) {
                $problems[] = sprintf(
                    '%s 的 owner 含不在值域內的值「%s」（值域：%s）',
                    $prefix,
                    is_string($owner) ? $owner : get_debug_type($owner),
                    implode('／', self::VALID_OWNERS),
                );
            }
        }

        if (count($entry['owner']) !== count(array_unique($entry['owner']))) {
            $problems[] = sprintf('%s 的 owner 有重複值', $prefix);
        }

        // 「待查」是「查不到任何寫入者」的意思，不能跟具體 repo 併列。
        if (in_array('待查', $entry['owner'], true) && count($entry['owner']) > 1) {
            $problems[] = sprintf('%s 的 owner 把「待查」與具體 repo 併列，語意矛盾', $prefix);
        }

        return $problems;
    }

    public function test_never_registered_prefixes_stay_out_of_registry(): void
    {
        $registry = $this->registry();

        foreach (self::NEVER_REGISTERED_PREFIXES as $prefix) {
            $this->assertArrayNotHasKey($prefix, $registry, sprintf(
                "%s_bet_records 是彙總去處而非注單源頭，刻意不登記。\n"
                . '若確定要納入，artisan bet-record:audit-tables 的 EXCLUDED_TABLES 必須同時改，'
                . '否則稽核會報出假差異。',
                $prefix,
            ));
        }
    }

    public function test_vendor_ids_are_consistent_with_kind(): void
    {
        $vendors = require self::repoPath('database/data/vendors.php');
        $problems = [];

        foreach ($this->registry() as $prefix => $entry) {
            $kind = $entry['kind'] ?? null;
            $vendorIds = $entry['vendor_ids'] ?? [];

            if (!is_string($kind) || !is_array($vendorIds)) {
                continue; // 結構問題由 test_every_entry_has_complete_shape 負責回報
            }

            if (in_array($kind, self::KINDS_REQUIRING_VENDOR_IDS, true)) {
                if ($vendorIds === []) {
                    $problems[] = sprintf('%s 標為 %s 卻沒有任何 vendor_ids', $prefix, $kind);
                }
            } elseif ($vendorIds !== []) {
                // event／non_bet_record／orphan 都不該掛 vendor_ids——掛了就代表
                // 分類與資料互相矛盾，例如把某張遊戲商表誤標成 orphan。
                $problems[] = sprintf(
                    '%s 標為 %s 卻帶了 vendor_ids [%s]',
                    $prefix,
                    $kind,
                    implode(', ', array_map('strval', $vendorIds)),
                );
            }

            foreach ($vendorIds as $vendorId) {
                if (!array_key_exists($vendorId, $vendors)) {
                    $problems[] = sprintf('%s 引用了 vendors.php 沒有的 vendor_id=%s', $prefix, (string) $vendorId);
                }
            }
        }

        $this->assertSame([], $problems, "kind 與 vendor_ids 不一致：\n  " . implode("\n  ", $problems));
    }

    /**
     * 反向覆蓋：vendors.php 只要有 tname，就必須有 tables.php 條目引用它的 id。
     *
     * 🔴 這條守的是「契約凍結之後新接的遊戲商會不會靜默漏登記」。上一條
     *    test_vendor_ids_are_consistent_with_kind 只驗單向（registry → vendors.php），
     *    新遊戲商只加 vendors.php 與自己的注單表、不碰本清單時全部照綠，清單就在
     *    沒人察覺的情況下與生產庫脫節——2026-09-03 Dev 部署後由
     *    `artisan bet-record:audit-tables` 事後才發現 aigamingsw_bet_records 未登記
     *    （來源分支從本清單建立之前分出，整個 database/data/bet_records/ 都不存在），
     *    當時版控內沒有任何機器守衛會擋。這條補上那道閘。
     *
     * ⚠️ 這是版控內自洽，不查資料庫：新遊戲商的表通常先只在 Dev 存在，生產庫還沒有，
     *    此時條目應登記為 registry_only（vendors.php 有 tname、六站生產庫尚無對應表），
     *    並把 REGISTRY_ONLY_COUNT 加一；等該遊戲商實際上線各站、跨白標稽核報出
     *    「registry_only 但 DB 已有」時，再改標 vendor 並改記 PRODUCTION_TABLE_UNION_COUNT。
     *
     * ⚠️ tname 為空字串者排除：vendors.php 的 id=7「沒有雞的鬥雞」已停用且無表名，
     *    tables.php 的檔頭註解已載明刻意不建條目。
     */
    public function test_every_vendor_with_a_table_name_is_covered_by_the_registry(): void
    {
        /** @var array<int, array<string, mixed>> $vendors */
        $vendors = require self::repoPath('database/data/vendors.php');

        $coveredVendorIds = [];
        foreach ($this->registry() as $entry) {
            foreach ($entry['vendor_ids'] ?? [] as $vendorId) {
                $coveredVendorIds[$vendorId] = true;
            }
        }

        $problems = [];
        foreach ($vendors as $vendorId => $vendor) {
            $tname = trim((string) ($vendor['tname'] ?? ''));

            if ($tname === '' || isset($coveredVendorIds[$vendorId])) {
                continue;
            }

            $problems[] = sprintf(
                'vendor_id=%s（tname=%s、name=%s）在 database/data/bet_records/tables.php 沒有任何條目引用',
                (string) $vendorId,
                $tname,
                (string) ($vendor['name'] ?? '?'),
            );
        }

        $this->assertSame(
            [],
            $problems,
            '新遊戲商未登記進注單表清單——請在 database/data/bet_records/tables.php 補條目'
            . "（生產庫尚無該表時用 kind=registry_only 並把 REGISTRY_ONLY_COUNT 加一）：\n  "
            . implode("\n  ", $problems),
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function registry(): array
    {
        return require self::repoPath('database/data/bet_records/tables.php');
    }

    /**
     * 純 PHPUnit 測試不啟動 Laravel，database_path() 不可用（app() 回傳的 Container
     * 沒有 databasePath()）。比照 tests/Unit/Data/BankRegistryParityTest.php 以
     * __DIR__ 回推專案根目錄——2026-08-28 執行 Task 7 時實際踩到並修正。
     */
    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
