<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\Schedule;

use App\Console\Commands\Schedule\AbstractArchive;
use App\Console\Commands\Schedule\ArchiveActivityLogs;
use App\Console\Commands\Schedule\ArchiveCommandLogs;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionProperty;
use Tests\TestCase;

/**
 * AbstractArchive 分表保留期清理測試
 *
 * php artisan test tests/Unit/Console/Commands/Schedule/ArchivePartitionRetentionTest.php --compact
 *
 * ## 為何用探針表而不用 command_logs / activity_logs
 * `TestCase` 以 `DatabaseTransactions` 隔離，但 `CREATE TABLE` / `DROP TABLE` 是 DDL、
 * 在 MySQL 會**隱式提交交易**——測試對真實表做的 DDL 會直接落到測試庫且無法 rollback。
 * 故改用本檔自建的 `archive_probe_logs` 系列，並於 tearDown 明確清除。
 *
 * ## 未覆蓋
 * `handle()` 步驟 1～3（建分表 / 搬資料 / 清主表）需備妥整套歸檔資料，本檔不涵蓋；
 * 探針只走步驟 4 的入口 {@see AbstractArchive::prunePartitions()}，
 * 因此「清理在 `DB::commit()` 之後才執行」這個順序仍靠 code review 把關。
 */
class ArchivePartitionRetentionTest extends TestCase
{
    /** 探針主表名（刻意與任何真實資料表無關） */
    private const string PROBE_TABLE = 'archive_probe_logs';

    /** 本測試建立過的資料表，tearDown 逐一清除 */
    private array $createdTables = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 預設釘在月中：月中對任何 keep 值都不會觸發 subMonths 溢位，
        // 溢位邊界另由 test_cutoff_is_immune_to_month_end_overflow() 覆蓋
        Carbon::setTestNow('2026-07-15 03:00:00');

        Artisan::registerCommand(new ArchiveProbeCommand);

