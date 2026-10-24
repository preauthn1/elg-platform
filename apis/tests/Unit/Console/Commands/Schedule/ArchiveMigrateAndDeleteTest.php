<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Schedule;

use App\Console\Commands\Schedule\AbstractArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * AbstractArchive 步驟 2（搬資料）與步驟 3（清主表）測試
 *
 * php artisan test tests/Unit/Console/Commands/Schedule/ArchiveMigrateAndDeleteTest.php --compact
 *
 * ## 為何用探針表
 * 與 {@see ArchivePartitionRetentionTest} 同理：`CREATE TABLE LIKE` 是 DDL、在 MySQL 會
 * **隱式提交交易**，`DatabaseTransactions` 擋不住，故一律對自建的 `archive_migrate_probe_logs`
 * 操作，並於 tearDown 連表帶列一併 DROP（列不會殘留在測試庫）。
 *
 * ## 守的是什麼
 * 探針刻意把批次調到 3（搬）／ 2（刪），讓每個場景都必須跨多批才會通過。
 * 分頁一旦退化成「只搬第一批」或「只刪第一批」，這裡就會紅。
 */
class ArchiveMigrateAndDeleteTest extends TestCase
{
    private const string PROBE_TABLE = 'archive_migrate_probe_logs';

    /** 步驟 2 的目標分表；當月為 202607 */
    private const string PROBE_PARTITION = 'archive_migrate_probe_logs_202607';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-15 03:00:00');

        Artisan::registerCommand(new ArchiveMigrateProbeCommand);

