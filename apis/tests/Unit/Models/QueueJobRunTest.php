<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\QueueJobRunStatusEnum;
use App\Models\QueueJobRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * QueueJobRun 通用完成標記的 job_key 隔離契約。
 *
 * 表通用化後（MR !2080 審查意見），isCompleted／missingPairs／resetMarks 的
 * job_key 過濾是唯一把各 job 的標記隔開的機制——把 helper 裡的
 * `where('job_key', ...)` 拔掉，SBR 專屬測試仍會全綠（目前只有一個 job），
 * 但第二個 job 接入後會互判完成／互刪標記造成靜默漏格。本測試以另一個
 * job_key 的同 (work_key, bucket) 列鎖住隔離語意。
 *
 * php artisan test tests/Unit/Models/QueueJobRunTest.php
 */
class QueueJobRunTest extends TestCase
{
    use DatabaseTransactions;

    private const string BUCKET = '2026-08-28 11:00:00';

    private const int WORK_KEY = 10;

    /** 模擬未來第二個 job：寫入路徑不受 Enum 限制，直接以字串塞列即可 */
    private const string OTHER_JOB_KEY = 'other_job_for_isolation_test';

    private function createOtherJobMark(): void
    {
        QueueJobRun::create([
            'job_key' => self::OTHER_JOB_KEY,
            'work_key' => (string) self::WORK_KEY,
            'bucket_time' => self::BUCKET,
            'source' => QueueJobRunSourceEnum::SCHEDULE->value,
        ]);
    }

    #[Test]
    public function test_is_completed_does_not_see_other_job_marks(): void
    {
        $this->createOtherJobMark();

        $this->assertFalse(
            QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET),
            '別的 job 的同 (work_key, bucket) 標記不得被判為本 job 已完成，否則本 job 該格永久靜默漏跑',
        );

        QueueJobRun::markCompleted(
            jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD,
            workKey: self::WORK_KEY,
            bucketTime: self::BUCKET,
            source: QueueJobRunSourceEnum::SCHEDULE,
        );

