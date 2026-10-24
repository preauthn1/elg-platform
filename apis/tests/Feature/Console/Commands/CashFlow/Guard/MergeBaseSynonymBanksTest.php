<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Guard;

use App\Console\Commands\CashFlow\Guard\MergeBaseSynonymBanks;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * MergeBaseSynonymBanks 測試
 *
 * G2 階段二安全子集：VWorld base(group 0) IDR/VND 同行不同碼雙 active 收斂（S1）、
 * 停用列孤兒 cfb repoint（S2）。cfb 三分流：repoint 只改 bank_id（bank_code 一律不動，
 * 該欄承接會員 agent 層碼比對）、休眠渠道撞鍵停用、活躍渠道撞鍵留置。
 * 配對表寫死、防呆整體攔截；用 DatabaseTransactions＋配對表真實 id 建列（先清同 id 殘留）。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Patch/MergeBaseSynonymBanksTest.php
 */
class MergeBaseSynonymBanksTest extends TestCase
{
    use DatabaseTransactions;
    use DetachesBanksActiveGuard;

    /** S1 VND 樣本（repoint）：survivor 1163(VND0046) ← loser 661(VND0002) */
    private const P1_SURVIVOR_ID = 1163;

    private const P1_LOSER_ID = 661;

    /** S1 IDR 樣本（撞鍵）：survivor 701(IDR0004) ← loser 723(IDR0026) */
    private const P2_SURVIVOR_ID = 701;

    private const P2_LOSER_ID = 723;

    /** S2 孤兒樣本：dead 867(MayBank, 停用) → target 835(MayBank, active) */
    private const O1_DEAD_ID = 867;

    private const O1_TARGET_ID = 835;

    /** S2 孤兒樣本（撞鍵）：dead 770(IDR0073) → target 718(IDR0021) */
    private const O2_DEAD_ID = 770;

    private const O2_TARGET_ID = 718;

    /** 測試用虛構 cash_flow id（seed 時一併清 ACF 殘留） */
    private const TEST_CASH_FLOW_IDS = [9968, 9972, 9976, 9982];

