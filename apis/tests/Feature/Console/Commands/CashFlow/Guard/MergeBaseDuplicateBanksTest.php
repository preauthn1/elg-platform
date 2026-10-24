<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Guard;

use App\Console\Commands\CashFlow\Guard\MergeBaseDuplicateBanks;
use App\Models\WithdrawalOrder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * MergeBaseDuplicateBanks 測試
 *
 * G2 階段一：VWorld base(group 0) 同碼多 active 收斂——cash_flow_banks 依撞鍵與否
 * 分流 repoint／停用，loser bank status=0 + deleted_at；配對表寫死、防呆整體攔截。
 * 用 DatabaseTransactions 避免污染共享庫；以配對表真實 id 建列（先清同 id 殘留）。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Patch/MergeBaseDuplicateBanksTest.php
 */
class MergeBaseDuplicateBanksTest extends TestCase
{
    use DatabaseTransactions;
    use DetachesBanksActiveGuard;

    /** MYR 樣本：HSBC survivor 845 ← loser 924 */
    private const MYR_SURVIVOR_ID = 845;

    private const MYR_LOSER_ID = 924;

    /** SGD 樣本：SGD0008 survivor 883 ← loser 954 */
    private const SGD_SURVIVOR_ID = 883;

    private const SGD_LOSER_ID = 954;

