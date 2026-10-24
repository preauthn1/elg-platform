<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\WithdrawalOrder;
use App\Services\CashFlow\Bank\BankRepairService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/Bank/DedupRollbackCommandTest.php
 *
 * ⚠️ tearDown 只允許清理「本測試自己建立的東西」（同 GuardActiveBanksCommandTest，
 * 2026-08-25 Dev banks 全表被清空事故的教訓）：guard 約束測試含 DDL（隱式 commit），
 * DatabaseTransactions 無法回滾，殘留列以逐筆追蹤到的 id 精準刪除；
 * 禁止 truncate 與無條件 delete——測試連上非測試庫時那等於清空真實資料。
 *
 * 「為何不能比照其他測試走事務回滾」的完整說明見 {@see GuardActiveBanksCommandTest}：
 * 重點是 MySQL 的 DDL 隱式 commit 不只終結交易，還會把「它之前的所有寫入」一併提交，
 * 因此 fixture 列同樣回不去，只能逐筆精準清理。
 */
class DedupRollbackCommandTest extends TestCase
{
    use DetachesBanksActiveGuard;

    /** setUp 時 banks 是否已有 active_uk：既有的 guard 約束不是本測試建的，tearDown 不卸 */
    private bool $hadActiveUkColumn = false;

    /** @var array<string, list<int>> 本測試插入的各表 id，tearDown 逐筆精準刪除 */
    private array $fixtureIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadActiveUkColumn = Schema::hasColumn('banks', 'active_uk');
        $this->fixtureIds = [];
    }

    protected function tearDown(): void
    {
        if (!$this->hadActiveUkColumn && Schema::hasColumn('banks', 'active_uk')) {
            DB::statement('ALTER TABLE banks DROP INDEX uk_banks_active');
            DB::statement('ALTER TABLE banks DROP COLUMN active_uk');
        }

        // 命令寫入的審計列沒有可預知 id，以本測試建立的 banks.id 反查刪除
        $bankIds = $this->fixtureIds['banks'] ?? [];
        if ($bankIds !== []) {
            DB::table('bank_dedup_logs')
                ->whereIn('survivor_bank_id', $bankIds)
                ->orWhereIn('loser_bank_id', $bankIds)
                ->delete();
        }

        foreach ($this->fixtureIds as $table => $ids) {
            DB::table($table)->whereIn('id', $ids)->delete();
        }

        parent::tearDown();
    }

    private function track(string $table, int $id): int
    {
        $this->fixtureIds[$table][] = $id;

        return $id;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeBank(array $attrs = []): int
    {
        return $this->track('banks', (int) DB::table('banks')->insertGetId(array_merge([
            'agent_group_id' => 674,
            'type' => 1,
            'name' => '(MYR) - AGRO Bank',
            'code' => 'MYR0023',
            'currency_id' => 1,
            'status' => 1,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ], $attrs)));
    }

    private function makeBinding(int $memberId, int $bankId, int $status = 1): int
    {
        return $this->track('member_bank_accounts', (int) DB::table('member_bank_accounts')->insertGetId([
            'member_id' => $memberId, 'bank_id' => $bankId, 'account_name' => 'T', 'account_no' => '1',
            'status' => $status, 'sort' => 0, 'operator_cs' => 0,
            'created_at' => Carbon::parse('2026-01-01'), 'updated_at' => Carbon::parse('2026-01-01'),
        ]));
    }

    public function test_rollback_restores_repoint_and_deactivation(): void
    {
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $bindingId = $this->makeBinding(memberId: 5001, bankId: $loserId);
        $order = WithdrawalOrder::factory()->create(['member_id' => 5001, 'bank_id' => $loserId]);
        $this->track('withdrawal_orders', (int) $order->id);

        // 先清理
        $this->artisan('bank:dedup-active --execute --force')->assertExitCode(0);
        $this->assertSame($survivorId, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'));
        $this->assertSame(0, (int) DB::table('banks')->where('id', $loserId)->value('status'));

        // 回滾
        $this->artisan('bank:dedup-rollback --execute --force')
            ->expectsOutputToContain('回滾完成')
            ->assertExitCode(0);

        // 還原：綁定回 loser、loser 復活、訂單回 loser
        $this->assertSame($loserId, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', $loserId)->value('status'));
        $order->refresh();
        $this->assertSame($loserId, (int) $order->bank_id);
    }

    public function test_rollback_restores_pending_realname_request_repoint(): void
    {
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $requestId = $this->track('realname_validation_requests', (int) DB::table('realname_validation_requests')->insertGetId([
            'member_id' => 9301, 'realname' => 'Tester', 'bank_id' => $loserId,
            'bank_name' => '(MYR) - AGRO Bank', 'bank_account' => '1234567890',
            'status' => 0, 'username' => 'tester01',
            'created_at' => Carbon::parse('2026-01-01'), 'updated_at' => Carbon::parse('2026-01-01'),
        ]));

        $this->artisan('bank:dedup-active --execute --force')->assertExitCode(0);
        $this->assertSame($survivorId, (int) DB::table('realname_validation_requests')->where('id', $requestId)->value('bank_id'));

        $this->artisan('bank:dedup-rollback --execute --force')
            ->expectsOutputToContain('回滾完成')
            ->assertExitCode(0);

        $this->assertSame($loserId, (int) DB::table('realname_validation_requests')->where('id', $requestId)->value('bank_id'));
    }

    public function test_rollback_drops_active_constraint_so_losers_can_reactivate(): void
    {
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $this->makeBinding(memberId: 5001, bankId: $loserId);

        $this->artisan('bank:dedup-active --execute --force')->assertExitCode(0);
        $this->artisan('bank:guard-active-banks')->assertExitCode(0);
        $this->assertTrue(Schema::hasColumn('banks', 'active_uk'));

        $this->artisan('bank:dedup-rollback --execute --force')->assertExitCode(0);

        // 約束已卸，loser 復活（若約束未卸，status=1 會撞 unique 導致回滾失敗）
        $this->assertFalse(Schema::hasColumn('banks', 'active_uk'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', $loserId)->value('status'));
    }

    public function test_dry_run_does_not_write(): void
    {
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $this->makeBinding(memberId: 5001, bankId: $loserId);
        $this->artisan('bank:dedup-active --execute --force')->assertExitCode(0);

        $this->artisan('bank:dedup-rollback')
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        // 未回滾：仍維持清理後狀態
        $this->assertSame(0, (int) DB::table('banks')->where('id', $loserId)->value('status'));
    }

    public function test_rollback_restores_soft_deleted_shadow(): void
    {
        // shadow 清理（deleted_at 標記）後回滾：deleted_at 應復原為 null、綁定回 shadow
        $shadowId = $this->makeBank(['status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $bindingId = $this->makeBinding(memberId: 5002, bankId: $shadowId);

        $this->artisan('bank:dedup-active --execute --force')->assertExitCode(0);
        $this->assertNotNull(DB::table('banks')->where('id', $shadowId)->value('deleted_at'));

        $this->artisan('bank:dedup-rollback --execute --force')->assertExitCode(0);

        $this->assertNull(DB::table('banks')->where('id', $shadowId)->value('deleted_at'));
        $this->assertSame($shadowId, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'));
        // shadow 原本即 inactive，回滾不得誤啟用
        $this->assertSame(0, (int) DB::table('banks')->where('id', $shadowId)->value('status'));
    }

    public function test_rollback_does_not_consume_mismatch_prefixed_audit_rows(): void
    {
        // 第一組：正常 dedup（同名不同建立時間）⇒ 產生無前綴 audit，rollback 應該吃掉
        $loserId = $this->makeBank(['name' => '(MYR) - AGRO BANK', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['name' => '(MYR) - AGRO Bank', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $bindingId = $this->makeBinding(memberId: 7101, bankId: $loserId);

        // 第二組：base 層 fix-mismatch 合併 ⇒ 產生 mismatch_* audit，rollback 不該碰
        // 名稱與代碼都獨特，避免被 bank:dedup-active 當成同一組重複
        $primaryId = $this->makeBank([
            'agent_group_id' => 0, 'name' => '(MYR) - Isolation Primary', 'code' => 'ISO_PRI',
        ]);
        $dupId = $this->makeBank([
            'agent_group_id' => 0, 'name' => '(MYR) - Isolation Dup', 'code' => 'ISO_DUP',
        ]);
        (new BankRepairService)->applyDuplicateMerge($primaryId, [$dupId]);

        $this->assertSame(0, (int) DB::table('banks')->where('id', $dupId)->value('status'));
        $mismatchAuditBefore = DB::table('bank_dedup_logs')->where('action', 'like', 'mismatch_%')->count();
        $this->assertGreaterThan(0, $mismatchAuditBefore);

        $this->artisan('bank:dedup-active --execute --force')->assertExitCode(0);
        $this->artisan('bank:dedup-rollback --execute --force')
            ->expectsOutputToContain('回滾完成')
            ->assertExitCode(0);

        // 正常那組被還原
        $this->assertSame($loserId, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', $loserId)->value('status'));

        // mismatch 那組完全沒被動：dup 仍停用、audit 仍在
        $this->assertSame(
            0,
            (int) DB::table('banks')->where('id', $dupId)->value('status'),
            'rollback 不該復活 fix-mismatch 停用的列'
        );
        $this->assertSame(
            $mismatchAuditBefore,
            DB::table('bank_dedup_logs')->where('action', 'like', 'mismatch_%')->count(),
            'mismatch_* audit 不該被 rollback 消費'
        );
    }
}