        $this->createTable(self::PROBE_TABLE);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdTables) as $table) {
            Schema::dropIfExists($table);
        }
        $this->createdTables = [];

        // RENAME 產生的隔離表不在 $createdTables 內，改用前綴掃一次
        $strays = DB::select(
            'SELECT TABLE_NAME AS name
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME LIKE ?',
            ['%' . self::PROBE_TABLE . '%']
        );

        foreach ($strays as $stray) {
            Schema::dropIfExists($stray->name);
        }

        Carbon::setTestNow();

        parent::tearDown();
    }

    #[TestDox('刪除超過保留期的分表，保留窗口內的不動')]
    public function test_drops_partitions_older_than_retention_window(): void
    {
        // keep=6、當月 202607 ⇒ 保留 202602 ～ 202607，202601 及更早刪除
        $this->createPartitions(['202512', '202601', '202602', '202607']);

        $this->artisan('test:archive-probe')
            ->assertExitCode(Command::SUCCESS);

        $this->assertPartitionsMissing(['202512', '202601']);
        $this->assertPartitionsExist(['202602', '202607']);
    }

    #[TestDox('保留窗口的邊界月份不會被刪')]
    public function test_keeps_the_boundary_month_of_the_window(): void
    {
        // 202602 是窗口內最舊的一個月（202607 往回數 6 個月），屬邊界，必須留下
        $this->createPartitions(['202601', '202602']);

        $this->artisan('test:archive-probe')->assertExitCode(Command::SUCCESS);

        $this->assertPartitionsMissing(['202601']);
        $this->assertPartitionsExist(['202602']);
    }

    #[TestDox('月底執行不會因日期溢位而多刪一個分表')]
    public function test_cutoff_is_immune_to_month_end_overflow(): void
    {
        // `subMonths()` 沿用 PHP DateTime 溢位語意：2026-07-31 減 5 個月 ⇒ 2026-03-03，
        // 未先 startOfMonth() 時 cutoff 會誤為 202603，害 202602 提早數天被刪
        Carbon::setTestNow('2026-07-31 05:00:00');

        $this->createPartitions(['202601', '202602']);

        $this->artisan('test:archive-probe')->assertExitCode(Command::SUCCESS);

        $this->assertPartitionsMissing(['202601']);
        $this->assertPartitionsExist(['202602']);
    }

    #[TestDox('保留 12 個月時月底同樣不溢位')]
    public function test_cutoff_is_immune_to_overflow_for_twelve_month_retention(): void
    {
        // activity_logs 用的 keep=12：2027-01-31 減 11 個月 ⇒ 2026-03-03，cutoff 會誤為 202603
        Carbon::setTestNow('2027-01-31 06:00:00');

        $this->createPartitions(['202601', '202602']);

        $this->artisan('test:archive-probe --keep=12')->assertExitCode(Command::SUCCESS);

        $this->assertPartitionsMissing(['202601']);
        $this->assertPartitionsExist(['202602']);
    }

    #[TestDox('保留期為 1 個月時當月分表仍不被刪')]
    public function test_keeps_current_month_when_retention_is_one_month(): void
    {
        // keep=1 是合法下界，cutoff 等於當月；當月分表因 `< cutoff` 被排除，必須存活
        $this->createPartitions(['202606', '202607']);

        $this->artisan('test:archive-probe --keep=1')->assertExitCode(Command::SUCCESS);

        $this->assertPartitionsMissing(['202606']);
        $this->assertPartitionsExist(['202607']);
    }

    #[TestDox('dry-run 只列出不刪除')]
    public function test_dry_run_lists_without_dropping(): void
    {
        $this->createPartitions(['202512', '202601']);

        $this->artisan('test:archive-probe --dry-run-partitions')
            ->expectsOutputToContain('[DRY RUN]')
            ->assertExitCode(Command::SUCCESS);

        $this->assertPartitionsExist(['202512', '202601']);
    }

    #[TestDox('未設定保留期時完全不碰分表')]
    public function test_skips_entirely_when_retention_is_not_configured(): void
    {
        $this->createPartitions(['202001', '202512']);

        $this->artisan('test:archive-probe --no-retention')
            ->assertExitCode(Command::SUCCESS);

        $this->assertPartitionsExist(['202001', '202512']);
    }

    #[TestDox('同前綴但非年月分表的資料表不被誤刪')]
    public function test_ignores_tables_that_are_not_year_month_partitions(): void
    {
        $this->createPartitions(['202512']);

        // 同前綴但後綴形態不符：文字後綴、8 位日期後綴、5 位數字
        $decoys = [
            self::PROBE_TABLE . '_backup',
            self::PROBE_TABLE . '_20251201',
            self::PROBE_TABLE . '_20251',
        ];

        foreach ($decoys as $decoy) {
            $this->createTable($decoy);
        }

        $this->artisan('test:archive-probe')->assertExitCode(Command::SUCCESS);

        $this->assertPartitionsMissing(['202512']);

        foreach ($decoys as $decoy) {
            $this->assertTrue(
                Schema::hasTable($decoy),
                "非年月分表被誤刪：{$decoy}"
            );
        }
    }

    #[TestDox('無超過保留期的分表時正常結束')]
    public function test_succeeds_when_nothing_to_drop(): void
    {
        $this->createPartitions(['202607']);

        $this->artisan('test:archive-probe')
            ->expectsOutputToContain('無超過保留期的分表')
            ->assertExitCode(Command::SUCCESS);
    }

    #[TestDox('正式命令的保留期與清理模式符合拍板值')]
    public function test_production_commands_declare_the_agreed_retention(): void
    {
        $policyNote = '這是 RB 2026-07-29 拍板的資料保留政策，不是實作細節；'
            . '分表是主表資料被刪後的唯一副本，改動前需重新確認政策。';

        $commandLogs = new ArchiveCommandLogs;
        $activityLogs = new ArchiveActivityLogs;

        // activity_logs 的 15 個月 ＝ 拍板的 12 ＋ 3；原本的兩階段隔離已於 2026-08 拆除，
        // 永久銷毀時點不變，理由見 ArchiveActivityLogs docblock
        $this->assertSame(6, $this->retentionOf($commandLogs), $policyNote);
        $this->assertSame(15, $this->retentionOf($activityLogs), $policyNote);

        // 表名會被內插進 MySQL REGEXP，metacharacter 會擴大比對範圍而誤刪鄰近資料表。
        // 這是寫死在子類的字面值，屬編譯期契約，故驗在測試而非每天兩次的 runtime
        foreach ([$commandLogs, $activityLogs] as $command) {
            $this->assertMatchesRegularExpression(
                '/^[A-Za-z0-9_]+$/',
                (new ReflectionProperty($command, 'table'))->getValue($command),
                $command->getName() . ' 的主表名稱含 REGEXP metacharacter'
            );
        }
    }

    #[TestDox('正式命令都提供分表清理的預演旗標')]
    public function test_production_commands_expose_the_prune_dry_run_flag(): void
    {
        // 保留期一設，清理就是不可逆操作；少了預演旗標等於失去上線前的核對手段
        foreach ([new ArchiveCommandLogs, new ArchiveActivityLogs] as $command) {
            $this->assertTrue(
                $command->getDefinition()->hasOption('dry-run-partitions'),
                $command->getName() . ' 設了保留期卻沒有 --dry-run-partitions 旗標'
            );
        }
    }

    private function retentionOf(AbstractArchive $command): ?int
    {
        // 讀實例值而非宣告預設值：日後若有人改在 configure()／建構子覆寫，這裡才抓得到
        return (new ReflectionProperty($command, 'keepPartitionMonths'))->getValue($command);
    }

    /** @param  list<string>  $yearMonths */
    private function createPartitions(array $yearMonths): void
    {
        foreach ($yearMonths as $yearMonth) {
            $this->createTable(self::PROBE_TABLE . '_' . $yearMonth);
        }
    }

    private function createTable(string $table): void
    {
        Schema::dropIfExists($table);
        DB::statement("CREATE TABLE {$table} (id INT UNSIGNED NOT NULL PRIMARY KEY)");

        $this->createdTables[] = $table;
    }

    /** @param  list<string>  $yearMonths */
    private function assertPartitionsExist(array $yearMonths): void
    {
        foreach ($yearMonths as $yearMonth) {
            $table = self::PROBE_TABLE . '_' . $yearMonth;
            $this->assertTrue(Schema::hasTable($table), "分表應該保留卻被刪除：{$table}");
        }
    }

    /** @param  list<string>  $yearMonths */
    private function assertPartitionsMissing(array $yearMonths): void
    {
        foreach ($yearMonths as $yearMonth) {
            $table = self::PROBE_TABLE . '_' . $yearMonth;
            $this->assertFalse(Schema::hasTable($table), "分表應該被刪除卻還在：{$table}");
        }
    }
}