    private function seedAll(): void
    {
        $bankIds = $this->allBankIds();

        DB::table('cash_flow_banks')->whereIn('bank_id', $bankIds)->delete();
        DB::table('member_bank_accounts')->whereIn('bank_id', $bankIds)->delete();
        DB::table('bank_dedup_logs')->where('action', 'like', 'syn_%')->delete();
        DB::table('agent_cash_flows')->whereIn('cash_flow_id', self::TEST_CASH_FLOW_IDS)->delete();
        DB::table('banks')->whereIn('id', $bankIds)->delete();

        foreach (MergeBaseSynonymBanks::PAIRS as $pair) {
            $this->insertBank($pair['survivor_id'], $pair['type'], $pair['currency_id'], $pair['survivor_code'], 1);
            $this->insertBank($pair['loser_id'], $pair['type'], $pair['currency_id'], $pair['loser_code'], 1);
        }

        foreach (MergeBaseSynonymBanks::ORPHANS as $orphan) {
            $this->insertBank($orphan['dead_id'], $orphan['type'], $orphan['currency_id'], $orphan['dead_code'], 0);
            // target 可能與其他 orphan 共用（如 835/836/842/848），已插過則略過
            if (DB::table('banks')->where('id', $orphan['target_id'])->doesntExist()) {
                $this->insertBank($orphan['target_id'], $orphan['type'], $orphan['currency_id'], $orphan['target_code'], 1);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function allBankIds(): array
    {
        $ids = [];
        foreach (MergeBaseSynonymBanks::PAIRS as $pair) {
            $ids[] = $pair['survivor_id'];
            $ids[] = $pair['loser_id'];
        }
        foreach (MergeBaseSynonymBanks::ORPHANS as $orphan) {
            $ids[] = $orphan['dead_id'];
            $ids[] = $orphan['target_id'];
        }

        return array_values(array_unique($ids));
    }

    private function insertBank(int $id, int $type, int $currencyId, string $code, int $status): void
    {
        DB::table('banks')->insert([
            'id' => $id,
            'agent_group_id' => 0,
            'type' => $type,
            'currency_id' => $currencyId,
            'code' => $code,
            'name' => 'Bank ' . $code . ' ' . $id,
            'status' => $status,
        ]);
    }

    private function insertCashFlowBank(int $cashFlowId, int $currencyId, int $bankId, string $bankCode, int $status = 1): int
    {
        return (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => $cashFlowId,
            'currency_id' => $currencyId,
            'bank_id' => $bankId,
            'bank_code' => $bankCode,
            'provider_bank_code' => 'P-' . $bankId,
            'status' => $status,
        ]);
    }

    /** 建立 active ACF 使該 cash_flow 判定為活躍渠道（撞鍵留置分支） */
    private function insertActiveAgentCashFlow(int $cashFlowId, int $currencyId): void
    {
        DB::table('agent_cash_flows')->insert([
            'cash_flow_info_id' => 0,
            'cash_flow_id' => $cashFlowId,
            'agent_group_id' => 990001,
            'currency_id' => $currencyId,
            'rate' => 1,
            'name' => 'TEST ACF',
            'info' => '{}',
            'sorts' => 0,
            'cost' => 0,
            'status' => 1,
            'backend_display' => 1,
            'admin' => 0,
        ]);
    }

    private function runExecute(int $expectedExitCode = 0): void
    {
        $this->artisan('bank:merge-base-synonym-banks', ['--execute' => true, '--force' => true])
            ->assertExitCode($expectedExitCode);
    }

    // -------------------------------------------------------------------------
    // Test 1: 預設 dry-run 零寫入
    // -------------------------------------------------------------------------
    public function test_dry_run_writes_nothing(): void
    {
        $this->seedAll();
        $loserCfbId = $this->insertCashFlowBank(9982, 3, self::P1_LOSER_ID, 'VND0002');
        $orphanCfbId = $this->insertCashFlowBank(9972, 1, self::O1_DEAD_ID, 'MYR0025');

        $this->artisan('bank:merge-base-synonym-banks')->assertExitCode(0);

        $this->assertSame(self::P1_LOSER_ID, (int) DB::table('cash_flow_banks')->find($loserCfbId)->bank_id);
        $this->assertSame(self::O1_DEAD_ID, (int) DB::table('cash_flow_banks')->find($orphanCfbId)->bank_id);
        $this->assertSame(1, (int) DB::table('banks')->where('id', self::P1_LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'syn_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 2: S1 無撞鍵 → loser cfb repoint（只改 bank_id、bank_code 不動）、loser 停用＋標刪
    // -------------------------------------------------------------------------
    public function test_execute_repoints_pair_cfb_without_touching_bank_code(): void
    {
        $this->seedAll();
        $cfbId = $this->insertCashFlowBank(9982, 3, self::P1_LOSER_ID, 'VND0002');

        $this->runExecute();

        $cfb = DB::table('cash_flow_banks')->find($cfbId);
        $this->assertSame(self::P1_SURVIVOR_ID, (int) $cfb->bank_id);
        $this->assertSame('VND0002', (string) $cfb->bank_code);
        $this->assertSame('P-' . self::P1_LOSER_ID, (string) $cfb->provider_bank_code);
        $this->assertSame(1, (int) $cfb->status);

        $loser = DB::table('banks')->find(self::P1_LOSER_ID);
        $this->assertSame(0, (int) $loser->status);
        $this->assertNotNull($loser->deleted_at);

        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'syn_repoint_cfb')
            ->where('loser_bank_id', self::P1_LOSER_ID)
            ->count());
        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'syn_deactivate_bank')
            ->where('loser_bank_id', self::P1_LOSER_ID)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 3: S1 撞鍵＋休眠渠道（零 active ACF）→ loser cfb 停用
    // -------------------------------------------------------------------------
    public function test_execute_deactivates_colliding_cfb_on_dormant_channel(): void
    {
        $this->seedAll();
        $survivorCfbId = $this->insertCashFlowBank(9976, 6, self::P2_SURVIVOR_ID, 'IDR0004');
        $loserCfbId = $this->insertCashFlowBank(9976, 6, self::P2_LOSER_ID, 'IDR0026');

        $this->runExecute();

        $loserCfb = DB::table('cash_flow_banks')->find($loserCfbId);
        $this->assertSame(self::P2_LOSER_ID, (int) $loserCfb->bank_id);
        $this->assertSame(0, (int) $loserCfb->status);

        $this->assertSame(1, (int) DB::table('cash_flow_banks')->find($survivorCfbId)->status);
        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'syn_deactivate_cfb')
            ->where('loser_bank_id', self::P2_LOSER_ID)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 4: S1 撞鍵＋活躍渠道（有 active ACF）→ loser cfb 留置不動、bank 照常停用
    // -------------------------------------------------------------------------
    public function test_execute_keeps_colliding_cfb_on_active_channel(): void
    {
        $this->seedAll();
        $this->insertCashFlowBank(9976, 6, self::P2_SURVIVOR_ID, 'IDR0004');
        $loserCfbId = $this->insertCashFlowBank(9976, 6, self::P2_LOSER_ID, 'IDR0026');
        $this->insertActiveAgentCashFlow(9976, 6);

        $this->runExecute();

        $loserCfb = DB::table('cash_flow_banks')->find($loserCfbId);
        $this->assertSame(self::P2_LOSER_ID, (int) $loserCfb->bank_id);
        $this->assertSame(1, (int) $loserCfb->status);
        $this->assertSame('IDR0026', (string) $loserCfb->bank_code);

        // 銀行列收斂不受留置影響
        $loser = DB::table('banks')->find(self::P2_LOSER_ID);
        $this->assertSame(0, (int) $loser->status);
        $this->assertNotNull($loser->deleted_at);

        $this->assertSame(0, DB::table('bank_dedup_logs')
            ->where('action', 'syn_deactivate_cfb')
            ->where('loser_bank_id', self::P2_LOSER_ID)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 5: S2 孤兒 cfb repoint（bank_code 不動）、dead 補標 deleted_at
    // -------------------------------------------------------------------------
    public function test_execute_repoints_orphan_cfb_and_soft_deletes_dead_bank(): void
    {
        $this->seedAll();
        $cfbId = $this->insertCashFlowBank(9972, 1, self::O1_DEAD_ID, 'MYR0025');

        $this->runExecute();

        $cfb = DB::table('cash_flow_banks')->find($cfbId);
        $this->assertSame(self::O1_TARGET_ID, (int) $cfb->bank_id);
        $this->assertSame('MYR0025', (string) $cfb->bank_code);
        $this->assertSame(1, (int) $cfb->status);

        $dead = DB::table('banks')->find(self::O1_DEAD_ID);
        $this->assertSame(0, (int) $dead->status);
        $this->assertNotNull($dead->deleted_at);

        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'syn_repoint_cfb')
            ->where('loser_bank_id', self::O1_DEAD_ID)
            ->count());
        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'syn_soft_delete_bank')
            ->where('loser_bank_id', self::O1_DEAD_ID)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 6: S2 撞鍵＋休眠渠道 → 孤兒 cfb 停用不 repoint
    // -------------------------------------------------------------------------
    public function test_execute_deactivates_colliding_orphan_cfb_on_dormant_channel(): void
    {
        $this->seedAll();
        $targetCfbId = $this->insertCashFlowBank(9968, 6, self::O2_TARGET_ID, 'IDR0021');
        $orphanCfbId = $this->insertCashFlowBank(9968, 6, self::O2_DEAD_ID, 'IDR0073');

        $this->runExecute();

        $orphanCfb = DB::table('cash_flow_banks')->find($orphanCfbId);
        $this->assertSame(self::O2_DEAD_ID, (int) $orphanCfb->bank_id);
        $this->assertSame(0, (int) $orphanCfb->status);

        $this->assertSame(1, (int) DB::table('cash_flow_banks')->find($targetCfbId)->status);
        $this->assertSame(1, DB::table('bank_dedup_logs')
            ->where('action', 'syn_deactivate_cfb')
            ->where('loser_bank_id', self::O2_DEAD_ID)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 7: 配對表與 DB 不符（code 竄改）→ 整體攔截零寫入
    // -------------------------------------------------------------------------
    public function test_aborts_on_code_mismatch(): void
    {
        $this->seedAll();
        DB::table('banks')->where('id', self::P1_LOSER_ID)->update(['code' => 'TAMPERED']);

        $this->runExecute(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', self::P2_LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'syn_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 8: 孤兒表的 dead 列被復活（status=1）→ 性質改變、整體攔截
    // -------------------------------------------------------------------------
    public function test_aborts_when_dead_bank_revived(): void
    {
        $this->seedAll();
        DB::table('banks')->where('id', self::O1_DEAD_ID)->update(['status' => 1]);

        $this->runExecute(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', self::P1_LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'syn_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 9: loser 有會員綁定 → 整體攔截零寫入
    // -------------------------------------------------------------------------
    public function test_aborts_when_loser_has_member_binding(): void
    {
        $this->seedAll();
        DB::table('member_bank_accounts')->insert([
            'member_id' => 920001,
            'bank_id' => self::P1_LOSER_ID,
            'account_name' => 'ACC',
            'account_no' => 'NO-1',
            'status' => 1,
            'operator_cs' => 0,
        ]);

        $this->runExecute(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', self::P1_LOSER_ID)->value('status'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'like', 'syn_%')->count());
    }

    // -------------------------------------------------------------------------
    // Test 10: 冪等：第二次執行零新增動作
    // -------------------------------------------------------------------------
    public function test_second_run_is_idempotent(): void
    {
        $this->seedAll();
        $this->insertCashFlowBank(9982, 3, self::P1_LOSER_ID, 'VND0002');
        $this->insertCashFlowBank(9972, 1, self::O1_DEAD_ID, 'MYR0025');
        $this->insertCashFlowBank(9976, 6, self::P2_SURVIVOR_ID, 'IDR0004');
        $this->insertCashFlowBank(9976, 6, self::P2_LOSER_ID, 'IDR0026');

        $this->runExecute();
        $auditCountAfterFirst = DB::table('bank_dedup_logs')->where('action', 'like', 'syn_%')->count();

        $this->runExecute();
        $auditCountAfterSecond = DB::table('bank_dedup_logs')->where('action', 'like', 'syn_%')->count();

        $this->assertSame($auditCountAfterFirst, $auditCountAfterSecond);
    }
}
