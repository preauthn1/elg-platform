<?php

declare(strict_types=1);

namespace Tests\Unit\DB;

use App\Models\Currency;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * currencies 資料表的結構與基線守衛（多幣別錢包第二期／階段 2.1）。
 *
 * 幣別主檔是所有 currency_id 欄位的合法值來源。它在 2026-08-28 之前只有 PRIMARY(id)，
 * code 完全沒有索引——而 SyncCurrenciesSeeder 卻以 code 當 uniqueKey 做 upsert。
 * 也就是說「code 唯一」一直是程式的假設、不是資料庫的保證。
 *
 * 兩個測試的分工：
 * - test_seed_data_matches_master_rows_by_id_and_code：把 currencies.php 灌進去後（DatabaseTransactions
 *   會回滾），逐筆核對主檔的 id→code 與資料檔一致。它同時覆蓋 seeder 的一致性守衛：測試庫若有某個 id
 *   被別的 code 佔住，seeder 會拋例外、本測試轉紅。原本這裡是「code 零重複」的前置檢查，唯一鍵建成後
 *   它已不可能失敗（CI 的 currencies 更是空表），故改造成這支。生產的同一條檢查另由稽核排程承擔。
 * - test_code_unique_index_exists：唯一鍵建成後的長期守衛，索引名固定 currencies_code_unique，
 *   幣別一致性稽核排程會引用它。
 *
 * ⚠️ CI 的 currencies 是空表（migrate:fresh 不灌幣別），所以基線守衛必須先 seed 再斷言，不能只讀現況。
 *
 * php artisan test tests/Unit/DB/CurrenciesTableSchemaTest.php
 */
class CurrenciesTableSchemaTest extends TestCase
{
    private const string UNIQUE_INDEX_NAME = 'currencies_code_unique';

    public function test_seed_data_matches_master_rows_by_id_and_code(): void
    {
        $this->seed(CurrencySeeder::class);

        /** @var array<int, string> $master */
        $master = Currency::query()->pluck('code', 'id')->all();
        $mismatches = [];

        foreach (require database_path('data/currencies.php') as $row) {
            $actual = $master[$row['id']] ?? null;

            if ($actual !== $row['code']) {
                $mismatches[] = sprintf('id %d：資料檔 %s、主檔 %s', $row['id'], $row['code'], $actual ?? '（無此列）');
            }
        }

        $this->assertSame([], $mismatches, sprintf(
            "currencies 主檔與 currencies.php 的 id→code 不一致：\n  %s",
            implode("\n  ", $mismatches),
        ));
    }

    public function test_code_unique_index_exists(): void
    {
        $this->assertTrue(
            Schema::hasIndex('currencies', self::UNIQUE_INDEX_NAME, 'unique'),
            sprintf(
                'currencies.code 缺少唯一鍵 %s。SyncCurrenciesSeeder 以 code 當 uniqueKey 做 upsert，'
                . '沒有唯一鍵時同一個 code 可以被建立兩次，而 vendor_infos.currency_id 的回填'
                . '會因此對到不確定的那一筆。',
                self::UNIQUE_INDEX_NAME,
            ),
        );
    }
}