        $this->createProbeTable();
    }

    protected function tearDown(): void
    {
        // DROP 表即連列一併清除；DDL 的隱式提交讓 rollback 無效，故必須自己收尾。
        // 測試會把 Carbon 挪到不同月份，分表名因此不固定，按前綴掃一次
        foreach (DB::select(
            'SELECT TABLE_NAME AS name FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            [self::PROBE_TABLE . '_%']
        ) as $partition) {
            Schema::dropIfExists($partition->name);
        }

        Schema::dropIfExists(self::PROBE_TABLE);

        Carbon::setTestNow();

        parent::tearDown();
    }

    #[TestDox('每日模式把當日全部資料搬進分表，跨多批不漏，且不碰其他日期')]
    public function test_migrates_every_batch_of_the_target_day(): void
    {
        // 批次 3、當日 10 列 ⇒ 必須跨 4 批才搬得完
        $this->seedRows('2026-07-14', 10);
        $this->seedRows('2026-07-13', 2);
        $this->seedRows('2026-07-15', 2);

        $this->artisan('test:archive-migrate-probe --daily')
            ->assertExitCode(Command::SUCCESS);

        $archived = DB::table(self::PROBE_PARTITION)->pluck('created_at');

        $this->assertCount(10, $archived, '當日資料未全部搬進分表（分頁只搬了第一批？）');

        foreach ($archived as $createdAt) {
            $this->assertStringStartsWith('2026-07-14', (string) $createdAt, '搬進了非目標日期的資料');
        }
    }

    #[TestDox('重跑同一天不會在分表產生重複列')]
    public function test_rerun_is_idempotent(): void
    {
        $this->seedRows('2026-07-14', 10);

        $this->artisan('test:archive-migrate-probe --daily')->assertExitCode(Command::SUCCESS);
        $this->artisan('test:archive-migrate-probe --daily')->assertExitCode(Command::SUCCESS);

        $this->assertSame(10, DB::table(self::PROBE_PARTITION)->count());
    }

    #[TestDox('分頁走游標式 id 條件，不得退化成 OFFSET')]
    public function test_pagination_uses_an_id_cursor_instead_of_offset(): void
    {
        // 前四個場景只驗「搬得完不完整」，OFFSET 分頁同樣會通過——退化成 OFFSET 只是慢，不是錯。
        // 而 507 秒的線上事故正是慢造成的，所以分頁形式本身必須是契約：
        // OFFSET 每頁都要重掃整個區間再 filesort，游標式則是純 PK range scan。
        $this->seedRows('2026-07-14', 10);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->artisan('test:archive-migrate-probe --daily')
            ->assertExitCode(Command::SUCCESS);

        $selects = array_values(array_filter(
            $queries,
            fn (string $sql) => str_starts_with($sql, 'select *') && str_contains($sql, self::PROBE_TABLE)
        ));

        $this->assertNotEmpty($selects, '沒抓到任何搬資料的 select，測試本身失效了');

        foreach ($selects as $sql) {
            $this->assertStringNotContainsString('offset', $sql, "分頁退化成 OFFSET：{$sql}");
        }

        $this->assertTrue(
            (bool) array_filter($selects, fn (string $sql) => str_contains($sql, '`id` >')),
            '分頁沒有帶上 id 游標條件'
        );
    }

    #[TestDox('主表刪除跨多批清完 cutoff 之前的資料，cutoff 之後的全數保留')]
    public function test_deletes_every_batch_before_the_cutoff(): void
    {
        // deleteMonths=2、now=2026-07-15 ⇒ 滾動 cutoff = 2026-05-15 23:59:59
        $this->seedRows('2026-04-10', 3);
        $this->seedRows('2026-05-15', 2);   // 邊界當日，endOfDay 使整天都在 cutoff 內
        $this->seedRows('2026-05-16', 2);   // 邊界後一天，必須留下
        $this->seedRows('2026-07-14', 1);

        $this->artisan('test:archive-migrate-probe --daily')
            ->assertExitCode(Command::SUCCESS);

        $remaining = DB::table(self::PROBE_TABLE)
            ->orderBy('created_at')
            ->pluck('created_at')
            ->map(fn ($value) => substr((string) $value, 0, 10))
            ->all();

        // 批次 2、待刪 5 列 ⇒ 必須跨 3 批才刪得完
        $this->assertSame(['2026-05-16', '2026-05-16', '2026-07-14'], $remaining);
    }

    #[TestDox('目標日期無資料時不報錯，也不建出空內容以外的副作用')]
    public function test_handles_a_day_with_no_rows(): void
    {
        $this->seedRows('2026-07-01', 2);

        $this->artisan('test:archive-migrate-probe --daily')
            ->expectsOutputToContain('無需歸檔的資料')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, DB::table(self::PROBE_PARTITION)->count());
    }

    #[TestDox('跨月當天只刪一天的量，不會一次刪掉整個月')]
    public function test_cutoff_advances_one_day_at_a_time_across_a_month_boundary(): void
    {
        // deleteMonths=2。舊寫法 subMonths()->endOfMonth() 在 7/31 的 cutoff 是 5/31，
        // 8/1 直接變成 6/30 ⇒ 整個 6 月一次刪完（VWorld 約 450 萬列，就是 507 秒告警的另一半）。
        // 滾動日界後 8/1 的 cutoff 只到 6/1，6 月其餘日期要各等到自己的那一天
        Carbon::setTestNow('2026-08-01 06:00:00');

        $this->seedRows('2026-06-01', 2);
        $this->seedRows('2026-06-15', 2);
        $this->seedRows('2026-06-30', 2);
        $this->seedRows('2026-07-31', 1);

        $this->artisan('test:archive-migrate-probe --daily')
            ->assertExitCode(Command::SUCCESS);

        $remaining = DB::table(self::PROBE_TABLE)
            ->orderBy('created_at')
            ->pluck('created_at')
            ->map(fn ($value) => substr((string) $value, 0, 10))
            ->unique()
            ->values()
            ->all();

        // 只有 6/1 被刪；舊寫法會讓這三天全部消失
        $this->assertSame(['2026-06-15', '2026-06-30', '2026-07-31'], $remaining);
    }

    #[TestDox('月底 cutoff 不因 subMonths 溢位而倒退或亂跳')]
    public function test_cutoff_is_monotonic_at_month_end(): void
    {
        // 4/30 減 2 個月：溢位會算成 3/2（2 月只有 28 天），比 5/1 的 3/1 還晚 ⇒ cutoff 倒退。
        // NoOverflow 讓 4/29、4/30 都停在 2/28，3/1 之後才輪到
        Carbon::setTestNow('2026-04-30 06:00:00');

        $this->seedRows('2026-02-28', 1);
        $this->seedRows('2026-03-01', 1);
        $this->seedRows('2026-03-02', 1);

        $this->artisan('test:archive-migrate-probe --daily')
            ->assertExitCode(Command::SUCCESS);

        $remaining = DB::table(self::PROBE_TABLE)
            ->orderBy('created_at')
            ->pluck('created_at')
            ->map(fn ($value) => substr((string) $value, 0, 10))
            ->all();

        // 溢位版會把 3/1、3/2 一起刪掉
        $this->assertSame(['2026-03-01', '2026-03-02'], $remaining);
    }

    #[TestDox('--month 與 --daily 同時給時搬整個月，不會只搬 1 號')]
    public function test_month_option_wins_over_daily_flag(): void
    {
        // 舊寫法：getTargetMonth() 回 '2026-06'（month 優先），但 isDailyMode() 看 --daily 旗標
        // ⇒ 走每日分支對 '2026-06' 做 strtotime() ＝ 6/1 ⇒ 靜默只搬 6/1 那一天，
        // 卻回報「已將 2026-06 的 N 筆資料歸檔」。補檔正是用來修漏搬資料的操作，不能這樣錯
        $this->seedRows('2026-06-01', 2);
        $this->seedRows('2026-06-15', 2);
        $this->seedRows('2026-06-30', 2);

        $this->artisan('test:archive-migrate-probe --month=2026-06 --daily')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(6, DB::table(self::PROBE_TABLE . '_202606')->count(), '整個 6 月都該進分表');
    }

    #[TestDox('無旗標時歸檔整個上個月，與 --month=<上個月> 等價')]
    public function test_default_mode_archives_the_whole_previous_month(): void
    {
        // now=2026-07-15 ⇒ 上個月為 2026-06
        $this->seedRows('2026-06-01', 1);
        $this->seedRows('2026-06-30', 1);
        $this->seedRows('2026-07-01', 1);   // 當月，不該被搬

        $this->artisan('test:archive-migrate-probe')
            ->assertExitCode(Command::SUCCESS);

        $archived = DB::table(self::PROBE_TABLE . '_202606')
            ->orderBy('created_at')
            ->pluck('created_at')
            ->map(fn ($value) => substr((string) $value, 0, 10))
            ->all();

        $this->assertSame(['2026-06-01', '2026-06-30'], $archived);
    }

    #[TestDox('補檔月份超過保留期時拒絕執行，不建立注定被刪的分表')]
    public function test_rejects_a_backfill_month_past_the_retention_window(): void
    {
        // keep=6、當月 202607 ⇒ cutoff 202602。補 2025-12 等於建一張下一輪就該被刪的分表，
        // 擋在入口後，步驟 4 不需要為補檔開特例
        $this->assertRejectsBackfill('2025-12', '已超過分表保留期');
        $this->assertFalse(Schema::hasTable(self::PROBE_TABLE . '_202512'), '不該建立注定被刪的分表');
    }

    #[TestDox('補檔月份晚於當月時拒絕執行')]
    public function test_rejects_a_backfill_month_in_the_future(): void
    {
        $this->assertRejectsBackfill('2026-09', '不得晚於當月');
        $this->assertFalse(Schema::hasTable(self::PROBE_TABLE . '_202609'), '不該建立永遠空著的未來分表');
    }

    /**
     * 斷言補檔月份被守衛擋下。
     *
     * 命令不再自捕例外（改由 `bootstrap/app.php` 的 report() → AlertGate 處理），
     * 所以這裡驗的是例外本身而非 exit code。
     */
    private function assertRejectsBackfill(string $month, string $expectedMessage): void
    {
        try {
            $this->artisan("test:archive-migrate-probe --month={$month}")->run();
            $this->fail("補檔月份 {$month} 應該被拒絕");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }

    private function createProbeTable(): void
    {
        Schema::dropIfExists(self::PROBE_PARTITION);
        Schema::dropIfExists(self::PROBE_TABLE);

        // 索引比照 activity_logs：區間走 created_at、排序走 PRIMARY，才驗得到游標式分頁
        DB::statement(sprintf(
            'CREATE TABLE %s (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL,
                KEY idx_created_at (created_at)
            )',
            self::PROBE_TABLE
        ));
    }

    private function seedRows(string $day, int $count): void
    {
        DB::table(self::PROBE_TABLE)->insert(
            array_map(
                fn (int $i) => ['created_at' => sprintf('%s %02d:00:00', $day, $i)],
                range(0, $count - 1)
            )
        );
    }
}

/**
 * 測試專用探針：跑完整 `handle()`，但不宣告 `$keepPartitionMonths`，
 * 因此步驟 4（分表清理）直接跳過，只留步驟 1～3 受測。
 */
class ArchiveMigrateProbeCommand extends AbstractArchive
{
    protected $signature = 'test:archive-migrate-probe {--month=} {--daily}';

    protected $description = '（測試用）執行 AbstractArchive 的搬資料與主表刪除';

    protected string $table = 'archive_migrate_probe_logs';

    protected string $dateColumn = 'created_at';

    protected int $deleteMonths = 2;

    /** 補檔月份守衛要拿 cutoff 比對，故必須宣告保留期；6 個月 ⇒ 當月 202607 時 cutoff 為 202602 */
    protected ?int $keepPartitionMonths = 6;

    /** 刻意小於資料量，逼每個場景都跨多批 */
    protected int $batchSize = 3;

    protected int $deleteBatchSize = 2;
}
