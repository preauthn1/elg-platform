<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\SummaryBetRecord;

use App\Console\Commands\SummaryBetRecord\SummaryBetRecordCommand;
use App\Console\Commands\SummaryBetRecord\SummaryBetRecordRunner;
use App\Console\Commands\SummaryBetRecord\SummaryBetRecordService;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\AgentCustomizedSetting;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorInfo;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * SummaryBetRecordCommand P0 問題測試（使用 Laravel Validator）
 *
 * 測試重點：
 * 1. P0-1: vendor 參數驗證（防止非數字誤判為全廠商執行）
 * 2. P0-2: GROUP BY 查詢在 ONLY_FULL_GROUP_BY 下能正常執行
 * 3. P0-3: 參數格式驗證（date, hour）
 * 4. P0-4: 錯誤回傳碼正確性
 *
 * ## 執行測試
 *
 * ```bash
 * # 執行此測試檔所有測試
 * php artisan test tests/Unit/Console/Commands/SummaryBetRecord/SummaryBetRecordCommandTest.php
 *
 * # 執行單一測試方法
 * php artisan test tests/Unit/Console/Commands/SummaryBetRecord/SummaryBetRecordCommandTest.php --filter test_vendor_parameter_validation_with_laravel_validator
 *
 * # 顯示詳細輸出
 * php artisan test tests/Unit/Console/Commands/SummaryBetRecord/SummaryBetRecordCommandTest.php --verbose
 * ```
 */
class SummaryBetRecordCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // 設置測試用廠商配置
        Config::set('summary_bet_record', [
            VendorIdEnum::JOKER_BACCARAT_SW->value => [
                'third_party_code' => 'TEST_VENDOR',
                'time_field' => 'payout_time',
            ],
        ]);
    }

    #[Test]
    public function test_vendor_parameter_validation_with_laravel_validator()
    {
        // 測試非數字字符串
        $exitCode = Artisan::call('summary_bet_record', [
            '--vendor' => 'abc',
            '--prev-hour' => true,
            '--dry-run' => true,
        ]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--vendor 參數必須為整數', Artisan::output());

        // 測試負數
        $exitCode = Artisan::call('summary_bet_record', [
            '--vendor' => '-1',
            '--prev-hour' => true,
            '--dry-run' => true,
        ]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--vendor 參數不能為負數', Artisan::output());
    }

    #[Test]
    public function test_date_parameter_validation_with_laravel_validator()
    {
        // 測試錯誤格式
        $exitCode = Artisan::call('summary_bet_record', [
            '--date' => '2025/12/30',
            '--dry-run' => true,
        ]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--date 參數格式錯誤，請使用 Y-m-d 格式', Artisan::output());

        // 測試過早日期
        $exitCode = Artisan::call('summary_bet_record', [
            '--date' => '2019-01-01',
            '--dry-run' => true,
        ]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--date 參數不能早於 2020-01-01', Artisan::output());

        // 測試過未來日期
        $futureDate = date('Y-m-d', strtotime('+2 years'));
        $exitCode = Artisan::call('summary_bet_record', [
            '--date' => $futureDate,
            '--dry-run' => true,
        ]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--date 參數不能超過一年後', Artisan::output());
    }

    #[Test]
    public function test_hour_parameter_validation_with_laravel_validator()
    {
        // 測試範圍外數值
        $exitCode = Artisan::call('summary_bet_record', [
            '--hour' => '25',
            '--dry-run' => true,
        ]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--hour 參數必須介於 0-23 之間', Artisan::output());

        // 測試非整數
        $exitCode = Artisan::call('summary_bet_record', [
            '--hour' => 'abc',
            '--dry-run' => true,
        ]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--hour 參數必須為整數', Artisan::output());

        // 測試負數
        $exitCode = Artisan::call('summary_bet_record', [
            '--hour' => '-1',
            '--dry-run' => true,
        ]);

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('--hour 參數必須介於 0-23 之間', Artisan::output());
    }

    #[Test]
    public function test_load_game_fee_rate_with_max_functions()
    {
        $this->createTestData();

        $runner = new SummaryBetRecordRunner;

        $reflection = new \ReflectionClass($runner);
        $method = $reflection->getMethod('loadGameFeeRate');
        $method->setAccessible(true);

        // 本測試專門驗證 loadGameFeeRate 的 GROUP BY 相容性，才臨時開啟
        // ONLY_FULL_GROUP_BY；其餘測試維持生產 session 實況（config 的 modes 已
        // 排除此 mode，調帳路徑 fetchAggregatedOriginRecords 等既有查詢無需 1055-safe，
        // 整類開啟會誤傷）。結束後還原，避免洩漏到同進程後續測試。
        DB::statement("SET sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        // 這應該不會拋出 ONLY_FULL_GROUP_BY 錯誤
        $this->expectNotToPerformAssertions();
        try {
            $method->invoke($runner);
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'ONLY_FULL_GROUP_BY')) {
                $this->fail('loadGameFeeRate() 仍然違反 ONLY_FULL_GROUP_BY 規則: ' . $e->getMessage());
            }
        } finally {
            DB::statement(sprintf("SET sql_mode = '%s'", implode(',', config('database.connections.mysql.modes'))));
        }
    }

    #[Test]
    public function test_valid_parameters_pass_laravel_validation()
    {
        $this->createTestData();

        $exitCode = Artisan::call('summary_bet_record', [
            '--date' => '2025-12-30',
            '--hour' => '14',
            '--vendor' => (string) VendorIdEnum::JOKER_BACCARAT_SW->value,
            '--dry-run' => true,
        ]);

        $this->assertNotEquals(1, $exitCode, '有效參數不應該因 Validator 驗證失敗');
    }

    #[Test]
    public function test_zero_vendor_parameter_represents_all_vendors()
    {
        $this->createTestData();

        $exitCode = Artisan::call('summary_bet_record', [
            '--vendor' => '0',
            '--prev-hour' => true,
            '--dry-run' => true,
        ]);

        $this->assertNotEquals(1, $exitCode, 'vendor=0 應該被識別為有效的全廠商執行');
    }

    #[Test]
    public function test_various_invalid_date_formats()
    {
        $invalidDates = [
            '20250101',      // 缺少分隔符
            '2025-1-1',      // 月日不足兩位數
            '2025-13-01',    // 無效月份
            '2025-02-30',    // 無效日期
            '25-01-01',      // 年份不足四位數
        ];

        foreach ($invalidDates as $invalidDate) {
            $exitCode = Artisan::call('summary_bet_record', [
                '--date' => $invalidDate,
                '--dry-run' => true,
            ]);

            $this->assertEquals(1, $exitCode, "日期格式 {$invalidDate} 應該被拒絕");
        }
    }

    #[Test]
    public function test_proper_exit_codes()
    {
        // 參數錯誤應回傳 1
        $exitCode = Artisan::call('summary_bet_record', [
            '--vendor' => 'invalid',
            '--dry-run' => true,
        ]);
        $this->assertEquals(1, $exitCode, '參數錯誤應回傳 exit code 1');

        // 未定義廠商應回傳 1
        $exitCode = Artisan::call('summary_bet_record', [
            '--vendor' => '999',
            '--dry-run' => true,
        ]);
        $this->assertEquals(1, $exitCode, '未定義廠商應回傳 exit code 1');
    }

    #[Test]
    public function test_repull_target_vendors_config_mapping_matches_expected(): void
    {
        Config::set('summary_bet_record', require config_path('summary_bet_record.php'));
        $configs = config('summary_bet_record');

        $this->assertNotEmpty($configs, 'summary_bet_record 設定不得為空');

        foreach ($configs as $vendorId => $config) {
            $this->assertIsInt($vendorId, 'vendor_id 必須是整數');
            $this->assertArrayHasKey('third_party_code', $config, "vendor_id={$vendorId} 缺少 third_party_code");
            $this->assertArrayHasKey('time_field', $config, "vendor_id={$vendorId} 缺少 time_field");
            $this->assertIsString($config['third_party_code']);
            $this->assertContains($config['time_field'], ['payout_time', 'bet_time'], "vendor_id={$vendorId} time_field 值不合法");
        }
    }

    #[Test]
    public function test_repull_command_uses_expected_time_field_for_target_vendors(): void
    {
        Config::set('summary_bet_record', require config_path('summary_bet_record.php'));
        $this->createTestData();

        $configs = config('summary_bet_record');

        foreach ($configs as $vendorId => $config) {
            $exitCode = Artisan::call('summary_bet_record', [
                '--date' => '2025-12-30',
                '--hour' => '14',
                '--vendor' => (string) $vendorId,
                '--dry-run' => true,
            ]);

            $output = Artisan::output();

            $this->assertNotSame(1, $exitCode, "重新拉帳命令不應在參數/流程驗證階段失敗 (vendor={$vendorId})");
            $this->assertStringContainsString('[VendorId ' . $vendorId . ']', $output);
            $this->assertMatchesRegularExpression(
                '/遊戲商代碼：\s*' . preg_quote($config['third_party_code'], '/') . '/u',
                $output,
            );
        }
    }

    /**
     * 廠商彙總鎖 key：粒度 = vendor × 小時，確保不同小時可並發、同 (vendor, 小時) 互斥。
     */
    #[Test]
    public function test_vendor_lock_key_is_per_vendor_per_hour(): void
    {
        $this->assertSame(
            'summary_bet_record_command_144_20260603_20',
            $this->invokeLockKey('vendorLockKey', 144, '2026-06-03 20:00:00'),
        );

        // 同廠商不同小時 → 不同 key（可並發）
        $this->assertNotSame(
            $this->invokeLockKey('vendorLockKey', 144, '2026-06-03 20:00:00'),
            $this->invokeLockKey('vendorLockKey', 144, '2026-06-03 21:00:00'),
        );

        // 同小時不同廠商 → 不同 key（互不阻擋）
        $this->assertNotSame(
            $this->invokeLockKey('vendorLockKey', 144, '2026-06-03 20:00:00'),
            $this->invokeLockKey('vendorLockKey', 83, '2026-06-03 20:00:00'),
        );
    }

    /**
     * team SP 鎖 key：粒度 = 台北日（DATE(StartTime +8h)），對齊 SP 對 vendor 3/10/23 的整日刪重建。
     *
     * 關鍵回歸點：同台北日、跨 UTC 日的兩個小時必須拿到同一把 key，否則整日 row 競態。
     */
    #[Test]
    public function test_team_lock_key_is_per_taipei_day(): void
    {
        // UTC 16:00 +8h → 台北 06-04 00:00；UTC 次日 00:00 +8h → 台北 06-04 08:00 → 同台北日
        $this->assertSame('summary_bet_record_team_20260604', $this->invokeLockKey('teamLockKey', '2026-06-03 16:00:00'));
        $this->assertSame('summary_bet_record_team_20260604', $this->invokeLockKey('teamLockKey', '2026-06-04 00:00:00'));

        // 上述兩個「跨 UTC 日但同台北日」的小時 → 必須同 key（序列化，防 3/10/23 整日 row 互砸）
        $this->assertSame(
            $this->invokeLockKey('teamLockKey', '2026-06-03 16:00:00'),
            $this->invokeLockKey('teamLockKey', '2026-06-04 00:00:00'),
        );

        // UTC 15:00 +8h → 台北 06-03 23:00 → 不同台北日 → 不同 key（可並發）
        $this->assertSame('summary_bet_record_team_20260603', $this->invokeLockKey('teamLockKey', '2026-06-03 15:00:00'));
    }

    /**
     * rolling chip SP 鎖 key：粒度 = UTC 小時（不可 +8h，足跡與 team SP 不同）。
     */
    #[Test]
    public function test_rolling_chip_lock_key_is_per_utc_hour(): void
    {
        $this->assertSame(
            'summary_rolling_chip_records_20260603_16',
            $this->invokeLockKey('rollingChipLockKey', '2026-06-03 16:00:00'),
        );

        // 同一時間點，rolling chip（UTC 小時）與 team（台北日 +8h）刻意不同界 → key 必不相同
        $this->assertNotSame(
            $this->invokeLockKey('rollingChipLockKey', '2026-06-03 16:00:00'),
            $this->invokeLockKey('teamLockKey', '2026-06-03 16:00:00'),
        );
    }

    /**
     * service 的 getLastStats() 在尚未 process 前回傳預設值（mode=none）。
     */
    #[Test]
    public function test_service_last_stats_defaults_to_none(): void
    {
        $service = new SummaryBetRecordService(
            new OutputStyle(new ArrayInput([]), new BufferedOutput)
        );

        $this->assertSame(
            ['mode' => 'none', 'written' => 0, 'reversed' => 0],
            $service->getLastStats(),
        );
    }

    /**
     * 命令對每個廠商記錄對稱的 start / end 日誌，且 message 自帶 [vendor:code] 與 lock key。
     */
    #[Test]
    public function test_command_emits_symmetric_start_and_end_logs(): void
    {
        $this->createTestData();
        // 取回 spy 實例再驗證：Log::shouldHaveReceived() 是 __callStatic 轉發，靜態分析看不到
        $logSpy = Log::spy();

        $vendorId = VendorIdEnum::JOKER_BACCARAT_SW->value;

        Artisan::call('summary_bet_record', [
            '--date' => '2025-12-30',
            '--hour' => '14',
            '--vendor' => (string) $vendorId,
            '--dry-run' => true,
        ]);

        // 第二個參數帶 Closure 等同 withArgs()，Mockery 內部已套用 atLeast()->once()
        $logSpy->shouldHaveReceived('info', fn ($message) => is_string($message)
            && str_starts_with($message, "summary_bet_record start [{$vendorId}:TEST_VENDOR]")
            && str_contains($message, 'lock='));

        $logSpy->shouldHaveReceived('info', fn ($message) => is_string($message)
            && str_starts_with($message, "summary_bet_record end [{$vendorId}:TEST_VENDOR]")
            && str_contains($message, 'mode='));
    }

    /**
     * 已關帳日補跑：24 個小時的調帳列都寫在 summary_time = 關帳時間同一格，
     * 彙總結算只能在全部小時寫完後以關帳時間執行一次，不得逐小時重跑 24 次。
     */
    #[Test]
    public function test_past_cutoff_day_runs_summary_procedures_once_after_all_hours(): void
    {
        $this->createTestData();
        $settlementRuns = $this->captureSummarySettlementRuns();

        $exitCode = Artisan::call('summary_bet_record', [
            '--date' => '2025-12-30',
            '--vendor' => (string) VendorIdEnum::JOKER_BACCARAT_SW->value,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());

        $cutoff = SummaryBetRecordService::getDailyCutoffTime();
        $this->assertSame([
            ['team', $cutoff],
            ['rolling_chip', $cutoff],
        ], $settlementRuns->all());
    }

    /**
     * 未關帳小時維持原行為：該小時彙總完即以本次 start 執行彙總結算。
     */
    #[Test]
    public function test_current_hour_runs_summary_procedures_with_its_own_start(): void
    {
        $this->createTestData();
        $settlementRuns = $this->captureSummarySettlementRuns();

        $nowUtc = now('UTC');
        $exitCode = Artisan::call('summary_bet_record', [
            '--date' => $nowUtc->format('Y-m-d'),
            '--hour' => (string) $nowUtc->hour,
            '--timezone' => 'UTC',
            '--vendor' => (string) VendorIdEnum::JOKER_BACCARAT_SW->value,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());

        $start = $nowUtc->format('Y-m-d H:00:00');
        $this->assertSame([
            ['team', $start],
            ['rolling_chip', $start],
        ], $settlementRuns->all());
    }

    /**
     * 攔截本次測試內彙總結算（team／滾碼 writer）的執行足跡（依執行順序）。
     *
     * 兩個 writer 的起手式都是對自身目標表的刪除重建，以該 DELETE 的
     * summary_time 綁定值還原「結算以哪個時間執行」。
     *
     * @return \Illuminate\Support\Collection<int, array{0: string, 1: string}>
     */
    private function captureSummarySettlementRuns(): \Illuminate\Support\Collection
    {
        $calls = collect();
        DB::listen(function ($query) use ($calls): void {
            if (str_starts_with($query->sql, 'DELETE FROM summary_bet_records_team WHERE summary_time = ? AND vendor_id NOT IN')) {
                $calls->push(['team', (string) $query->bindings[0]]);
            }

            if ($query->sql === 'DELETE FROM summary_rolling_chip_records WHERE summary_time = ?') {
                $calls->push(['rolling_chip', (string) $query->bindings[0]]);
            }
        });

        return $calls;
    }

    /**
     * 以反射呼叫 command 的私有鎖 key builder（純字串運算，不需 DB）。
     */
    /**
     * --timezone 決定「輸入的日期／小時」如何換算成 UTC 時間範圍；預設 +08:00 維持既有行為。
     */
    #[Test]
    public function test_timezone_option_shifts_the_generated_utc_ranges(): void
    {
        // 預設（UTC+8）：台北 06-04 整天 = UTC 06-03 16:00 ～ 06-04 16:00
        $taipeiRanges = $this->invokeParseArguments(['--date' => '2026-06-04']);
        $this->assertCount(24, $taipeiRanges);
        $this->assertSame(['2026-06-03 16:00:00', '2026-06-03 17:00:00'], $taipeiRanges[0]);
        $this->assertSame(['2026-06-04 15:00:00', '2026-06-04 16:00:00'], $taipeiRanges[23]);

        // --timezone=UTC：整天即 UTC 06-04 00:00 ～ 24:00
        $utcRanges = $this->invokeParseArguments(['--date' => '2026-06-04', '--timezone' => 'UTC']);
        $this->assertCount(24, $utcRanges);
        $this->assertSame(['2026-06-04 00:00:00', '2026-06-04 01:00:00'], $utcRanges[0]);
        $this->assertSame(['2026-06-04 23:00:00', '2026-06-05 00:00:00'], $utcRanges[23]);

        // 指定小時同樣依時區換算
        $this->assertSame(
            [['2026-06-04 00:00:00', '2026-06-04 01:00:00']],
            $this->invokeParseArguments(['--date' => '2026-06-04', '--hour' => '8']),
        );
        $this->assertSame(
            [['2026-06-04 08:00:00', '2026-06-04 09:00:00']],
            $this->invokeParseArguments(['--date' => '2026-06-04', '--hour' => '8', '--timezone' => 'UTC']),
        );
    }

    /**
     * schedule_enabled = false 的廠商不隨全廠商排程執行，但明確指定 --vendor 時要照跑。
     */
    #[Test]
    public function test_schedule_disabled_vendors_are_skipped_only_on_all_vendor_runs(): void
    {
        $configs = [
            // 缺鍵預設納入排程
            10 => ['third_party_code' => 'A', 'time_field' => 'payout_time'],
            20 => ['third_party_code' => 'B', 'time_field' => 'bet_time', 'schedule_enabled' => false],
            30 => ['third_party_code' => 'C', 'time_field' => 'bet_time', 'schedule_enabled' => true],
        ];

        // 全廠商（--vendor 未指定 / 0）：只跳過 schedule_enabled = false
        $this->assertSame([10, 30], array_keys($this->invokeVendorsForRun($configs, 0)));

        // 明確指定未納入排程的廠商：照跑
        $this->assertSame([20], array_keys($this->invokeVendorsForRun($configs, 20)));
    }

    /**
     * 以指定 vendorId 呼叫私有的 vendorsForRun()。
     *
     * @param  array<int, array<string, mixed>>  $configs
     * @return array<int, array<string, mixed>>
     */
    private function invokeVendorsForRun(array $configs, int $vendorId): array
    {
        $command = new SummaryBetRecordCommand;

        $vendorIdProperty = new \ReflectionProperty($command, 'vendorId');
        $vendorIdProperty->setAccessible(true);
        $vendorIdProperty->setValue($command, $vendorId);

        $method = new \ReflectionMethod($command, 'vendorsForRun');
        $method->setAccessible(true);

        return $method->invoke($command, $configs);
    }

    /**
     * 以指定參數跑 parseArguments，取回組出的 UTC 時間範圍清單。
     *
     * @param  array<string, string>  $options
     * @return array<int, array{0: string, 1: string}>
     */
    private function invokeParseArguments(array $options): array
    {
        $command = new SummaryBetRecordCommand;
        $command->setLaravel($this->app);
        $command->setInput(new ArrayInput($options, $command->getDefinition()));

        $parse = new \ReflectionMethod($command, 'parseArguments');
        $parse->setAccessible(true);
        $parse->invoke($command);

        $ranges = new \ReflectionProperty($command, 'timeRanges');
        $ranges->setAccessible(true);

        return $ranges->getValue($command);
    }

    private function invokeLockKey(string $method, int|string ...$args): string
    {
        // 鎖 key builder 已抽至 SummaryBetRecordRunner（CLI 與 queue job 共用）
        $runner = new SummaryBetRecordRunner;
        $ref = new \ReflectionMethod($runner, $method);
        $ref->setAccessible(true);

        return $ref->invoke($runner, ...$args);
    }

    private function createTestData(): void
    {
        $agent = Agent::factory()->create([
            'customized_setting_group_id' => 1,
            'organization_id' => 1,
        ]);

        $vendor = Vendor::factory()->create(['id' => VendorIdEnum::JOKER_BACCARAT_SW->value]);

        $vendorInfo = VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::JOKER_BACCARAT_SW->value,
            'cost' => 0.05,
        ]);

        AgentVendor::create([
            'agent_id' => $agent->id,
            'vendor_id' => VendorIdEnum::JOKER_BACCARAT_SW->value,
            'vendor_info_id' => $vendorInfo->id,
            'status' => 1,
            'self_status' => 1,
            'admin' => 0,
        ]);

        Game::factory()->create([
            'vendor_id' => VendorIdEnum::JOKER_BACCARAT_SW->value,
            'valid_bet_rate' => 1,
        ]);

        // 建立 V-Jackpot 設定
        AgentCustomizedSetting::create([
            'agent_group_id' => 1,
            'setting_key' => AgentCustomizedSettingKeyEnum::V_JACKPOT_RATE,
            'value' => json_encode(['DEFAULT' => 0.003]),
            'sub_category' => 1,
            'memo' => 'test',
        ]);
    }
}