/**
 * 測試專用探針：只跑步驟 4 的入口 {@see AbstractArchive::prunePartitions()}，
 * 不觸發歸檔與主表刪除。
 *
 * 直接跑 `handle()` 會連帶執行 migrateData / deleteOldData，需要備妥整套歸檔資料才有意義；
 * 本檔要驗的是分表保留期的篩選與刪除行為，故只暴露該步驟，並比照正式 `handle()`
 * 把回傳值映射成 SUCCESS / FAILURE。
 */
class ArchiveProbeCommand extends AbstractArchive
{
    protected $signature = 'test:archive-probe
                            {--month= : 模擬 --month= 補檔模式}
                            {--daily : 模擬每日模式}
                            {--dry-run-partitions : 只列出將處置的分表}
                            {--keep= : 覆寫保留月數，未給則用預設 6}
                            {--no-retention : 模擬子類未宣告保留期的情況}';

    protected $description = '（測試用）執行 AbstractArchive 的分表保留期清理';

    protected string $table = 'archive_probe_logs';

    protected ?int $keepPartitionMonths = 6;

    public function handle(): int
    {
        if ($this->option('no-retention')) {
            $this->keepPartitionMonths = null;
        }

        if ($this->option('keep') !== null) {
            $this->keepPartitionMonths = (int) $this->option('keep');
        }

        $this->prunePartitions();

        return self::SUCCESS;
    }
}
