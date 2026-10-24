<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\SummaryBetRecord;

use App\Console\Commands\SummaryBetRecord\SummaryBetRecordRunner;
use App\Console\Commands\SummaryBetRecord\SummaryBetRecordService;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\QueueJobRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * SummaryBetRecordRunner 完成標記（queue_job_runs，job_key=summary_bet_record）行為。
 *
 * 完成標記是 queue 回看補洞（SummarizeBetRecordHour）的判定依據：
 * 一列＝該 vendor × hour bucket 已成功彙總，與 SBR 寫入同一交易提交。
 *
 * php artisan test tests/Unit/Console/Commands/SummaryBetRecord/SummaryBetRecordRunnerTest.php
 */
class SummaryBetRecordRunnerTest extends TestCase
{
    use DatabaseTransactions;

    private const string BUCKET_START = '2026-08-28 11:00:00';

    private const string BUCKET_END = '2026-08-28 12:00:00';

    /** @var array{third_party_code: string, time_field: string} */
    private const array VENDOR_CONFIG = [
        'third_party_code' => 'TEST_VENDOR',
        'time_field' => 'payout_time',
    ];

    #[Test]
    public function test_run_vendor_bucket_marks_completion_in_same_transaction(): void
    {
        $vendorId = VendorIdEnum::JOKER_BACCARAT_SW->value;

        $runner = new SummaryBetRecordRunner;
        $runner->prepareSharedData();
        $runner->runVendorBucket(
            vendorId: $vendorId,
            config: self::VENDOR_CONFIG,
            start: self::BUCKET_START,
            end: self::BUCKET_END,
            source: QueueJobRunSourceEnum::SCHEDULE,
        );

        $run = $this->sbrRuns()->where('work_key', (string) $vendorId)->first();
        $this->assertNotNull($run, '成功彙總後必須寫入完成標記');
        $this->assertSame(QueueJobKeyEnum::SUMMARY_BET_RECORD->value, $run->job_key);
        $this->assertSame(self::BUCKET_START, $run->bucket_time->format('Y-m-d H:i:s'));
        $this->assertSame(QueueJobRunSourceEnum::SCHEDULE->value, $run->source);
    }

    #[Test]
    public function test_dry_run_does_not_mark_completion(): void
    {
        $vendorId = VendorIdEnum::JOKER_BACCARAT_SW->value;

        $runner = new SummaryBetRecordRunner;
        $runner->prepareSharedData();
        $runner->runVendorBucket(
            vendorId: $vendorId,
            config: self::VENDOR_CONFIG,
            start: self::BUCKET_START,
            end: self::BUCKET_END,
            dryRun: true,
        );

        $this->assertSame(
            0,
            $this->sbrRuns()->where('work_key', (string) $vendorId)->count(),
            'dry-run 不得留下完成標記，否則回看補洞會誤判該 bucket 已彙總',
        );
    }

    #[Test]
    #[Group('ticket-4416')]
    #[Group('ac-2 [自動] 某一小時已經有排程自己完成的紀錄時，整點排程會照舊跳過，不會重複計算')]
    #[TestDox('補洞模式下，已有排程完成標記的格子取鎖後直接跳過，不重算也不新增標記')]
    public function test_skip_if_completed_short_circuits_without_reprocessing(): void
    {
        $vendorId = VendorIdEnum::JOKER_BACCARAT_SW->value;

        QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: self::BUCKET_START, source: QueueJobRunSourceEnum::SCHEDULE);

        $runner = new SummaryBetRecordRunner;
        $runner->prepareSharedData();
        $stats = $runner->runVendorBucket(
            vendorId: $vendorId,
            config: self::VENDOR_CONFIG,
            start: self::BUCKET_START,
            end: self::BUCKET_END,
            source: QueueJobRunSourceEnum::BACKFILL,
            skipIfCompleted: true,
        );

        $this->assertSame('skipped', $stats['mode'], '已有完成標記時應跳過重算');

