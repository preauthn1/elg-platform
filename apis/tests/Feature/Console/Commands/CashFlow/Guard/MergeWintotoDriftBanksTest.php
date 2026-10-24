<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Guard;

use App\Console\Commands\CashFlow\Guard\MergeWintotoDriftBanks;
use App\Models\WithdrawalOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * MergeWintotoDriftBanks 測試
 *
 * Wintoto g70/g93 drift 銀行雙列合併：配對表寫死、loser 綁定 repoint / 停用、
 * loser 銀行 status=0 不刪、withdrawal_orders 不溯及既往、審計寫 bank_dedup_logs（drift_* action）。
 * 用 DatabaseTransactions 避免污染共享庫；seed 直接以配對表的真實 id 建列（先清同 id 殘留）。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Patch/MergeWintotoDriftBanksTest.php
 */
class MergeWintotoDriftBanksTest extends TestCase
{
    use DatabaseTransactions;
    use DetachesBanksActiveGuard;

    /** 特殊組樣本：canonical 停用中（AFFIN），drift loser 綁定仍 repoint 過去 */
    private const DISABLED_SURVIVOR_ID = 101;

    private const DISABLED_SURVIVOR_LOSER_ID = 1438;

    /** 正常組樣本：g70 PUBLIC BANK */
    private const SURVIVOR_ID = 96;

    private const LOSER_ID = 1344;

    private const LOSER_CODE = 'PublicBank';