    private function seedGroups(): void
    {
        $bankIds = $this->allBankIds();

        DB::table('cash_flow_banks')->whereIn('bank_id', $bankIds)->delete();
        DB::table('member_bank_accounts')->whereIn('bank_id', $bankIds)->delete();
        DB::table('bank_dedup_logs')->where('action', 'like', 'base_%')->delete();
        DB::table('banks')->whereIn('id', $bankIds)->delete();

        foreach (MergeBaseDuplicateBanks::GROUPS as $group) {
            $this->insertBank($group['survivor_id'], $group);

            foreach ($group['loser_ids'] as $loserId) {
                $this->insertBank($loserId, $group);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function allBankIds(): array
    {
        $ids = [];
        foreach (MergeBaseDuplicateBanks::GROUPS as $group) {
            $ids[] = $group['survivor_id'];
            foreach ($group['loser_ids'] as $loserId) {
                $ids[] = $loserId;
            }
        }

        return $ids;
    }

    /**
     * @param  array{type: int, currency_id: int, code: string}  $group
     */
    private function insertBank(int $id, array $group): void
    {
        DB::table('banks')->insert([
            'id' => $id,
            'agent_group_id' => 0,
            'type' => $group['type'],
            'currency_id' => $group['currency_id'],
            'code' => $group['code'],
            'name' => 'Bank ' . $group['code'] . ' ' . $id,
            'status' => 1,
        ]);
    }

    private function insertCashFlowBank(int $cashFlowId, int $currencyId, int $bankId, int $status = 1): int
    {
        return (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => $cashFlowId,
            'currency_id' => $currencyId,
            'bank_id' => $bankId,
            'bank_code' => 'CODE-' . $bankId,
            'provider_bank_code' => 'P-' . $bankId,
            'status' => $status,
        ]);
    }

    private function runExecute(int $expectedExitCode = 0): void
    {
        $this->artisan('bank:merge-base-duplicate-banks', ['--execute' => true, '--force' => true])
            ->assertExitCode($expectedExitCode);
    }

    // -------------------------------------------------------------------------
    // Test 1: 預設 dry-run 零寫入
    // -------------------------------------------------------------------------
    public function test_dry_run_writes_nothing(): void
    {
        $this->seedGroups();
        $cfbId = $this->insertCashFlowBank(9923, 1, self::MYR_LOSER_ID);

        $this->artisan('bank:merge-base-duplicate-banks')->assertExitCode(0);

        $cfb = DB::table('cash_flow_banks')->find($cfbId);
        $this->assertSame(self::MYR_LOSER_ID, (int) $cfb->bank_id);
        $this->assertSame(1, (int) DB::table('banks')->where('id', self::MYR_LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'base_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 2: survivor 無同 cash_flow → loser cfb repoint 至 survivor
    // -------------------------------------------------------------------------
    public function test_execute_repoints_cfb_when_no_collision(): void
    {
        $this->seedGroups();
        $cfbId = $this->insertCashFlowBank(9923, 1, self::MYR_LOSER_ID);

        $this->runExecute();

        $cfb = DB::table('cash_flow_banks')->find($cfbId);
        $this->assertSame(self::MYR_SURVIVOR_ID, (int) $cfb->bank_id);
        $this->assertSame(1, (int) $cfb->status);
        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'base_repoint_cfb')
            ->where('loser_bank_id', self::MYR_LOSER_ID)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 3: survivor 已有同 cash_flow → loser cfb 停用（不 repoint、不撞唯一鍵）
    // -------------------------------------------------------------------------
    public function test_execute_deactivates_cfb_on_collision(): void
    {
        $this->seedGroups();
        $survivorCfbId = $this->insertCashFlowBank(9976, 2, self::SGD_SURVIVOR_ID);
        $loserCfbId = $this->insertCashFlowBank(9976, 2, self::SGD_LOSER_ID);

        $this->runExecute();

        $loserCfb = DB::table('cash_flow_banks')->find($loserCfbId);
        $this->assertSame(self::SGD_LOSER_ID, (int) $loserCfb->bank_id);
        $this->assertSame(0, (int) $loserCfb->status);

        $survivorCfb = DB::table('cash_flow_banks')->find($survivorCfbId);
        $this->assertSame(self::SGD_SURVIVOR_ID, (int) $survivorCfb->bank_id);
        $this->assertSame(1, (int) $survivorCfb->status);

        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'base_deactivate_cfb')
            ->where('loser_bank_id', self::SGD_LOSER_ID)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 4: 全部 loser bank 停用＋標 deleted_at、survivor 不動
    // -------------------------------------------------------------------------
    public function test_execute_deactivates_and_marks_all_loser_banks(): void
    {
        $this->seedGroups();

        $this->runExecute();

        $loserIds = [];
        $survivorIds = [];
        foreach (MergeBaseDuplicateBanks::GROUPS as $group) {
            $survivorIds[] = $group['survivor_id'];
            foreach ($group['loser_ids'] as $loserId) {
                $loserIds[] = $loserId;
            }
        }

        $this->assertSame(0, DB::table('banks')->whereIn('id', $loserIds)->where('status', 1)->count());
        $this->assertSame(0, DB::table('banks')->whereIn('id', $loserIds)->whereNull('deleted_at')->count());
        $this->assertSame(count($survivorIds), DB::table('banks')
            ->whereIn('id', $survivorIds)
            ->where('status', 1)
            ->whereNull('deleted_at')
            ->count());
        $this->assertSame(count($loserIds), DB::table('bank_dedup_logs')->where('action', 'base_deactivate_bank')->count());
    }

    // -------------------------------------------------------------------------
    // Test 5: loser 有會員綁定 → 整體攔截零寫入
    // -------------------------------------------------------------------------
    public function test_aborts_when_loser_has_member_binding(): void
    {
        $this->seedGroups();
        DB::table('member_bank_accounts')->insert([
            'member_id' => 910001,
            'bank_id' => self::MYR_LOSER_ID,
            'account_name' => 'ACC',
            'account_no' => 'NO-1',
            'status' => 1,
            'operator_cs' => 0,
        ]);

        $this->runExecute(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', self::MYR_LOSER_ID)->value('status'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', self::SGD_LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'base_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 6: loser 有歷史出款單 → 整體攔截零寫入
    // -------------------------------------------------------------------------
    public function test_aborts_when_loser_has_withdrawal_order(): void
    {
        $this->seedGroups();
        WithdrawalOrder::factory()->create(['member_id' => 910002, 'bank_id' => self::MYR_LOSER_ID]);

        $this->runExecute(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', self::MYR_LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'base_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 7: 配對表與 DB 不符（code 竄改）→ 整體攔截零寫入
    // -------------------------------------------------------------------------
    public function test_aborts_on_code_mismatch(): void
    {
        $this->seedGroups();
        DB::table('banks')->where('id', self::MYR_LOSER_ID)->update(['code' => 'TAMPERED']);

        $this->runExecute(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', self::SGD_LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'base_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 8: 冪等：第二次執行零新增動作
    // -------------------------------------------------------------------------
    public function test_second_run_is_idempotent(): void
    {
        $this->seedGroups();
        $this->insertCashFlowBank(9923, 1, self::MYR_LOSER_ID);

        $this->runExecute();
        $auditCountAfterFirst = DB::table('bank_dedup_logs')->where('action', 'like', 'base_%')->count();

        $this->runExecute();
        $auditCountAfterSecond = DB::table('bank_dedup_logs')->where('action', 'like', 'base_%')->count();

        $this->assertSame($auditCountAfterFirst, $auditCountAfterSecond);
    }
}
