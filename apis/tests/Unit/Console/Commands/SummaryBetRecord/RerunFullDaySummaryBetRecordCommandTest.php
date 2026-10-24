<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\SummaryBetRecord;

use App\Console\Commands\SummaryBetRecord\RerunFullDaySummaryBetRecordCommand;
use App\Enums\Vendor\VendorIdEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * summary_bet_record:rerun-full-day：以對帳時區重跑前一整天彙總的步驟規劃與逐商序列執行（target/tickets#4357）。
 *
 * php artisan test tests/Unit/Console/Commands/SummaryBetRecord/RerunFullDaySummaryBetRecordCommandTest.php
 */
class RerunFullDaySummaryBetRecordCommandTest extends TestCase
{
    /** 排程觸發時刻：UTC 00:25 = UTC+8 08:25（UTC 日界之後） */
    private const string SCHEDULE_TICK_UTC = '2026-09-15 00:25:00';

    #[Test]
    public function test_single_wallet_only_reruns_summary_for_yesterday_in_reconcile_timezone(): void
    {
        $steps = RerunFullDaySummaryBetRecordCommand::steps(
            vendorId: 145,
            config: ['reconcile_timezone' => -4],
            isSingleWallet: true,
            nowUtc: CarbonImmutable::parse(self::SCHEDULE_TICK_UTC, 'UTC'),
        );

        // UTC-4 當下為 09-14 20:05，昨天 = 09-13
        $this->assertSame([
            ['summary_bet_record', ['--vendor' => '145', '--date' => '2026-09-13', '--timezone' => '-04:00']],
        ], $steps);
    }

    #[Test]
    public function test_transfer_wallet_fetches_utc_window_before_summary(): void
    {
        $steps = RerunFullDaySummaryBetRecordCommand::steps(
            vendorId: 253,
            config: [],
            isSingleWallet: false,
            nowUtc: CarbonImmutable::parse(self::SCHEDULE_TICK_UTC, 'UTC'),
        );

        // 未設對帳時區 = UTC+8：當下 09-15 08:25，昨天 = 09-14，UTC 窗口 09-13 16:00 ～ 09-14 16:00
        $this->assertSame([
            ['fetch_bet_record', ['--vendor' => '253', '--start' => '2026-09-13@16', '--end' => '2026-09-14@16', '--direct' => true, '--update' => true]],
            ['summary_bet_record', ['--vendor' => '253', '--date' => '2026-09-14', '--timezone' => '+08:00']],
        ], $steps);
    }

    #[Test]
    public function test_utc_vendor_at_schedule_tick_reruns_the_same_day_as_utc8_vendors(): void
    {
        $steps = RerunFullDaySummaryBetRecordCommand::steps(
            vendorId: 54,
            config: ['reconcile_timezone' => 0],
            isSingleWallet: false,
            nowUtc: CarbonImmutable::parse(self::SCHEDULE_TICK_UTC, 'UTC'),
        );

        // UTC 09-14 已在 00:00 結束，昨天 = 09-14 整天，與 +8 時區遊戲商重跑同一天
        $this->assertSame('2026-09-14@00', $steps[0][1]['--start']);
        $this->assertSame('2026-09-15@00', $steps[0][1]['--end']);
        $this->assertSame(['--vendor' => '54', '--date' => '2026-09-14', '--timezone' => 'UTC'], $steps[1][1]);
    }

    #[Test]
    public function test_rerun_targets_come_from_config_flag(): void
    {
        $targets = RerunFullDaySummaryBetRecordCommand::rerunTargets(require config_path('summary_bet_record.php'));

        $this->assertSame([VendorIdEnum::BWG->value, VendorIdEnum::PPPOP->value], array_keys($targets));
    }

    #[Test]
    public function test_runs_targets_in_order_and_continues_after_a_vendor_fails(): void
    {
        $calls = $this->stubStepCommands(failVendor: '20');

        Config::set('summary_bet_record', [
            10 => ['third_party_code' => 'A', 'time_field' => 'bet_time'],
            20 => ['third_party_code' => 'B', 'time_field' => 'bet_time', 'next_day_full_rerun' => true, 'reconcile_timezone' => 0],
            30 => ['third_party_code' => 'C', 'time_field' => 'bet_time', 'next_day_full_rerun' => true],
        ]);

        // 20 失敗不中斷 30，整體回非 0 讓 ScheduleProvider 發排程失敗告警
        // （不驗輸出文字：步驟命令帶自己的 BufferedOutput，會把 Artisan::output() 的 lastOutput 換掉）
        $this->assertSame(1, Artisan::call('summary_bet_record:rerun-full-day'));

        // 20 的彙總失敗即停（不再有後續步驟），30 照常從拉注單跑到彙總
        $this->assertSame([
            'fetch_bet_record:20',
            'summary_bet_record:20',
            'fetch_bet_record:30',
            'summary_bet_record:30',
        ], $calls->all());
    }

    #[Test]
    public function test_no_targets_exits_successfully_without_running_anything(): void
    {
        $calls = $this->stubStepCommands();
        Config::set('summary_bet_record', [10 => ['third_party_code' => 'A', 'time_field' => 'bet_time']]);

        $this->assertSame(0, Artisan::call('summary_bet_record:rerun-full-day'));
        $this->assertStringContainsString('沒有設定 next_day_full_rerun', Artisan::output());
        $this->assertSame([], $calls->all());
    }

    /**
     * 以同名 closure 命令頂替兩支步驟命令，只記錄呼叫、不碰 DB。
     *
     * @param  string|null  $failVendor  該 vendor 的 summary_bet_record 回非 0
     * @return Collection<int, string> 呼叫順序（`<命令>:<vendor>`），由測試讀取
     */
    private function stubStepCommands(?string $failVendor = null): Collection
    {
        $calls = new Collection;

        Artisan::command('fetch_bet_record {--vendor=} {--start=} {--end=} {--direct} {--update}', function () use ($calls): int {
            $calls->push('fetch_bet_record:' . $this->option('vendor'));

            return 0;
        });

        Artisan::command('summary_bet_record {--vendor=} {--date=} {--timezone=}', function () use ($calls, $failVendor): int {
            $calls->push('summary_bet_record:' . $this->option('vendor'));

            return $this->option('vendor') === $failVendor ? 1 : 0;
        });

        return $calls;
    }
}