    /**
     * 依命令的配對表 seed 全部 survivor / loser 列（先清同 id 殘留與舊審計）。
     */
    private function seedPairs(): void
    {
        $bankIds = $this->allBankIds();

        DB::table('member_bank_accounts')->whereIn('bank_id', $bankIds)->delete();
        DB::table('bank_dedup_logs')->where('action', 'like', 'drift_%')->delete();
        DB::table('banks')->whereIn('id', $bankIds)->delete();

        foreach (MergeWintotoDriftBanks::PAIRS as $pair) {
            $this->insertBank($pair['survivor_id'], $pair['agent_group_id'], $pair['survivor_code']);

            foreach ($pair['losers'] as $loserId => $loserCode) {
                $this->insertBank($loserId, $pair['agent_group_id'], $loserCode);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function allBankIds(): array
    {
        $ids = [];
        foreach (MergeWintotoDriftBanks::PAIRS as $pair) {
            $ids[] = $pair['survivor_id'];
            foreach (array_keys($pair['losers']) as $loserId) {
                $ids[] = $loserId;
            }
        }

        return $ids;
    }

    private function insertBank(int $id, int $agentGroupId, string $code, int $status = 1): void
    {
        DB::table('banks')->insert([
            'id' => $id,
            'agent_group_id' => $agentGroupId,
            'type' => MergeWintotoDriftBanks::BANK_TYPE,
            'currency_id' => MergeWintotoDriftBanks::CURRENCY_ID,
            'code' => $code,
            'name' => 'Bank ' . $code,
            'status' => $status,
        ]);
    }

    private function insertBinding(int $memberId, int $bankId, int $status = 1): int
    {
        return (int) DB::table('member_bank_accounts')->insertGetId([
            'member_id' => $memberId,
            'bank_id' => $bankId,
            'account_name' => 'ACC NAME',
            'account_no' => 'NO-' . $memberId . '-' . $bankId,
            'status' => $status,
            'operator_cs' => 0,
        ]);
    }

    private function runExecute(): void
    {
        $this->artisan('bank:merge-wintoto-drift-banks', ['--execute' => true, '--force' => true])
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // Test 1: 預設 dry-run 零寫入
    // -------------------------------------------------------------------------
    public function test_dry_run_writes_nothing(): void
    {
        $this->seedPairs();
        $bindingId = $this->insertBinding(900001, self::LOSER_ID);

        $this->artisan('bank:merge-wintoto-drift-banks')->assertExitCode(0);

        $binding = DB::table('member_bank_accounts')->find($bindingId);
        $this->assertSame(self::LOSER_ID, (int) $binding->bank_id);
        $this->assertSame(1, (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'drift_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 2: 只綁 loser 的會員 → 綁定 repoint 至 survivor
    // -------------------------------------------------------------------------
    public function test_execute_repoints_sole_binding_to_survivor(): void
    {
        $this->seedPairs();
        $bindingId = $this->insertBinding(900002, self::LOSER_ID);

        $this->runExecute();

        $binding = DB::table('member_bank_accounts')->find($bindingId);
        $this->assertSame(self::SURVIVOR_ID, (int) $binding->bank_id);
        $this->assertSame(1, (int) $binding->status);
        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'drift_repoint')
            ->where('loser_bank_id', self::LOSER_ID)
            ->where('member_bank_account_id', $bindingId)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 3: 同時綁 survivor 與 loser 的會員 → loser 綁定停用、survivor 綁定不動
    // -------------------------------------------------------------------------
    public function test_execute_deactivates_duplicate_binding_when_member_has_both(): void
    {
        $this->seedPairs();
        $survivorBindingId = $this->insertBinding(900003, self::SURVIVOR_ID);
        $loserBindingId = $this->insertBinding(900003, self::LOSER_ID);

        $this->runExecute();

        $loserBinding = DB::table('member_bank_accounts')->find($loserBindingId);
        $this->assertSame(self::LOSER_ID, (int) $loserBinding->bank_id);
        $this->assertSame(0, (int) $loserBinding->status);

        $survivorBinding = DB::table('member_bank_accounts')->find($survivorBindingId);
        $this->assertSame(self::SURVIVOR_ID, (int) $survivorBinding->bank_id);
        $this->assertSame(1, (int) $survivorBinding->status);

        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'drift_deactivate_binding')
            ->where('member_bank_account_id', $loserBindingId)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 3b: survivor 綁定已停用 → 必須走 repoint，會員不得落到零有效綁定
    // -------------------------------------------------------------------------

    /**
     * 兩案分流的子查詢若只比對 bank_id 而不限 status=1，這種會員會兩邊都不接：
     * 情況1 認定他已綁 survivor 而停掉那筆**唯一啟用**的，情況2 又因同一理由被排除。
     * 結果是零個有效綁定，而前台提款銀行清單要求 member_bank_accounts.status=1，
     * 會員因此無法提款，且命令 exit 0、審計看起來正常，完全靜默。
     */
    public function test_member_whose_survivor_binding_is_inactive_keeps_an_active_binding(): void
    {
        $this->seedPairs();
        $survivorBindingId = $this->insertBinding(900011, self::SURVIVOR_ID, status: 0);
        $loserBindingId = $this->insertBinding(900011, self::LOSER_ID);

        $this->runExecute();

        $loserBinding = DB::table('member_bank_accounts')->find($loserBindingId);
        $this->assertSame(
            self::SURVIVOR_ID,
            (int) $loserBinding->bank_id,
            'survivor 綁定已停用者應走 repoint，不該被當成「已綁 survivor」',
        );
        $this->assertSame(1, (int) $loserBinding->status, 'repoint 不得順手停用該綁定');

        $this->assertSame(
            0,
            (int) DB::table('member_bank_accounts')->where('id', $survivorBindingId)->value('status'),
            '原本就停用的 survivor 綁定維持原狀',
        );

        // 真正要守住的不變式：收斂後該會員仍提得了款。
        $this->assertSame(
            1,
            DB::table('member_bank_accounts')->where('member_id', 900011)->where('status', 1)->count(),
            '收斂後會員必須仍有 active 綁定，否則無法提款',
        );
    }

    // -------------------------------------------------------------------------
    // Test 4: 全部 loser 銀行停用（status=0 不刪）、survivor status 不被改動
    // -------------------------------------------------------------------------
    public function test_execute_deactivates_all_loser_banks_without_touching_survivor_status(): void
    {
        $this->seedPairs();

        $this->runExecute();

        $loserIds = [];
        foreach (MergeWintotoDriftBanks::PAIRS as $pair) {
            foreach (array_keys($pair['losers']) as $loserId) {
                $loserIds[] = $loserId;
            }
        }

        $this->assertSame(0, DB::table('banks')->whereIn('id', $loserIds)->where('status', 1)->count());
        $this->assertSame(0, DB::table('banks')->whereIn('id', $loserIds)->whereNotNull('deleted_at')->count());

        // survivor 全數保持 seed 時的 status=1（本測試未模擬特殊組停用）
        $survivorIds = array_column(MergeWintotoDriftBanks::PAIRS, 'survivor_id');
        $this->assertSame(count($survivorIds), DB::table('banks')->whereIn('id', $survivorIds)->where('status', 1)->count());

        $this->assertSame(count($loserIds), DB::table('bank_dedup_logs')->where('action', 'drift_deactivate_bank')->count());
    }

    // -------------------------------------------------------------------------
    // Test 5: 特殊組：survivor 停用中 → 綁定仍 repoint 過去、survivor 不被復活
    // -------------------------------------------------------------------------
    public function test_special_group_survivor_stays_disabled_and_receives_repointed_binding(): void
    {
        $this->seedPairs();
        DB::table('banks')->where('id', self::DISABLED_SURVIVOR_ID)->update(['status' => 0]);
        $bindingId = $this->insertBinding(900005, self::DISABLED_SURVIVOR_LOSER_ID);

        $this->runExecute();

        $binding = DB::table('member_bank_accounts')->find($bindingId);
        $this->assertSame(self::DISABLED_SURVIVOR_ID, (int) $binding->bank_id);
        $this->assertSame(0, (int) DB::table('banks')->where('id', self::DISABLED_SURVIVOR_ID)->value('status'));
        $this->assertSame(0, (int) DB::table('banks')->where('id', self::DISABLED_SURVIVOR_LOSER_ID)->value('status'));
    }

    // -------------------------------------------------------------------------
    // Test 6: withdrawal_orders 不溯及既往（repoint 後歷史單原樣）
    // -------------------------------------------------------------------------
    public function test_execute_does_not_modify_withdrawal_orders(): void
    {
        $this->seedPairs();
        $this->insertBinding(900006, self::LOSER_ID);

        $order = WithdrawalOrder::factory()->create([
            'member_id' => 900006,
            'bank_id' => self::LOSER_ID,
            'bank_code' => self::LOSER_CODE,
            'bank_name' => 'Bank ' . self::LOSER_CODE,
        ]);

        $this->runExecute();

        $fresh = WithdrawalOrder::query()->find($order->id);
        $this->assertSame(self::LOSER_ID, (int) $fresh->bank_id);
        $this->assertSame(self::LOSER_CODE, (string) $fresh->bank_code);
        $this->assertSame('Bank ' . self::LOSER_CODE, (string) $fresh->bank_name);
    }

    // -------------------------------------------------------------------------
    // Test 7: 冪等：第二次執行零新增動作
    // -------------------------------------------------------------------------
    public function test_second_run_is_idempotent(): void
    {
        $this->seedPairs();
        $this->insertBinding(900007, self::LOSER_ID);

        $this->runExecute();
        $auditCountAfterFirst = DB::table('bank_dedup_logs')->where('action', 'like', 'drift_%')->count();

        $this->runExecute();
        $auditCountAfterSecond = DB::table('bank_dedup_logs')->where('action', 'like', 'drift_%')->count();

        $this->assertSame($auditCountAfterFirst, $auditCountAfterSecond);
    }

    // -------------------------------------------------------------------------
    // Test 7b: 執行後的 dry-run 報表歸零（已停用綁定不得再被計入待處理）
    // -------------------------------------------------------------------------
    public function test_dry_run_after_execute_reports_zero_pending(): void
    {
        $this->seedPairs();
        $this->insertBinding(900009, self::SURVIVOR_ID);
        $this->insertBinding(900009, self::LOSER_ID);

        $this->runExecute();

        $this->artisan('bank:merge-wintoto-drift-banks')
            ->expectsOutputToContain('待處理：停用綁定 0、repoint 綁定 0、停用銀行 0')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // Test 8: 配對表與 DB 現況不符（code 不匹配）→ 整體攔截、零寫入
    // -------------------------------------------------------------------------
    public function test_aborts_without_writes_on_pairing_mismatch(): void
    {
        $this->seedPairs();
        $bindingId = $this->insertBinding(900008, self::LOSER_ID);
        DB::table('banks')->where('id', self::LOSER_ID)->update(['code' => 'TAMPERED']);

        $this->artisan('bank:merge-wintoto-drift-banks', ['--execute' => true, '--force' => true])
            ->assertExitCode(1);

        $binding = DB::table('member_bank_accounts')->find($bindingId);
        $this->assertSame(self::LOSER_ID, (int) $binding->bank_id);

        // 其他配對也全數未動（整體攔截，非逐筆跳過）
        $this->assertSame(1, (int) DB::table('banks')->where('id', 1343)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'drift_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 9: survivor 已軟刪＋status=0＋身分全中 → 該對跳過，其餘對子照常處理
    // -------------------------------------------------------------------------
    public function test_retired_survivor_pair_is_skipped_while_other_pairs_still_run(): void
    {
        $this->seedPairs();
        $skippedBinding = $this->insertBinding(900009, self::LOSER_ID);
        $otherBinding = $this->insertBinding(900010, self::DISABLED_SURVIVOR_LOSER_ID);

        DB::table('banks')->where('id', self::SURVIVOR_ID)
            ->update(['status' => 0, 'deleted_at' => '2026-08-17 01:36:20']);

        $this->runExecute();

        // 該對整對跳過：loser 綁定原地不動
        $skipped = DB::table('member_bank_accounts')->find($skippedBinding);
        $this->assertSame(self::LOSER_ID, (int) $skipped->bank_id);
        $this->assertSame(1, (int) $skipped->status);

        // 其餘對子照常處理：獨佔綁定 repoint 到 survivor（未被整批鎖死）
        $other = DB::table('member_bank_accounts')->find($otherBinding);
        $this->assertSame(self::DISABLED_SURVIVOR_ID, (int) $other->bank_id);
    }

    // -------------------------------------------------------------------------
    // Test 10: 軟刪但身分不中 → 仍整批攔死（跑錯白標 DB 的防呆不因分流而弱化）
    // -------------------------------------------------------------------------
    public function test_soft_deleted_row_with_mismatched_identity_still_aborts_everything(): void
    {
        $this->seedPairs();
        $bindingId = $this->insertBinding(900011, self::LOSER_ID);

        // 軟刪＋status=0，但 code 不是配對表寫死的值 ⇒ 不得判為「已完成」
        DB::table('banks')->where('id', self::LOSER_ID)
            ->update(['status' => 0, 'deleted_at' => '2026-08-17 01:36:20', 'code' => 'TAMPERED']);

        $this->artisan('bank:merge-wintoto-drift-banks', ['--execute' => true, '--force' => true])
            ->assertExitCode(1);

        $binding = DB::table('member_bank_accounts')->find($bindingId);
        $this->assertSame(self::LOSER_ID, (int) $binding->bank_id);
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'drift_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 11: 已軟刪卻仍 status=1（狀態矛盾）→ 該對排除、不當已完成，其餘照跑
    // -------------------------------------------------------------------------
    public function test_soft_deleted_but_still_active_row_is_reported_as_conflict_and_excluded(): void
    {
        $this->seedPairs();
        $conflictBinding = $this->insertBinding(900012, self::LOSER_ID);
        $otherBinding = $this->insertBinding(900013, self::DISABLED_SURVIVOR_LOSER_ID);

        DB::table('banks')->where('id', self::LOSER_ID)
            ->update(['status' => 1, 'deleted_at' => '2026-07-13 07:34:25']);

        $this->artisan('bank:merge-wintoto-drift-banks', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('狀態矛盾 1 項')
            ->assertExitCode(0);

        // 矛盾列所屬的對子被排除：綁定與銀行狀態都沒被動
        $conflict = DB::table('member_bank_accounts')->find($conflictBinding);
        $this->assertSame(self::LOSER_ID, (int) $conflict->bank_id);
        $this->assertSame(1, (int) DB::table('banks')->where('id', self::LOSER_ID)->value('status'));

        // 其餘對子仍照常處理
        $other = DB::table('member_bank_accounts')->find($otherBinding);
        $this->assertSame(self::DISABLED_SURVIVOR_ID, (int) $other->bank_id);
    }
}
