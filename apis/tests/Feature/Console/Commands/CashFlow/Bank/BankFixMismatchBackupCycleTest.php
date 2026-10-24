<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Helpers\StandardBankNameHelper;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Services\Database\TableBackupService;
use Database\Seeders\SyncCurrenciesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * --backup 的完整循環：改動前建備份表 → 改動後可比對 → 疏失時逐列回滾。
 *
 * ⚠️ 本類**刻意不包測試交易**（`$connectionsToTransact = []`）：備份建表是 DDL，MySQL 的
 * DDL 會隱式 commit、打破 DatabaseTransactions 的包裹（見 TestCase::syncProcedures 同款
 * 警語），先前包著跑的版本把 fixtures 永久外洩進測試庫、污染了整個套件。
 * 代價是本類必須自行全量清場（setUp 與 finally 各一次，防前輪中斷殘留）。
 */
class BankFixMismatchBackupCycleTest extends TestCase
{
    /** @var list<string> 停用測試交易（理由見類別 docblock） */
    protected array $connectionsToTransact = [];

    private TableBackupService $backup;

    private const CODES = ['TEST_FIX_BKP', 'TEST_FIX_BKD', 'TEST_FIX_PPR', 'TEST_FIX_PDU', 'TEST_FIX_MKP', 'TEST_FIX_MKD'];

    /** 校驗輸出遮罩測試用的假憑證：只要出現在命令輸出就是洩漏 */
    private const FAKE_API_KEY = [REDACTED]';

    /** prune 循環測試專用的 cash_flow_id（測試庫無 FK，毋需真的建 cash_flows 列） */
    private const PRUNE_CASH_FLOW_ID = 9101;

