<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/DedupActiveBanksCommandTest.php
 */
class DedupActiveBanksCommandTest extends TestCase
{
    use DetachesBanksActiveGuard;

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeBank(array $attrs = []): int
    {
        return (int) DB::table('banks')->insertGetId(array_merge([
            'agent_group_id' => 674,
            'type' => 1,
            'name' => '(MYR) - AGRO Bank',
            'code' => 'MYR0023',
            'currency_id' => 1,
            'status' => 1,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ], $attrs));
    }

    public function test_dry_run_reports_duplicate_groups_without_writing(): void
    {
        $this->makeBank(['created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $this->artisan('bank:dedup-active')
            ->expectsOutputToContain('MYR0023')
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        // dry-run 不得寫入：兩列仍為 active
        $this->assertSame(2, DB::table('banks')->where('status', 1)->count());
    }

    public function test_dry_run_reports_nothing_when_no_duplicates(): void
    {
        $this->makeBank(['code' => 'MYR0023']);
        $this->makeBank(['code' => 'MYR0035']);

        $this->artisan('bank:dedup-active')
            ->expectsOutputToContain('0')
            ->assertExitCode(0);

        $this->assertSame(2, DB::table('banks')->where('status', 1)->count());
    }

    public function test_execute_performs_cleanup_after_confirmation(): void
    {
        $loserId = $this->makeBank(['created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $this->artisan('bank:dedup-active --execute')
            ->expectsConfirmation('確認執行清理？此操作將合併會員綁定並停用重複銀行', 'yes')
            ->expectsOutputToContain('清理完成')
            ->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('banks')->where('id', $loserId)->value('status'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', $survivorId)->value('status'));
    }

    public function test_execute_reports_flagged_code_reuse_groups(): void
    {
        // 同 code 不同銀行 + loser 有綁定 → 命令應回報人工複核且不合併
        $loserId = $this->makeBank(['name' => '(MYR) - BSN', 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['name' => '(MYR) - Duitnow QR', 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        DB::table('member_bank_accounts')->insert([
            'member_id' => 9001, 'bank_id' => $loserId, 'account_name' => 'T', 'account_no' => '1',
            'status' => 1, 'sort' => 0, 'operator_cs' => 0,
            'created_at' => Carbon::parse('2026-01-01'), 'updated_at' => Carbon::parse('2026-01-01'),
        ]);

        $this->artisan('bank:dedup-active --execute --force')
            ->expectsOutputToContain('人工複核')
            ->assertExitCode(0);

        // flagged 組不得合併
        $this->assertSame(1, (int) DB::table('banks')->where('id', $loserId)->value('status'));
    }

    public function test_execute_aborts_when_confirmation_declined(): void
    {
        $loserId = $this->makeBank(['created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $this->artisan('bank:dedup-active --execute')
            ->expectsConfirmation('確認執行清理？此操作將合併會員綁定並停用重複銀行', 'no')
            ->assertExitCode(0);

        // 取消 → 不得寫入
        $this->assertSame(1, (int) DB::table('banks')->where('id', $loserId)->value('status'));
    }

    public function test_dry_run_reports_inactive_shadow_groups_without_writing(): void
    {
        // TPower AGRO 形態：同名同碼 1 active + 1 inactive shadow
        // 斷言字串不可互相重疊（'shadow' + 'dry-run' 會被同一行 info 的首個 Mockery expectation 吃掉）
        $shadowId = $this->makeBank(['status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $this->artisan('bank:dedup-active')
            ->expectsOutputToContain('shadow_ids')
            ->expectsOutputToContain('dry-run')
            ->assertExitCode(0);

        $this->assertNull(DB::table('banks')->where('id', $shadowId)->value('deleted_at'));
    }

    public function test_execute_cleans_inactive_shadow_groups(): void
    {
        $shadowId = $this->makeBank(['status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $survivorId = $this->makeBank(['status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $this->artisan('bank:dedup-active --execute --force')
            ->expectsOutputToContain('shadow')
            ->assertExitCode(0);

        $this->assertNotNull(DB::table('banks')->where('id', $shadowId)->value('deleted_at'));
        $this->assertNull(DB::table('banks')->where('id', $survivorId)->value('deleted_at'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', $survivorId)->value('status'));
    }

    public function test_agent_group_filter_limits_active_cleanup_scope(): void
    {
        // group 674 與 675 各一組 active 重複；只清 675，674 必須原封不動
        $untouchedLoserId = $this->makeBank(['agent_group_id' => 674, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['agent_group_id' => 674, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $targetLoserId = $this->makeBank(['agent_group_id' => 675, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $targetSurvivorId = $this->makeBank(['agent_group_id' => 675, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $this->artisan('bank:dedup-active --execute --force --agent-group=675')
            ->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('banks')->where('id', $targetLoserId)->value('status'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', $targetSurvivorId)->value('status'));
        $this->assertSame(1, (int) DB::table('banks')->where('id', $untouchedLoserId)->value('status'));
    }

    public function test_agent_group_filter_limits_shadow_cleanup_scope(): void
    {
        // group 674 與 675 各一組 shadow；只清 675，674 的 shadow 不得標刪
        $untouchedShadowId = $this->makeBank(['agent_group_id' => 674, 'status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['agent_group_id' => 674, 'status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);
        $targetShadowId = $this->makeBank(['agent_group_id' => 675, 'status' => 0, 'created_at' => Carbon::parse('2023-11-05 00:00:00')]);
        $this->makeBank(['agent_group_id' => 675, 'status' => 1, 'created_at' => Carbon::parse('2026-02-01 00:00:00')]);

        $this->artisan('bank:dedup-active --execute --force --agent-group=675')
            ->assertExitCode(0);

        $this->assertNotNull(DB::table('banks')->where('id', $targetShadowId)->value('deleted_at'));
        $this->assertNull(DB::table('banks')->where('id', $untouchedShadowId)->value('deleted_at'));
    }
}
