<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\Bank;
use Database\Seeders\SyncCurrenciesSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `bank:fix-mismatch --absorb-inactive` 的命令層守衛：前置條件 fail-closed、預演零寫入、
 * 實跑只移轉綁定。全程不帶 `--backup`（無 DDL），維持 DatabaseTransactions 正常包裹。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Bank/BankFixMismatchAbsorbInactiveTest.php
 */
class BankFixMismatchAbsorbInactiveTest extends TestCase
{
    /** 測試專用 agent group 與會員 id，避開真實資料 */
    private const GROUP_ID = 970_001;

    private const MEMBER_ID = 970_001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SyncCurrenciesSeeder::class);
    }

    private function makeBank(string $name, string $code, int $status, int $groupId = self::GROUP_ID): Bank
    {
        return Bank::create([
            'agent_group_id' => $groupId,
            'type' => 4,
            'name' => $name,
            'code' => $code,
            'currency_id' => 1,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 999999, 'sort' => 0,
            'status' => $status, 'admin' => 0,
        ]);
    }

    private function bindMember(Bank $bank): int
    {
        return (int) DB::table('member_bank_accounts')->insertGetId([
            'member_id' => self::MEMBER_ID, 'bank_id' => $bank->id,
            'account_name' => 'TEST NAME', 'account_no' => '0000000000',
            'status' => 1, 'operator_cs' => 0,
        ]);
    }

    public function test_requires_apply_and_primary(): void
    {
        $primary = $this->makeBank('(MYR) - Absorb Primary', 'TEST_ABS_CMD_P1', 1);
        $dup = $this->makeBank('(MYR) - Absorb Dup', 'TEST_ABS_CMD_D1', 0);

        $this->artisan('bank:fix-mismatch', [
            '--absorb-inactive' => (string) $dup->id,
            '--primary' => (string) $primary->id,
        ])->expectsOutputToContain('--apply')->assertFailed();

        $this->artisan('bank:fix-mismatch', [
            '--absorb-inactive' => (string) $dup->id,
            '--apply' => true,
            '--dry-run' => true,
        ])->expectsOutputToContain('--primary')->assertFailed();
    }

    public function test_rejects_active_duplicate_and_cross_group_pair(): void
    {
        $primary = $this->makeBank('(MYR) - Absorb Primary', 'TEST_ABS_CMD_P2', 1);
        $activeDup = $this->makeBank('(MYR) - Absorb Active Dup', 'TEST_ABS_CMD_D2', 1);
        $otherGroupDup = $this->makeBank('(MYR) - Absorb Other Group', 'TEST_ABS_CMD_D3', 0, self::GROUP_ID + 1);

        $this->artisan('bank:fix-mismatch', [
            '--absorb-inactive' => (string) $activeDup->id,
            '--primary' => (string) $primary->id,
            '--apply' => true, '--dry-run' => true, '--force' => true,
        ])->expectsOutputToContain('不是停用列')->assertFailed();

        $this->artisan('bank:fix-mismatch', [
            '--absorb-inactive' => (string) $otherGroupDup->id,
            '--primary' => (string) $primary->id,
            '--apply' => true, '--dry-run' => true, '--force' => true,
        ])->expectsOutputToContain('agent group')->assertFailed();

        $this->assertSame(1, (int) $activeDup->fresh()->status, '前置檢查失敗不得動到任何列');
    }

    public function test_dry_run_previews_merge_and_writes_nothing(): void
    {
        $primary = $this->makeBank('(MYR) - Absorb Primary', 'TEST_ABS_CMD_P3', 1);
        $dup = $this->makeBank('(MYR) - Absorb Dup', 'TEST_ABS_CMD_D4', 0);
        $bindingId = $this->bindMember($dup);

        $this->artisan('bank:fix-mismatch', [
            '--absorb-inactive' => (string) $dup->id,
            '--primary' => (string) $primary->id,
            '--apply' => true, '--dry-run' => true, '--force' => true,
        ])->expectsOutputToContain('可合併')->assertSuccessful();

        $this->assertSame($dup->id, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'), '預演不得改指');
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('loser_bank_id', $dup->id)->count(), '預演不得留審計');
    }

    public function test_apply_repoints_binding_and_audits(): void
    {
        $primary = $this->makeBank('(MYR) - Absorb Primary', 'TEST_ABS_CMD_P4', 1);
        $dup = $this->makeBank('(MYR) - Absorb Dup', 'TEST_ABS_CMD_D5', 0);
        $bindingId = $this->bindMember($dup);

        $this->artisan('bank:fix-mismatch', [
            '--absorb-inactive' => (string) $dup->id,
            '--primary' => (string) $primary->id,
            '--apply' => true, '--skip-backup' => true, '--force' => true,
        ])->assertSuccessful();

        $this->assertSame($primary->id, (int) DB::table('member_bank_accounts')->where('id', $bindingId)->value('bank_id'), '綁定改指到承接列');
        $this->assertSame(0, (int) $dup->fresh()->status);
        $this->assertSame(1, (int) $primary->fresh()->status);
        $this->assertSame(
            1,
            DB::table('bank_dedup_logs')->where('loser_bank_id', $dup->id)->where('action', 'mismatch_inactive_merged')->count(),
            '人工點名併入必須留獨立審計',
        );
    }
}