        // 標記維持原樣：來源不被覆寫成 backfill
        $run = $this->sbrRuns()->where('work_key', (string) $vendorId)->first();
        $this->assertSame(QueueJobRunSourceEnum::SCHEDULE->value, $run->source);
        $this->assertSame(1, $this->sbrRuns()->where('work_key', (string) $vendorId)->count());
    }

    #[Group('ticket-4416')]
    #[Group('ac-1 [自動] 某一小時只有手動補跑的紀錄時，下一個整點排程會自動重算這一小時')]
    #[TestDox('補洞模式下，只有手動補跑的標記不算完成，照樣重算並補上補洞標記')]
    public function test_skip_if_completed_ignores_manual_marks(): void
    {
        $vendorId = VendorIdEnum::JOKER_BACCARAT_SW->value;

        QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: self::BUCKET_START, source: QueueJobRunSourceEnum::MANUAL);

        $runner = new SummaryBetRecordRunner;
        $runner->prepareSharedData();
        $stats = $runner->runVendorBucket(
            vendorId: $vendorId,
            config: self::VENDOR_CONFIG,
            start: self::BUCKET_START,
            end: self::BUCKET_END,
            source: QueueJobRunSourceEnum::BACKFILL,
            skipIfCompleted: true,
        );

        $this->assertNotSame('skipped', $stats['mode']);
        $this->assertEqualsCanonicalizing(
            [QueueJobRunSourceEnum::MANUAL->value, QueueJobRunSourceEnum::BACKFILL->value],
            $this->sbrRuns()->where('work_key', (string) $vendorId)->pluck('source')->all(),
        );
    }

    #[Test]
    public function test_rerun_without_skip_adds_a_new_mark_row(): void
    {
        $vendorId = VendorIdEnum::JOKER_BACCARAT_SW->value;

        QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: self::BUCKET_START, source: QueueJobRunSourceEnum::SCHEDULE);

        $runner = new SummaryBetRecordRunner;
        $runner->prepareSharedData();
        $stats = $runner->runVendorBucket(
            vendorId: $vendorId,
            config: self::VENDOR_CONFIG,
            start: self::BUCKET_START,
            end: self::BUCKET_END,
            source: QueueJobRunSourceEnum::MANUAL,
        );

        $this->assertNotSame('skipped', $stats['mode'], '未要求 skip 時（手動補跑）必須照常重算');

        // 一次執行一列：重算另立新列（列即重算軌跡），原排程那列不被覆寫
        $rows = $this->sbrRuns()->where('work_key', (string) $vendorId)->orderBy('run_uuid')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(
            [QueueJobRunSourceEnum::SCHEDULE->value, QueueJobRunSourceEnum::MANUAL->value],
            $rows->pluck('source')->all(),
        );
    }

    #[Test]
    public function test_vendor_failure_does_not_mark_completion_and_rethrows(): void
    {
        $vendorId = VendorIdEnum::JOKER_BACCARAT_SW->value;

        $runner = new SummaryBetRecordRunner;
        $runner->prepareSharedData();

        try {
            $runner->runVendorBucket(
                vendorId: $vendorId,
                config: [...self::VENDOR_CONFIG, 'service_class' => ThrowingAggregateSummaryBetRecordService::class],
                start: self::BUCKET_START,
                end: self::BUCKET_END,
                source: QueueJobRunSourceEnum::SCHEDULE,
            );
            $this->fail('彙總中的 DB 例外（deadlock 等）必須上拋，否則 job 無從觸發 retry');
        } catch (QueryException) {
            // 預期路徑：例外不得被 Service 吞掉
        }

        $this->assertSame(
            0,
            $this->sbrRuns()->where('work_key', (string) $vendorId)->count(),
            '彙總失敗不得留下完成標記，否則回看補洞會把失敗格永久視為已完成',
        );
    }

    /**
     * 本測試只看 SBR 的標記列：表已通用化，斷言一律帶 job_key 過濾，
     * 免得第二個 job 接入後被別的 job 的列帶歪。
     *
     * @return Builder<QueueJobRun>
     */
    private function sbrRuns(): Builder
    {
        return QueueJobRun::query()->where('job_key', QueueJobKeyEnum::SUMMARY_BET_RECORD->value);
    }
}

/**
 * 模擬彙總途中 DB 例外（deadlock／lock wait timeout）的 service：
 * 讓真實 SummaryBetRecordService::process() 的 catch 路徑被走到，
 * 驗證例外會上拋、不因被吞掉而寫入完成標記。
 */
class ThrowingAggregateSummaryBetRecordService extends SummaryBetRecordService
{
    protected function buildAggregateQuery(string $start, string $end): Collection
    {
        throw new QueryException('mysql', 'select 1', [], new RuntimeException('deadlock simulation'));
    }
}
