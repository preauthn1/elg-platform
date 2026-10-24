<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Services\Database\TableBackupService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TableBackupService 的「零列不建表」與 pruneEmpty 行為。
 *
 * 連錯庫防護自查：本測試只建／只卸自有的 `zz_backup_probe*` 探針表（setUp 記旗標、
 * tearDown 逐名 DROP），不 truncate、不無條件 DELETE 任何共用表；pruneEmpty 的語意
 * 本身會 DROP 全庫零列備份表，但僅在 TestCase 四道連線防線（host／宣告庫名／實際
 * 目標）全過的測試庫執行——測試庫依紀律只含 migration schema、不存在業務備份表。
 * 備份 DDL 是隱式 commit，不依賴 DatabaseTransactions，一律手動精準清理。
 */
class TableBackupServiceTest extends TestCase
{
    private const PROBE = 'zz_backup_probe';

    private TableBackupService $service;

    /** @var list<string> 本測試建立、tearDown 要卸的表 */
    private array $createdTables = [];

    /** @var list<string> 本測試產生、tearDown 要刪的 manifest 戳 */
    private array $createdStamps = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TableBackupService::class);

        $this->assertFalse(
            $this->tableExists(self::PROBE),
            '探針表已存在，可能是前次測試殘留，請先人工確認再清',
        );

        DB::statement(sprintf(
            'CREATE TABLE `%s` (id INT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(50) NOT NULL)',
            self::PROBE,
        ));
        $this->createdTables[] = self::PROBE;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->createdTables) as $table) {
            DB::statement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }

        foreach ($this->createdStamps as $stamp) {
            $manifest = storage_path('app/backups/table-backup/manifest_' . $stamp . '.json');

            if (is_file($manifest)) {
                unlink($manifest);
            }
        }

        parent::tearDown();
    }

    public function test_scoped_capture_with_empty_ids_creates_no_table_and_manifests_zero_rows(): void
    {
        $captured = $this->service->capture(
            fullTables: [],
            scopedTables: [self::PROBE => ['column' => 'id', 'ids' => []]],
            meta: ['command' => 'phpunit'],
        );
        $this->createdStamps[] = $captured['stamp'];

        $meta = $captured['tables'][self::PROBE];
        $this->assertNull($meta['backup_table'], '零列範圍不應建備份表');
        $this->assertSame(0, $meta['rows']);

        $this->assertFalse(
            $this->tableExists(self::PROBE . '_bak_' . $captured['stamp']),
            '零列範圍仍建出了空備份表',
        );

        $manifest = json_decode((string) file_get_contents(
            storage_path('app/backups/table-backup/manifest_' . $captured['stamp'] . '.json'),
        ), true);
        $this->assertSame(0, $manifest['tables'][self::PROBE]['rows'], '「0 筆」的明確紀錄應由 manifest 承擔');
    }

    public function test_scoped_capture_with_ids_still_creates_table_with_scoped_rows(): void
    {
        DB::table(self::PROBE)->insert([
            ['id' => 1, 'name' => 'in-scope'],
            ['id' => 2, 'name' => 'out-of-scope'],
        ]);

        $captured = $this->service->capture(
            fullTables: [],
            scopedTables: [self::PROBE => ['column' => 'id', 'ids' => [1]]],
            meta: ['command' => 'phpunit'],
        );
        $this->createdStamps[] = $captured['stamp'];
        $backupTable = $captured['tables'][self::PROBE]['backup_table'];
        $this->createdTables[] = $backupTable;

        $this->assertSame(self::PROBE . '_bak_' . $captured['stamp'], $backupTable);
        $this->assertSame(1, DB::table($backupTable)->count(), '範圍備份應只含 in-scope 列');
        $this->assertSame(1, $captured['tables'][self::PROBE]['rows']);
    }

    public function test_prune_empty_drops_only_strict_named_zero_row_backup_tables(): void
    {
        $emptyBak = self::PROBE . '_bak_20200101000000';
        $filledBak = self::PROBE . '2_bak_20200101000000';
        $nonPattern = self::PROBE . '_bak_notatimestamp';

        foreach ([$emptyBak, $filledBak, $nonPattern] as $table) {
            DB::statement(sprintf('CREATE TABLE `%s` LIKE `%s`', $table, self::PROBE));
            $this->createdTables[] = $table;
        }
        DB::table($filledBak)->insert(['id' => 1, 'name' => 'keep-me']);

        $dropped = $this->service->pruneEmpty();

        $this->assertContains($emptyBak, $dropped, '嚴格命名的零列備份表應被清除');
        $this->assertFalse($this->tableExists($emptyBak));

        $this->assertNotContains($filledBak, $dropped, '有資料的備份表不論保留期都不可動');
        $this->assertTrue($this->tableExists($filledBak));

        $this->assertNotContains($nonPattern, $dropped, '不合 14 碼戳命名的表不可碰');
        $this->assertTrue($this->tableExists($nonPattern));
    }

    private function tableExists(string $table): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table],
        ) !== [];
    }
}