        $this->assertTrue(
            QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET),
        );
    }

    #[Test]
    public function test_missing_pairs_does_not_see_other_job_marks(): void
    {
        $this->createOtherJobMark();

        $missing = QueueJobRun::missingPairs(QueueJobKeyEnum::SUMMARY_BET_RECORD, [self::BUCKET], [self::WORK_KEY]);

        $this->assertSame(
            [self::BUCKET => [self::WORK_KEY]],
            $missing,
            '判缺必須以 job_key 隔離：別的 job 完成不代表本 job 完成',
        );
    }

    #[Test]
    public function test_reset_marks_does_not_delete_other_job_marks(): void
    {
        $this->createOtherJobMark();
        QueueJobRun::markCompleted(
            jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD,
            workKey: self::WORK_KEY,
            bucketTime: self::BUCKET,
            source: QueueJobRunSourceEnum::SCHEDULE,
        );

        $deleted = QueueJobRun::resetMarks(QueueJobKeyEnum::SUMMARY_BET_RECORD, [self::BUCKET], [self::WORK_KEY]);

        $this->assertSame(1, $deleted, '只能刪到本 job 的標記');
        $this->assertSame(
            1,
            QueueJobRun::where('job_key', self::OTHER_JOB_KEY)->count(),
            '重置本 job 的標記不得誤刪其他 job 的同 (work_key, bucket) 列',
        );
    }

    #[TestDox('markCompleted 同格重跑各自成列（重算軌跡），isCompleted 判定不受列數影響')]
    public function test_mark_completed_keeps_one_row_per_run(): void
    {
        foreach ([QueueJobRunSourceEnum::SCHEDULE, QueueJobRunSourceEnum::BACKFILL, QueueJobRunSourceEnum::MANUAL] as $source) {
            QueueJobRun::markCompleted(
                jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD,
                workKey: self::WORK_KEY,
                bucketTime: self::BUCKET,
                source: $source,
            );
        }

        $rows = QueueJobRun::where('job_key', QueueJobKeyEnum::SUMMARY_BET_RECORD->value)
            ->where('work_key', self::WORK_KEY)
            ->where('bucket_time', self::BUCKET)
            ->orderBy('run_uuid')
            ->get();

        // 一次執行一列，各列保留自己的 source——關帳調帳強制重算時，列即重算軌跡
        $this->assertCount(3, $rows);
        $this->assertCount(3, $rows->pluck('run_uuid')->unique(), 'run_uuid 逐列相異');
        $this->assertNotContains('', $rows->pluck('run_uuid')->all(), '每列都要有 run_uuid');
        $this->assertSame(
            [QueueJobRunSourceEnum::SCHEDULE->value, QueueJobRunSourceEnum::BACKFILL->value, QueueJobRunSourceEnum::MANUAL->value],
            $rows->pluck('source')->all(),
            'UUIDv7 時序遞增，依 run_uuid 排序即執行先後',
        );

        $this->assertTrue(QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET));
    }

    #[TestDox('createPending 每次派發各自一列（未處理），run_uuid 逐列相異')]
    public function test_create_pending_creates_one_row_per_dispatch(): void
    {
        $ids = [];
        foreach (range(1, 3) as $ignored) {
            $ids[] = QueueJobRun::createPending(
                jobKey: QueueJobKeyEnum::UPDATE_GAME_LIST,
                workKey: self::WORK_KEY,
                bucketTime: self::BUCKET,
                source: QueueJobRunSourceEnum::SCHEDULE,
            );
        }

        $rows = QueueJobRun::where('job_key', QueueJobKeyEnum::UPDATE_GAME_LIST->value)
            ->where('work_key', self::WORK_KEY)
            ->where('bucket_time', self::BUCKET)
            ->get();

        $this->assertCount(3, $rows);
        $this->assertCount(3, array_unique($rows->pluck('run_uuid')->all()), 'run_uuid 必須逐次相異');
        $this->assertSame([QueueJobRunStatusEnum::PENDING], $rows->pluck('status')->unique()->all());
        $this->assertCount(3, array_unique($ids));
    }

    #[TestDox('finishRun 把未處理列收尾為成功／失敗並寫入 output；已收尾的列不被覆寫')]
    public function test_finish_run_updates_pending_row_once(): void
    {
        $id = QueueJobRun::createPending(QueueJobKeyEnum::UPDATE_GAME_LIST, self::WORK_KEY, self::BUCKET, QueueJobRunSourceEnum::SCHEDULE);

        QueueJobRun::finishRun($id, success: true, output: '新增 3 款');
        // timeout 邊界：finish 後 failed() hook 再進來，不得把成功改成失敗
        QueueJobRun::finishRun($id, success: false, output: 'job failed');

        $row = QueueJobRun::find($id);
        $this->assertSame(QueueJobRunStatusEnum::SUCCESS, $row->status);
        $this->assertSame('新增 3 款', $row->output);

        // null 防禦：不炸也不動任何列
        QueueJobRun::finishRun(null, success: false);
    }

    #[TestDox('超長輸出截斷後仍能收尾寫入，不因欄位上限讓收尾本身失敗')]
    public function test_finish_run_truncates_oversized_output(): void
    {
        $id = QueueJobRun::createPending(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, QueueJobRunSourceEnum::MANUAL);

        QueueJobRun::finishRun($id, success: true, output: str_repeat('遊', 800_000));

        $run = QueueJobRun::find($id);
        $this->assertSame(QueueJobRunStatusEnum::SUCCESS, $run->status);
        $this->assertLessThanOrEqual(1_000_100, strlen($run->output));
        // 頭尾各留一半：尾段是逐人指令的收尾摘要，不可被砍掉
        $this->assertStringContainsString('（輸出過長，中段已截斷；完整內容見 Log）', $run->output);
        $this->assertStringStartsWith('遊', $run->output);
        $this->assertStringEndsWith('遊', $run->output);
    }

    #[TestDox('判缺只認成功列：未處理與失敗列不算已完成')]
    public function test_incompleted_statuses_are_not_counted_as_done(): void
    {
        $id = QueueJobRun::createPending(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, QueueJobRunSourceEnum::SCHEDULE);

        $this->assertFalse(QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET), '未處理列不得被判為已完成，否則判缺補洞會靜默漏補');
        $this->assertSame(
            [self::BUCKET => [self::WORK_KEY]],
            QueueJobRun::missingPairs(QueueJobKeyEnum::SUMMARY_BET_RECORD, [self::BUCKET], [self::WORK_KEY]),
        );

        QueueJobRun::finishRun($id, success: false, output: 'boom');
        $this->assertFalse(QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET), '失敗列不得被判為已完成');

        QueueJobRun::markCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, QueueJobRunSourceEnum::SCHEDULE);
        $this->assertTrue(QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET));
    }

    #[Group('ticket-4416')]
    #[Group('ac-1 [自動] 某一小時只有手動補跑的紀錄時，下一個整點排程會自動重算這一小時')]
    #[Group('ac-4 [自動] 每日缺口稽核仍然把手動補跑算成已補上，不會誤報缺口')]
    #[TestDox('排除手動來源時，只有手動補跑的成功列不算已完成；不排除時照樣算完成')]
    public function test_excluded_sources_are_not_counted_as_done(): void
    {
        QueueJobRun::markCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, QueueJobRunSourceEnum::MANUAL);
        $excluded = [QueueJobRunSourceEnum::MANUAL];

        $this->assertFalse(QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, $excluded));
        $this->assertSame(
            [self::BUCKET => [self::WORK_KEY]],
            QueueJobRun::missingPairs(QueueJobKeyEnum::SUMMARY_BET_RECORD, [self::BUCKET], [self::WORK_KEY], $excluded),
        );

        $this->assertTrue(QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET));
        $this->assertSame([], QueueJobRun::missingPairs(QueueJobKeyEnum::SUMMARY_BET_RECORD, [self::BUCKET], [self::WORK_KEY]));

        QueueJobRun::markCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, QueueJobRunSourceEnum::SCHEDULE);
        $this->assertTrue(QueueJobRun::isCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, $excluded));
    }

    #[Group('ticket-4416')]
    #[Group('ac-3 [自動] 結算失敗、系統重置紀錄準備重算時，手動補跑的紀錄會被保留下來，不會被刪除')]
    #[TestDox('重置完成標記時保留被排除來源的列，只刪其餘來源的列')]
    public function test_reset_marks_keeps_excluded_source_rows(): void
    {
        QueueJobRun::markCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, QueueJobRunSourceEnum::MANUAL);
        QueueJobRun::markCompleted(QueueJobKeyEnum::SUMMARY_BET_RECORD, self::WORK_KEY, self::BUCKET, QueueJobRunSourceEnum::SCHEDULE);

        $deleted = QueueJobRun::resetMarks(QueueJobKeyEnum::SUMMARY_BET_RECORD, [self::BUCKET], [self::WORK_KEY], [QueueJobRunSourceEnum::MANUAL]);

        $this->assertSame(1, $deleted);
        $this->assertSame(
            [QueueJobRunSourceEnum::MANUAL->value],
            QueueJobRun::query()
                ->where('job_key', QueueJobKeyEnum::SUMMARY_BET_RECORD->value)
                ->where('work_key', (string) self::WORK_KEY)
                ->pluck('source')
                ->all(),
        );
    }
}