    /** setUp 時 bank_dedup_logs 的最大 id：cleanup 只刪此後新增的 backup_rollback 審計列 */
    private int $auditWatermark = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backup = app(TableBackupService::class);
        $this->seed(SyncCurrenciesSeeder::class);
        StandardBankNameHelper::clearCache();
        $this->auditWatermark = (int) DB::table('bank_dedup_logs')->max('id');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        StandardBankNameHelper::clearCache();
        parent::tearDown();
    }

    /**
     * 兩道回歸守衛：①備份是同庫備份表且大表只備 dup 引用列——不得回退成整表載入的
     * bank:standardize 委派（生產 143 萬筆 wo 會 OOM）②回滾是逐列覆寫指定 id，
     * 不是整表灌回。
     */
    public function test_backup_verify_rollback_cycle(): void
    {
        $primary = $this->makeBank('TEST_FIX_BKP');
        $dup = $this->makeBank('TEST_FIX_BKD');
        $dupCreatedAt = (string) $dup->fresh()->created_at;

        $before = $this->backupTableNames();

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--backup' => true,
            '--force' => true,
        ])->assertSuccessful();

        $created = array_values(array_diff($this->backupTableNames(), $before));
        $this->assertNotEmpty($created, '應建立備份表');
        $this->assertSame(1, preg_match('/^[a-z_]+_bak_(\d{14})$/', $created[0], $m), '命名沿用原表名＋_bak_＋時間戳');
        $stamp = $m[1];

        try {
            // ① 備份表內容：banks 整表含 dup 的 before 值；大表只備 dup 引用列（本例 0 筆）
            $backupBanks = "banks_bak_{$stamp}";
            $this->assertContains($backupBanks, $created);

            $dupBefore = DB::table($backupBanks)->where('id', $dup->id)->first();
            $this->assertNotNull($dupBefore, '被合併列的 before 值必須在備份表裡');
            $this->assertSame(1, (int) $dupBefore->status, '備份的是改動前的值');
            $this->assertSame(
                $dupCreatedAt,
                (string) $dupBefore->created_at,
                '時間戳必須保真——generated column 判準寫錯（把 DEFAULT_GENERATED 當 generated）'
                . '會讓備份時間戳全變成備份當下時刻'
            );

            $this->assertSame(
                0,
                (int) DB::table("withdrawal_orders_bak_{$stamp}")->count(),
                '大表按 dup ids 篩＝本例零列（整表載入在生產會 OOM）'
            );

            // ② 改動已生效（合併把 dup 停用）
            $this->assertSame(0, (int) $dup->fresh()->status);

            // ③ 逐列回滾：dry-run 不寫入 → execute 還原
            $this->artisan('db:backup-rollback', [
                '--stamp' => $stamp,
                '--table' => 'banks',
                '--ids' => (string) $dup->id,
            ])->assertSuccessful();
            $this->assertSame(0, (int) $dup->fresh()->status, 'dry-run 不得寫入');

            $this->artisan('db:backup-rollback', [
                '--stamp' => $stamp,
                '--table' => 'banks',
                '--ids' => (string) $dup->id,
                '--execute' => true,
                '--force' => true,
            ])->assertSuccessful();

            $restored = $dup->fresh();
            $this->assertSame(1, (int) $restored->status, '回滾要把列還原成備份值');
            $this->assertSame('(MYR) - Kuwait Finance House', $restored->name);
            $this->assertSame(1, (int) $primary->fresh()->status, '未點名的列不得被動到');

            $this->assertStringContainsString(
                (string) $dup->id,
                (string) DB::table('bank_dedup_logs')
                    ->where('action', 'backup_rollback')->orderByDesc('id')->value('detail'),
                '回滾必須落審計'
            );

            // ④ 到期清除：保留期內不刪；把一張改成過期戳後即被排程命令清掉
            $this->artisan('db:prune-table-backups')->assertSuccessful();
            $this->assertContains($backupBanks, $this->backupTableNames(), '保留期內的備份表不得被清');

            DB::statement("RENAME TABLE `{$backupBanks}` TO `banks_bak_20250101000000`");
            $this->artisan('db:prune-table-backups')->assertSuccessful();
            $this->assertNotContains(
                'banks_bak_20250101000000',
                $this->backupTableNames(),
                '超過保留期（以戳解析，不看 create_time）的備份表要被清'
            );
        } finally {
            $this->cleanup();
        }
    }

    /**
     * `--prune-inactive-collisions` 的完整循環：刪停用撞鍵列放行合併 → 校驗把 prune 視為
     * 預期 DELETE → 回滾時 fail-closed 擋槽位衝突 → 依相反順序還原（restore 活列 →
     * --recreate-missing 重建被刪列、保留原 id）。
     */
    public function test_prune_apply_and_recreate_missing_cycle(): void
    {
        $primary = $this->makeBank('TEST_FIX_PPR');
        $dup = $this->makeBank('TEST_FIX_PDU');

        // primary 側停用列與 dup 側活列同槽位撞鍵；活列同 provider 碼＝停用碼的承接列
        $inactiveId = (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => self::PRUNE_CASH_FLOW_ID, 'currency_id' => 1,
            'bank_id' => $primary->id, 'bank_code' => 'TEST_FIX_PPR',
            'provider_bank_code' => 'PVZ9', 'status' => 0,
        ]);
        $activeId = (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => self::PRUNE_CASH_FLOW_ID, 'currency_id' => 1,
            'bank_id' => $dup->id, 'bank_code' => 'TEST_FIX_PDU',
            'provider_bank_code' => 'PVZ9', 'status' => 1,
        ]);

        $before = $this->backupTableNames();

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--backup' => true,
            '--prune-inactive-collisions' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain(sprintf('已刪撞鍵冗餘列 cfb id=%d', $inactiveId))
            ->expectsOutputToContain('校驗通過：所有差異皆在預期內。')
            ->assertSuccessful();

        $created = array_values(array_diff($this->backupTableNames(), $before));
        $this->assertNotEmpty($created);
        preg_match('/_bak_(\d{14})$/', $created[0], $m);
        $stamp = $m[1];

        try {
            // ① prune 生效：停用列已刪、活列 repoint、dup 停用、審計就位
            $this->assertNull(DB::table('cash_flow_banks')->find($inactiveId));
            $this->assertSame($primary->id, (int) DB::table('cash_flow_banks')->find($activeId)->bank_id);
            $this->assertSame(0, (int) $dup->fresh()->status);
            $this->assertStringContainsString(
                sprintf('"cfb":%d', $inactiveId),
                (string) DB::table('bank_dedup_logs')->where('action', 'mismatch_cfb_pruned')->value('detail'),
            );

            // ② fail-closed：活列仍佔著 primary 槽位時重建被刪列必須撞唯一鍵、整批回滾
            try {
                $this->backup->restore($stamp, 'cash_flow_banks', [$inactiveId], recreateMissing: true);
                $this->fail('槽位被佔時重建應撞唯一鍵拋出，不得靜默插入');
            } catch (\Illuminate\Database\QueryException) {
                // 預期：INSERT 撞 (cash_flow_id, currency_id, bank_id) 唯一鍵
            }
            $this->assertNull(DB::table('cash_flow_banks')->find($inactiveId), '撞鍵後不得留下半套插入');

            // ③ 相反順序還原：先把活列 restore 回 dup 槽位，再重建被刪列（保留原 id）
            $restored = $this->backup->restore($stamp, 'cash_flow_banks', [$activeId]);
            $this->assertSame([$activeId], $restored['restored']);

            $this->artisan('db:backup-rollback', [
                '--stamp' => $stamp,
                '--table' => 'cash_flow_banks',
                '--ids' => (string) $inactiveId,
                '--execute' => true,
                '--force' => true,
                '--recreate-missing' => true,
            ])->assertSuccessful();

            $recreated = DB::table('cash_flow_banks')->find($inactiveId);
            $this->assertNotNull($recreated, '重建必須保留原 id');
            $this->assertSame($primary->id, (int) $recreated->bank_id);
            $this->assertSame('PVZ9', (string) $recreated->provider_bank_code);
            $this->assertSame(0, (int) $recreated->status, '重建的是備份當下的值');
        } finally {
            $this->cleanup();
        }
    }

    /**
     * 紅線守衛：校驗輸出印的是 `agent_cash_flows.info` 的 diff，而 info 整包含三方憑證——
     * 2026-09-08 VWorld 實跑時前 60 字元就露出 API_KEY 片段。info 欄一律只印長度變化。
     */
    public function test_verification_output_masks_agent_cash_flow_info(): void
    {
        $primary = $this->makeBank('TEST_FIX_MKP');
        $dup = $this->makeBank('TEST_FIX_MKD');

        // 代付渠道（type=3）才會掃 available_banks；ACF 只含 dup 碼 ⇒ 會被改寫成 primary 碼
        DB::table('cash_flows')->insert([
            'id' => self::PRUNE_CASH_FLOW_ID, 'name' => 'TEST payout', 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);
        AgentCashFlow::create([
            'cash_flow_id' => self::PRUNE_CASH_FLOW_ID,
            'currency_id' => 1,
            'info' => [
                'API_KEY' => self::FAKE_API_KEY,
                'available_banks' => [['BankCode_ELG' => 'TEST_FIX_MKD', 'BankCode_Third' => 'MKD']],
            ],
            'admin' => 0,
        ]);

        try {
            $this->artisan('bank:fix-mismatch', [
                '--currency' => 'MYR',
                '--apply' => true,
                '--backup' => true,
                '--force' => true,
            ])
                ->expectsOutputToContain('info: (JSON 已變更')
                ->doesntExpectOutputToContain(self::FAKE_API_KEY)
                ->expectsOutputToContain('校驗通過：所有差異皆在預期內。')
                ->assertSuccessful();

            $acf = AgentCashFlow::query()->where('cash_flow_id', self::PRUNE_CASH_FLOW_ID)->firstOrFail();
            $this->assertSame(['TEST_FIX_MKP'], array_column($acf->info['available_banks'], 'BankCode_ELG'), '改寫本身仍要生效');
        } finally {
            $this->cleanup();
        }
    }

    private function makeBank(string $code): Bank
    {
        // 本類獨用的 standard（其他測試都在造 Maybank 列，撞 standard 會被分進別組 duplicate）
        return Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => '(MYR) - Kuwait Finance House',
            'code' => $code,
            'currency_id' => 1,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 999999, 'sort' => 0,
            'status' => 1, 'admin' => 0,
        ]);
    }

    /**
     * @return list<string>
     */
    private function backupTableNames(): array
    {
        return array_map(
            static fn (object $row): string => (string) $row->t,
            DB::select(
                "SELECT table_name t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '%\\_bak\\_%'"
            ),
        );
    }

    /**
     * 全量清場：無交易包裹，fixtures、審計、備份表、manifest 一律自己收。
     *
     * ⚠️ 只允許清「可證明屬於本測試」的東西（2026-08-25 Dev banks 清空事故的教訓，
     * 同 GuardActiveBanksCommandTest）：備份表逐張驗內容純為 fixture 才 DROP，
     * 禁止無差別 DROP 全部 `%_bak_%`——連錯庫時那會刪掉真實備份表（回滾唯一依據）；
     * backup_rollback 審計列只刪本測試期間新增的（watermark 圈定）。
     */
    private function cleanup(): void
    {
        $bankIds = DB::table('banks')->whereIn('code', self::CODES)->pluck('id')->all();

        if ($bankIds !== []) {
            DB::table('bank_dedup_logs')
                ->where(fn ($q) => $q->whereIn('survivor_bank_id', $bankIds)->orWhereIn('loser_bank_id', $bankIds))
                ->delete();
        }

        DB::table('bank_dedup_logs')
            ->where('action', 'backup_rollback')
            ->where('id', '>', $this->auditWatermark)
            ->delete();

        DB::table('banks')->whereIn('code', self::CODES)->delete();
        DB::table('cash_flow_banks')->where('cash_flow_id', self::PRUNE_CASH_FLOW_ID)->delete();
        DB::table('agent_cash_flows')->where('cash_flow_id', self::PRUNE_CASH_FLOW_ID)->delete();
        DB::table('cash_flows')->where('id', self::PRUNE_CASH_FLOW_ID)->delete();

        foreach ($this->backupTableNames() as $table) {
            if ($this->holdsOnlyTestFixtures($table)) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
        }

        foreach ((array) glob(storage_path('app/backups/table-backup/manifest_*.json')) as $file) {
            unlink((string) $file);
        }
    }

    /**
     * 備份表內容純為本測試 fixture（或空表）才可 DROP：
     * banks 源以 code 驗、cash_flow_banks 源以 cash_flow_id 驗、其他源只接受空表。
     */
    private function holdsOnlyTestFixtures(string $table): bool
    {
        if (preg_match(TableBackupService::NAME_SUFFIX_PATTERN, $table, $m) !== 1) {
            return false;
        }

        return match ($m['source']) {
            'banks' => !DB::table($table)->whereNotIn('code', self::CODES)->exists(),
            'cash_flow_banks' => !DB::table($table)->where('cash_flow_id', '!=', self::PRUNE_CASH_FLOW_ID)->exists(),
            'agent_cash_flows' => !DB::table($table)->where('cash_flow_id', '!=', self::PRUNE_CASH_FLOW_ID)->exists(),
            default => !DB::table($table)->exists(),
        };
    }
}
