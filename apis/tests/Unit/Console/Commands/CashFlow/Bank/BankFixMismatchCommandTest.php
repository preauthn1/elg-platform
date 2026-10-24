<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\CashFlow\Bank;

use App\Helpers\StandardBankNameHelper;
use App\Models\Bank;
use Database\Seeders\SyncCurrenciesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BankFixMismatchCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SyncCurrenciesSeeder::class);
        StandardBankNameHelper::clearCache();
    }

    protected function tearDown(): void
    {
        StandardBankNameHelper::clearCache();
        parent::tearDown();
    }

    private function makeBank(string $name, string $code): Bank
    {
        return Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => $name,
            'code' => $code,
            'currency_id' => 1, // MYR
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 999999, 'sort' => 0,
            'status' => 1, 'admin' => 0,
        ]);
    }

    public function test_default_mode_does_not_write(): void
    {
        $bank = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_DEFAULT');

        $this->artisan('bank:fix-mismatch', ['--currency' => 'MYR'])
            ->assertSuccessful();

        $this->assertSame('(MYR) - Maybank2u', $bank->fresh()->name, 'Default mode should not write');
    }

    public function test_apply_dry_run_does_not_write(): void
    {
        $bank = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_DRY');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame('(MYR) - Maybank2u', $bank->fresh()->name, 'Dry-run should not write');
    }

    public function test_apply_renames_mismatched_bank(): void
    {
        $bank = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_APPLY');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--force' => true,
            // --apply 強制要求備份決定；測試不跑真備份，改用明示跳過（--force 免二次確認）
            '--skip-backup' => true,
        ])->assertSuccessful();

        $this->assertSame('(MYR) - Maybank', $bank->fresh()->name);
    }

    public function test_unknown_currency_returns_failure(): void
    {
        $this->artisan('bank:fix-mismatch', ['--currency' => 'XXX'])
            ->assertFailed();
    }

    /**
     * 這命令沒有回滾工具，--backup 的快照是唯一的 before 值來源，所以不能是「可選」。
     */
    public function test_apply_without_backup_decision_is_blocked_and_writes_nothing(): void
    {
        $bank = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_NOBK');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame(
            '(MYR) - Maybank2u',
            $bank->fresh()->name,
            '沒做備份決定就被擋下時，不得有任何寫入'
        );
    }

    public function test_skip_backup_without_force_requires_confirmation(): void
    {
        $bank = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_SKBK');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--skip-backup' => true,
        ])
            ->expectsConfirmation('確認不備份就執行？', 'no')
            ->assertFailed();

        $this->assertSame('(MYR) - Maybank2u', $bank->fresh()->name, '答 no 不得寫入');
    }

    /**
     * --dry-run 只讀不寫，不該被備份把關擋住——否則預演本身變成要先做決定才跑得動。
     */
    public function test_dry_run_does_not_require_backup_decision(): void
    {
        $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_DRYBK');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
        ])->assertSuccessful();
    }

    private function makeCfb(int $cashFlowId, int $bankId, string $code, string $providerCode, int $status): int
    {
        return (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => $cashFlowId,
            'currency_id' => 1, // MYR
            'bank_id' => $bankId,
            'bank_code' => $code,
            'provider_bank_code' => $providerCode,
            'status' => $status,
        ]);
    }

    /**
     * prune 是 DELETE，備份表是 --recreate-missing 重建的唯一來源，不容明示跳過。
     */
    public function test_prune_with_skip_backup_is_rejected(): void
    {
        $bank = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_PSB');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--skip-backup' => true,
            '--prune-inactive-collisions' => true,
            '--force' => true,
        ])->assertFailed();

        $this->assertSame('(MYR) - Maybank2u', $bank->fresh()->name, '被擋下時不得有任何寫入');
    }

    /**
     * prune gate 放行面：恰一側停用＋停用側碼有活列承接 ⇒ 預演顯示將刪、且不寫入。
     */
    public function test_prune_dry_run_previews_deletion_of_inactive_collision(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_FIX_PRP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_PRD');

        // primary 側停用列與 dup 側活列同槽位撞鍵；dup 活列即停用碼的承接列（同 provider 碼）
        $inactiveId = $this->makeCfb(9001, $primary->id, 'TEST_FIX_PRP', 'PVD9', 0);
        $activeId = $this->makeCfb(9001, $dup->id, 'TEST_FIX_PRD', 'PVD9', 1);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
        ])
            ->expectsOutputToContain(sprintf('將刪撞鍵冗餘列 cfb id=%d', $inactiveId))
            ->assertSuccessful();

        $this->assertNotNull(DB::table('cash_flow_banks')->find($inactiveId), '預演不得刪列');
        $this->assertSame($dup->id, (int) DB::table('cash_flow_banks')->find($activeId)->bank_id, '預演不得 repoint');
    }

    /**
     * prune gate 擋下面之一：兩側皆活且 provider 碼不同＝真要人工挑碼，不得刪任何一側。
     */
    public function test_prune_dry_run_blocks_when_both_sides_active_with_different_provider_codes(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_FIX_BAP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_BAD');

        $this->makeCfb(9001, $primary->id, 'TEST_FIX_BAP', 'PVDA', 1);
        $this->makeCfb(9001, $dup->id, 'TEST_FIX_BAD', 'PVDB', 1);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
        ])
            ->expectsOutputToContain('兩側皆 status=1 且 provider 碼不同（primary "PVDA" vs duplicate "PVDB"），須人工挑碼')
            ->assertSuccessful();
    }

    /**
     * gate 4（2026-08-26 擴充）：兩側皆活但送三方的碼嚴格相同 ⇒ 挑碼問題不存在，
     * 刪 duplicate 側（primary 側的 bank_code 與合併後的 banks.code 天然一致）。
     */
    public function test_prune_dry_run_deletes_duplicate_side_when_provider_codes_match(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_FIX_SCP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_SCD');

        $this->makeCfb(9001, $primary->id, 'TEST_FIX_SCP', 'PVDS', 1);
        $dupCfbId = $this->makeCfb(9001, $dup->id, 'TEST_FIX_SCD', 'PVDS', 1);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
        ])
            ->expectsOutputToContain(sprintf('將刪撞鍵冗餘列 cfb id=%d', $dupCfbId))
            ->assertSuccessful();

        $this->assertNotNull(DB::table('cash_flow_banks')->find($dupCfbId), '預演不得刪列');
    }

    /**
     * prune gate 擋下面之二：停用側碼查無活列承接——「status=0」單獨不能證明配置已移除
     * （sync 有 matching collision 情境），一律留置請人工確認。
     */
    public function test_prune_dry_run_blocks_when_no_successor(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_FIX_NSP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_NSD');

        // 停用側碼 PVDX 沒有任何活列承接（dup 活列載的是別的碼）
        $this->makeCfb(9001, $primary->id, 'TEST_FIX_NSP', 'PVDX', 0);
        $this->makeCfb(9001, $dup->id, 'TEST_FIX_NSD', 'PVDY', 1);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
        ])
            ->expectsOutputToContain('查無活列承接')
            ->assertSuccessful();
    }

    /**
     * all-or-nothing：同一 duplicate 兩個撞點、一合格一不合格 ⇒ 整組留置，
     * 不得出現「沒合併但列已被刪」的半套（Codex 複查第 5 點）。
     */
    public function test_prune_is_all_or_nothing_across_collisions(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_FIX_AOP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_AOD');

        // 撞點一（cf 9001）：合格——primary 側停用、dup 側活承接
        $prunableId = $this->makeCfb(9001, $primary->id, 'TEST_FIX_AOP', 'PVDC', 0);
        $this->makeCfb(9001, $dup->id, 'TEST_FIX_AOD', 'PVDC', 1);

        // 撞點二（cf 9002）：不合格——兩側皆活
        $this->makeCfb(9002, $primary->id, 'TEST_FIX_AOP', 'PVDD', 1);
        $this->makeCfb(9002, $dup->id, 'TEST_FIX_AOD', 'PVDE', 1);

        // dry-run 驗 gate 分類（實跑版在 BankFixMismatchBackupCycleTest——備份 DDL 會打破本類的交易包裹）
        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
        ])
            ->expectsOutputToContain('prune 判準未全數通過、整組留置（all-or-nothing）')
            ->assertSuccessful();

        $this->assertNotNull(
            DB::table('cash_flow_banks')->find($prunableId),
            '合格撞點的停用列也不得被刪——整組必須零寫入留置'
        );
        $this->assertSame(1, (int) $dup->fresh()->status, '留置組的 duplicate 不得被停用');
    }

    /**
     * group 層合併（--agent-group，2026-08-26 新增）：稽核該 group 的 banks，且 ACF JSON
     * 改寫只限該 group——其他 group 還活著的同字串碼不得被改（TPower g674 IDR0025 實證：
     * 全庫改寫弄斷 group 活碼的送碼鏈）。
     */
    public function test_agent_group_scope_limits_acf_rewrite_to_that_group(): void
    {
        $primary = Bank::create([
            'agent_group_id' => 9601, 'type' => 1, 'name' => '(MYR) - Maybank', 'code' => 'TEST_FIX_GP',
            'currency_id' => 1, 'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 999999, 'sort' => 0, 'status' => 1, 'admin' => 0,
        ]);
        $dup = Bank::create([
            'agent_group_id' => 9601, 'type' => 1, 'name' => '(MYR) - Maybank2u', 'code' => 'TEST_FIX_GD',
            'currency_id' => 1, 'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 999999, 'sort' => 0, 'status' => 1, 'admin' => 0,
        ]);

        DB::table('cash_flows')->insert([
            'id' => 9602, 'name' => 'Test Payout', 'info' => '[]',
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);
        $entries = ['available_banks' => [['BankCode_ELG' => 'TEST_FIX_GD', 'BankCode_Third' => 'T1']]];
        $inGroupAcf = (int) DB::table('agent_cash_flows')->insertGetId([
            'cash_flow_id' => 9602, 'agent_group_id' => 9601, 'currency_id' => 1,
            'info' => json_encode($entries), 'admin' => 0,
        ]);
        $otherGroupAcf = (int) DB::table('agent_cash_flows')->insertGetId([
            'cash_flow_id' => 9602, 'agent_group_id' => 9609, 'currency_id' => 1,
            'info' => json_encode($entries), 'admin' => 0,
        ]);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--agent-group' => '9601',
            '--apply' => true,
            '--force' => true,
            '--skip-backup' => true,
        ])->assertSuccessful();

        $this->assertSame(0, (int) $dup->fresh()->status, 'group 層 duplicate 要被合併停用');
        $this->assertSame(1, (int) $primary->fresh()->status);

        $inGroupJson = (string) DB::table('agent_cash_flows')->where('id', $inGroupAcf)->value('info');
        $otherJson = (string) DB::table('agent_cash_flows')->where('id', $otherGroupAcf)->value('info');
        $this->assertStringContainsString('TEST_FIX_GP', $inGroupJson, '同 group ACF 的碼要被改成 primary');
        $this->assertStringContainsString('TEST_FIX_GD', $otherJson, '其他 group 的 ACF 不得被改（碼可能仍在服務）');
    }

    public function test_primary_option_makes_named_row_the_survivor(): void
    {
        // 低 id 者是 auditor 的預設 primary；--primary 指名高 id 者反轉方向
        $defaultPrimary = $this->makeBank('(MYR) - Maybank', 'TEST_PRI_LOW');
        $named = $this->makeBank('(MYR) - Maybank2u', 'TEST_PRI_HI');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--primary' => (string) $named->id,
            '--apply' => true,
            '--force' => true,
            '--skip-backup' => true,
        ])->assertSuccessful();

        $this->assertSame(1, (int) $named->fresh()->status, '指名者必須存活');
        $this->assertSame(0, (int) $defaultPrimary->fresh()->status, '原預設 primary 降為 duplicate 並被停用');
    }

    public function test_primary_option_not_in_any_group_fails(): void
    {
        $this->makeBank('(MYR) - Maybank', 'TEST_PRI_MISS');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--primary' => '99999999',
            '--apply' => true,
            '--force' => true,
            '--skip-backup' => true,
        ])->assertFailed();
    }

    /**
     * gate 5（2026-08-27 RB 拍板）保 duplicate 側：兩側皆活、碼不同（gate 4 會擋），
     * 人工挑碼指名保 duplicate ⇒ 刪 primary 側 cfb。VWorld `709←731`／`721←728` 情境：
     * primary 側的碼不在三方該幣別的有效清單內，有效碼在 duplicate 側。
     */
    public function test_collision_keep_duplicate_prunes_primary_side_cfb(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_CK_DP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_CK_DD');

        $primaryCfbId = $this->makeCfb(9001, $primary->id, 'TEST_CK_DP', 'BADCODE', 1);
        $dupCfbId = $this->makeCfb(9001, $dup->id, 'TEST_CK_DD', 'GOODCODE', 1);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
            '--bank' => (string) $dup->id,
            '--collision-keep' => 'duplicate',
        ])
            ->expectsOutputToContain(sprintf('將刪撞鍵冗餘列 cfb id=%d', $primaryCfbId))
            ->assertSuccessful();

        $this->assertNotNull(DB::table('cash_flow_banks')->find($primaryCfbId), '預演不得刪列');
        $this->assertNotNull(DB::table('cash_flow_banks')->find($dupCfbId));
    }

    /**
     * gate 5 保 primary 側：同一旗標的反方向（VWorld `989←1098` 情境）。
     */
    public function test_collision_keep_primary_prunes_duplicate_side_cfb(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_CK_PP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_CK_PD');

        $primaryCfbId = $this->makeCfb(9001, $primary->id, 'TEST_CK_PP', 'GOODCODE', 1);
        $dupCfbId = $this->makeCfb(9001, $dup->id, 'TEST_CK_PD', 'BADCODE', 1);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
            '--bank' => (string) $dup->id,
            '--collision-keep' => 'primary',
        ])
            ->expectsOutputToContain(sprintf('將刪撞鍵冗餘列 cfb id=%d', $dupCfbId))
            ->assertSuccessful();

        $this->assertNotNull(DB::table('cash_flow_banks')->find($primaryCfbId));
    }

    /**
     * gate 5 也覆寫 gate 2 的承接查詢：停用側碼查無活列承接（gate 2 會擋），
     * 人工確認該碼已無效後指名保 primary ⇒ 放行刪停用的 duplicate 側列。
     */
    public function test_collision_keep_overrides_successor_gate(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_CK_SP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_CK_SD');

        $this->makeCfb(9001, $primary->id, 'TEST_CK_SP', 'NEWSPELLING', 1);
        $dupCfbId = $this->makeCfb(9001, $dup->id, 'TEST_CK_SD', 'OLD SPELLING', 0);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
            '--bank' => (string) $dup->id,
            '--collision-keep' => 'primary',
        ])
            ->expectsOutputToContain(sprintf('將刪撞鍵冗餘列 cfb id=%d', $dupCfbId))
            ->assertSuccessful();
    }

    /**
     * gate 5 唯一仍 fail-closed 的情形：指名保留的那側 status=0——放行等於刪掉唯一活列、
     * 換上停用列，服務會斷；人工挑碼的授權不涵蓋這件事。
     */
    public function test_collision_keep_blocks_when_kept_side_is_inactive(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_CK_IP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_CK_ID');

        $this->makeCfb(9001, $primary->id, 'TEST_CK_IP', 'ACTIVECODE', 1);
        $this->makeCfb(9001, $dup->id, 'TEST_CK_ID', 'DEADCODE', 0);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
            '--bank' => (string) $dup->id,
            '--collision-keep' => 'duplicate',
        ])
            ->expectsOutputToContain('指定保留的那側 cfb status=0')
            ->assertSuccessful();
    }

    /**
     * gate 5 的兩道硬性前置與取值檢查：單獨帶等於靜默無效、不限單一組等於盲套。
     */
    public function test_collision_keep_requires_prune_flag_and_bank_filter(): void
    {
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_CK_GUARD');

        // 未搭 --prune-inactive-collisions
        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--bank' => (string) $dup->id,
            '--collision-keep' => 'primary',
        ])
            ->expectsOutputToContain('必須搭 --prune-inactive-collisions')
            ->assertFailed();

        // 未搭 --bank=
        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
            '--collision-keep' => 'primary',
        ])
            ->expectsOutputToContain('必須搭 --bank=')
            ->assertFailed();

        // 取值不合法
        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
            '--prune-inactive-collisions' => true,
            '--bank' => (string) $dup->id,
            '--collision-keep' => 'whichever',
        ])
            ->expectsOutputToContain('--collision-keep 只接受 primary|duplicate')
            ->assertFailed();
    }

    /**
     * gate 5 不鬆綁強制備份：撞點放行照樣是 DELETE，--skip-backup 仍整支拒絕、零寫入。
     */
    public function test_collision_keep_still_requires_backup(): void
    {
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_CK_AP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_CK_AD');

        $primaryCfbId = $this->makeCfb(9001, $primary->id, 'TEST_CK_AP', 'BADCODE', 1);
        $dupCfbId = $this->makeCfb(9001, $dup->id, 'TEST_CK_AD', 'GOODCODE', 1);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--force' => true,
            '--skip-backup' => true,
            '--prune-inactive-collisions' => true,
            '--bank' => (string) $dup->id,
            '--collision-keep' => 'duplicate',
        ])->assertFailed();

        $this->assertNotNull(
            DB::table('cash_flow_banks')->find($primaryCfbId),
            'prune 強制備份：--skip-backup 必須整支拒絕，零寫入',
        );
        $this->assertNotNull(DB::table('cash_flow_banks')->find($dupCfbId));
    }

    /**
     * --include-inactive＝停用列的命名盤點（純報表）。原始動機是想在正式組上線前攔到
     * drift，但實測 status=0 列絕大多數是已退場舊列 ⇒ 定位改為盤點、刻意不給修。
     */
    public function test_include_inactive_audits_disabled_rows(): void
    {
        $active = $this->makeBank('(MYR) - Maybank2u', 'TEST_INA_ON');
        $inactive = $this->makeBank('(MYR) - Maybank2u', 'TEST_INA_OFF');
        DB::table('banks')->where('id', $inactive->id)->update(['status' => 0]);

        // 不帶旗標：停用列不在稽核視野內
        $this->artisan('bank:fix-mismatch', ['--currency' => 'MYR'])
            ->expectsOutputToContain(sprintf('id=%d', $active->id))
            ->doesntExpectOutputToContain(sprintf('id=%d', $inactive->id))
            ->assertSuccessful();

        // 帶旗標：停用列的 drift 一併現形
        $this->artisan('bank:fix-mismatch', ['--currency' => 'MYR', '--include-inactive' => true])
            ->expectsOutputToContain(sprintf('id=%d', $inactive->id))
            ->assertSuccessful();

        $this->assertSame('(MYR) - Maybank2u', $inactive->fresh()->name, '稽核不得寫入');
    }

    /**
     * --include-inactive 放行 status=0 後，軟刪列會大量混入（它們多為 status=0）——
     * 那些是已退場的列、不是待修 drift，新路徑排除。預設路徑的行為刻意保持原狀。
     */
    public function test_include_inactive_excludes_soft_deleted_rows(): void
    {
        $trashed = $this->makeBank('(MYR) - Maybank2u', 'TEST_INA_DEL');
        DB::table('banks')->where('id', $trashed->id)->update(['status' => 0, 'deleted_at' => now()]);

        $this->artisan('bank:fix-mismatch', ['--currency' => 'MYR', '--include-inactive' => true])
            ->doesntExpectOutputToContain(sprintf('id=%d', $trashed->id))
            ->assertSuccessful();
    }

    /**
     * 已退場的舊列不該被 rename 成 canonical 名（會讓歷史單據的 bank_name snapshot
     * 與現況對不上）⇒ 旗標只出報表，fail-closed 拒絕 --apply。
     */
    public function test_include_inactive_rejects_apply(): void
    {
        $bank = $this->makeBank('(MYR) - Maybank2u', 'TEST_INA_APL');
        DB::table('banks')->where('id', $bank->id)->update(['status' => 0]);

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--include-inactive' => true,
            '--apply' => true,
            '--force' => true,
            '--skip-backup' => true,
        ])
            ->expectsOutputToContain('不可與 --apply 併用')
            ->assertFailed();

        $this->assertSame('(MYR) - Maybank2u', $bank->fresh()->name);
    }

    /**
     * 曾作為 loser 被收斂停用的列必須排除：它們的名稱不會被 rename（rename 只處理
     * status=1），所以必然報 mismatch、也必然與存活方配成 duplicate＝正常退場狀態。
     * 不濾掉的話報表會被舊列洗掉（TPower 實測 IDR duplicate 13→0、SGD 1→0）。
     */
    public function test_include_inactive_excludes_previously_converged_losers(): void
    {
        $survivor = $this->makeBank('(MYR) - Maybank', 'TEST_INA_SUR');
        $loser = $this->makeBank('(MYR) - Maybank2u', 'TEST_INA_LOS');
        DB::table('banks')->where('id', $loser->id)->update(['status' => 0]);

        DB::table('bank_dedup_logs')->insert([
            'connection' => DB::connection()->getName(),
            'agent_group_id' => 0,
            'code' => 'TEST_INA_SUR',
            'survivor_bank_id' => $survivor->id,
            'loser_bank_id' => $loser->id,
            'action' => 'mismatch_deactivate_bank',
            'detail' => null,
        ]);

        $this->artisan('bank:fix-mismatch', ['--currency' => 'MYR', '--include-inactive' => true])
            ->doesntExpectOutputToContain(sprintf('id=%d', $loser->id))
            ->assertSuccessful();
    }

    /**
     * --bank 單點過濾：同幣別兩組 duplicate，只處理點名那組。
     */
    public function test_bank_filter_limits_to_named_group(): void
    {
        $primaryA = $this->makeBank('(MYR) - Maybank', 'TEST_FIX_FA1');
        $dupA = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_FA2');
        $primaryB = $this->makeBank('(MYR) - Public Bank', 'TEST_FIX_FB1');
        $dupB = $this->makeBank('(MYR) - Public Bank Berhad', 'TEST_FIX_FB2');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--bank' => (string) $dupA->id,
            '--apply' => true,
            '--force' => true,
            '--skip-backup' => true,
        ])->assertSuccessful();

        $this->assertSame(0, (int) $dupA->fresh()->status, '點名組要被合併');
        $this->assertSame(1, (int) $dupB->fresh()->status, '未點名組不得被動到');
        $this->assertSame('(MYR) - Public Bank Berhad', $dupB->fresh()->name, '未點名組連 rename 都不得做');
        $this->assertSame(1, (int) $primaryA->fresh()->status);
        $this->assertSame(1, (int) $primaryB->fresh()->status);
    }

    /**
     * B1 的核心：dry-run 必須真的跑過合併預檢，而不是只印「打算合併哪幾個 id」。
     */
    public function test_dry_run_runs_real_precheck_on_duplicates(): void
    {
        // 兩列都解析到同一 standard（Maybank）⇒ 被判為 duplicate 組
        $primary = $this->makeBank('(MYR) - Maybank', 'TEST_FIX_PVP');
        $dup = $this->makeBank('(MYR) - Maybank2u', 'TEST_FIX_PVD');

        $this->artisan('bank:fix-mismatch', [
            '--currency' => 'MYR',
            '--apply' => true,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain(sprintf('可合併 id=%d', $dup->id))
            ->assertSuccessful();

        $this->assertSame(1, $dup->fresh()->status, '預演不得停用 duplicate');
        $this->assertSame('(MYR) - Maybank2u', $dup->fresh()->name, '預演不得 rename');
        $this->assertSame(1, $primary->fresh()->status);
    }
}
