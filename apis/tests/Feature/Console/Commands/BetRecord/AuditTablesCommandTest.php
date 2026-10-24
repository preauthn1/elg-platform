<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\BetRecord;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * bet-record:audit-tables 的行為測試。
 *
 * 🔴 不要在這裡加 RefreshDatabase：它對 MySQL 測試庫會 drop 全部資料表重建。
 *    父類 Tests\TestCase 已 use DatabaseTransactions。本命令全程唯讀，不需要任何重整。
 *
 * ⚠️ 測試庫的表集合與生產庫不同，而且會隨 migration 增減。因此本測試**刻意不斷言
 *    差異的內容或退出碼的絕對值**——那會讓測試綁死在一個隨時會變的外部狀態上。
 *    驗的是「命令能跑完、輸出結構完整、退出碼忠實反映它自己算出來的差異」。
 *    真實環境的比對結果由跨白標實跑負責，不是單元測試的職責。
 *
 * php artisan test tests/Feature/Console/Commands/BetRecord/AuditTablesCommandTest.php
 */
class AuditTablesCommandTest extends TestCase
{
    public function test_table_output_reports_counts_and_connection(): void
    {
        $this->artisan('bet-record:audit-tables')
            ->expectsOutputToContain('registry 條目')
            ->expectsOutputToContain('資料庫實際表數')
            ->expectsOutputToContain('連線');
    }

    public function test_json_output_has_expected_shape(): void
    {
        $payload = $this->auditAsJson();

        // 這支測試的定位是守住給下游（Task 3～6 的 writer 稽核）的 JSON 契約，
        // 所以鍵要列齊，不能只挑幾個。
        $keys = [
            'connection', 'database', 'registry_total_count', 'expected_table_count',
            'database_count', 'only_in_database', 'only_in_registry', 'registry_only_present',
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $payload, sprintf('--json 輸出缺少 %s', $key));
        }

        // registry_total_count 是全部條目，expected_table_count 已排除 registry_only，
        // 兩者刻意分開——下游若拿後者當「registry 有幾筆」會少算 registry_only 那幾筆。
        $this->assertIsInt($payload['registry_total_count']);
        $this->assertIsInt($payload['expected_table_count']);
        $this->assertIsInt($payload['database_count']);
        $this->assertGreaterThan($payload['expected_table_count'], $payload['registry_total_count']);
        $this->assertIsArray($payload['only_in_database']);
        $this->assertIsArray($payload['only_in_registry']);
        $this->assertIsArray($payload['registry_only_present']);
    }

    public function test_excluded_tables_never_appear_as_differences(): void
    {
        $payload = $this->auditAsJson();

        // total 與 summary 在命令的 SQL 與 registry 兩邊都被排除，兩邊必須同口徑。
        // 測試庫實際存在這兩張表，所以只要有一邊被改掉，它們就會冒出來變成假差異——
        // 在補這條之前，把命令的 NOT IN 拿掉會讓 7 支測試全綠通過（2026-08-31 實證）。
        foreach (['total', 'summary'] as $prefix) {
            $this->assertNotContains($prefix, $payload['only_in_database'], sprintf(
                '%s_bet_records 不該出現在差異裡：它是彙總去處而非注單源頭，'
                . '命令的 EXCLUDED_TABLES 與 registry 兩邊都排除了它',
                $prefix,
            ));
        }
    }

    public function test_exit_code_reflects_whether_differences_exist(): void
    {
        $exitCode = Artisan::call('bet-record:audit-tables', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $hasDifference = $payload['only_in_database'] !== [] || $payload['only_in_registry'] !== [];

        $this->assertSame(
            $hasDifference ? 1 : 0,
            $exitCode,
            '退出碼必須忠實反映命令自己算出來的差異：有差異回 1、完全一致回 0',
        );
    }

    public function test_only_in_registry_entries_carry_registry_context(): void
    {
        $payload = $this->auditAsJson();

        if ($payload['only_in_registry'] === []) {
            $this->markTestSkipped('測試庫此刻與 registry 沒有「registry 有、DB 沒有」的差異，無從驗證附帶資訊');
        }

        foreach ($payload['only_in_registry'] as $entry) {
            // 光給表名分不出「這張表本來就只有某一站有」和「這一站真的漏建了」。
            // 前者在 registry 的 note 裡寫得清清楚楚，所以差異要把 registry 的
            // 上下文一起帶出來，否則每次稽核都要人工回查清單。
            $this->assertArrayHasKey('prefix', $entry);
            $this->assertArrayHasKey('kind', $entry);
            $this->assertArrayHasKey('note', $entry);
            $this->assertIsString($entry['prefix']);
            $this->assertIsString($entry['kind']);
        }
    }

    /** @return array<string, mixed> */
    private function auditAsJson(): array
    {
        Artisan::call('bet-record:audit-tables', ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }
}
