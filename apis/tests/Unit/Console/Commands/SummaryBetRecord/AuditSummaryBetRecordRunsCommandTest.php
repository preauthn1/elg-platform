<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\SummaryBetRecord;

use App\Console\Commands\SummaryBetRecord\AuditSummaryBetRecordRunsCommand;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Models\QueueJobRun;
use App\Models\Vendor;
use App\Services\BotNotifyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * summary_bet_record:audit：完成標記缺口稽核（target/tickets#4257 收尾）。
 *
 * 回看補洞只涵蓋近 6 小時；排程／佇列整條靜默中斷超過視窗時，缺格永久留下
 * 且無告警。本命令直接掃 queue_job_runs（job_key=summary_bet_record）判缺——不依賴 Redis／Horizon，
 * 是唯一能偵測「整條佇列掛掉」的防線。
 *
 * php artisan test tests/Unit/Console/Commands/SummaryBetRecord/AuditSummaryBetRecordRunsCommandTest.php
 */
class AuditSummaryBetRecordRunsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const int ENABLED_VENDOR_A = 10;

    private const int ENABLED_VENDOR_B = 30;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('summary_bet_record', [
            self::ENABLED_VENDOR_A => ['third_party_code' => 'A', 'time_field' => 'payout_time'],
            20 => ['third_party_code' => 'B', 'time_field' => 'bet_time', 'schedule_enabled' => false],
            // B 刻意設非預設對帳時區：鎖住「缺口日期依各商 reconcile_timezone 換算」的行為
            self::ENABLED_VENDOR_B => ['third_party_code' => 'C', 'time_field' => 'payout_time', 'reconcile_timezone' => -4],
        ]);
    }

    #[Test]
    public function test_complete_window_passes_without_notify(): void
    {
        // 視窗前後各多鋪 1 小時：測試與命令各自取 now，跨整點時不致出現假缺口
        foreach ($this->bucketsWithMargin(hours: 6) as $bucket) {
            foreach ([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B] as $vendorId) {
                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $bucket, source: QueueJobRunSourceEnum::SCHEDULE);
            }
        }

        $this->mock(BotNotifyService::class)->shouldNotReceive('send');

        $exitCode = Artisan::call('summary_bet_record:audit', ['--hours' => 6]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    #[Group('ticket-4416')]
    #[Group('ac-4 [自動] 每日缺口稽核仍然把手動補跑算成已補上，不會誤報缺口')]
    #[TestDox('視窗內有手動補跑的標記時，稽核把它算成已補上，不通知也不失敗')]
    public function test_manual_marks_count_as_filled(): void
    {
        foreach ($this->bucketsWithMargin(hours: 6) as $bucket) {
            QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: self::ENABLED_VENDOR_A, bucketTime: $bucket, source: QueueJobRunSourceEnum::SCHEDULE);
            QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: self::ENABLED_VENDOR_B, bucketTime: $bucket, source: QueueJobRunSourceEnum::MANUAL);
        }

        $this->mock(BotNotifyService::class)->shouldNotReceive('send');

        $exitCode = Artisan::call('summary_bet_record:audit', ['--hours' => 6]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    #[Test]
    public function test_missing_pair_notifies_and_fails(): void
    {
        $buckets = $this->bucketsWithMargin(hours: 6);
        // 缺口挑視窗中段，避免測試與命令取 now 的秒差造成邊界誤判
        $missingBucket = $buckets[(int) floor(count($buckets) / 2)];

        foreach ($buckets as $bucket) {
            foreach ([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B] as $vendorId) {
                if ($bucket === $missingBucket && $vendorId === self::ENABLED_VENDOR_B) {
                    continue;
                }

                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $bucket, source: QueueJobRunSourceEnum::SCHEDULE);
            }
        }

        // 告警印 vendors.name（後台選單同一個名字），不是 id 也不是 third_party_code
        if (!Vendor::query()->whereKey(self::ENABLED_VENDOR_B)->exists()) {
            Vendor::factory()->create(['id' => self::ENABLED_VENDOR_B, 'name' => 'PlayTech']);
        }

        // 補跑走後台、粒度是整天，所以告警報的是「遊戲商（時區）：日期」而非 UTC 小時
        $expected = sprintf(
            'PlayTech (%d, -04:00) : %s',
            self::ENABLED_VENDOR_B,
            Carbon::parse($missingBucket)->addHours(-4)->toDateString(),
        );

        $this->mock(BotNotifyService::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, $expected)
                && !str_contains($message, $missingBucket));

        $exitCode = Artisan::call('summary_bet_record:audit', ['--hours' => 6]);

        $this->assertSame(1, $exitCode, '存在缺口時必須以失敗狀態結束，讓 command_logs 留下可稽核紀錄');
        $this->assertStringContainsString($expected, Artisan::output());
    }

    #[Test]
    public function test_disabled_vendor_not_audited(): void
    {
        // 只鋪啟用廠商的標記；schedule_enabled=false 的廠商從未寫標記，不得被判缺
        foreach ($this->bucketsWithMargin(hours: 6) as $bucket) {
            foreach ([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B] as $vendorId) {
                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $bucket, source: QueueJobRunSourceEnum::SCHEDULE);
            }
        }

        $this->mock(BotNotifyService::class)->shouldNotReceive('send');

        $this->assertSame(0, Artisan::call('summary_bet_record:audit', ['--hours' => 6]));
    }

    /**
     * 命令稽核視窗的 bucket 清單，前後各加 1 小時緩衝。
     *
     * @return array<int, string>
     */
    private function bucketsWithMargin(int $hours): array
    {
        $buckets = [];
        $lag = AuditSummaryBetRecordRunsCommand::SAFE_LAG_HOURS;

        for ($i = $lag - 1; $i < $lag + $hours + 1; $i++) {
            $buckets[] = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
        }

        return $buckets;
    }
}
