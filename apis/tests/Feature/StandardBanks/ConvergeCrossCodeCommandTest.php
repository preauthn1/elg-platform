<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use App\Models\Bank;
use App\Models\StandardBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConvergeCrossCodeCommandTest extends TestCase
{
    use DatabaseTransactions;

    private int $currencyId;

    private int $stdId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TCY', 'name' => 'Test Converge Currency', 'rate' => 1, 'admin' => 0,
        ]);
        $this->stdId = StandardBank::query()->create([
            'code' => 'XCMD', 'currency_id' => $this->currencyId, 'currency_code' => 'TCY',
            'type' => 1, 'name' => 'Converge Cmd Std', 'aliases' => [], 'status' => 1,
        ])->id;
    }

    private function makeBank(string $code, array $overrides = []): Bank
    {
        $bank = new Bank;
        $bank->forceFill(array_merge([
            'agent_group_id' => 903, 'standard_bank_id' => $this->stdId,
            'code' => $code, 'name' => '(TCY) - ' . $code, 'type' => 1,
            'currency_id' => $this->currencyId, 'status' => 1,
            'min_amount' => 0, 'max_amount' => 0, 'rate' => 1, 'tax_type' => 0, 'tax' => 0,
        ], $overrides));
        $bank->save();

        return $bank;
    }

    public function test_dry_run_reports_tiers_and_writes_nothing(): void
    {
        $live = $this->makeBank('XD01');
        $zombie = $this->makeBank('XD02');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => 9902, 'currency_id' => $this->currencyId,
            'bank_id' => $live->id, 'bank_code' => 'XD01', 'provider_bank_code' => '', 'status' => 1,
        ]);

        $this->artisan('bank:converge-cross-code', ['--agent-group' => '903'])
            ->expectsOutputToContain('auto')
            ->expectsOutputToContain('[dry-run] 未寫入')
            // I-3:dry-run 結尾的探測統計列
            ->expectsOutputToContain('probe_ok=1、probe_blocked=0、flagged=0')
            // I-2:min/max 相等時 amount_diff 欄留 '-',不應出現 min:/max: 標示
            ->doesntExpectOutputToContain('min:')
            ->assertExitCode(0);

        $this->assertSame(1, (int) Bank::query()->whereKey($zombie->id)->value('status'));
        $this->assertNull(Bank::query()->whereKey($zombie->id)->value('deleted_at'));
    }

    /**
     * I-2:min_amount 不等時,summary 表 amount_diff 欄需揭露差異。
     *
     * 只斷言單一子字串:table 同一 row 若塞兩個子字串斷言,PendingCommand 的
     * doWrite mock 逐次呼叫比對、同一行只會滿足先命中的那個(見 reference_pending_command_
     * substring_overlap),故 min/max 分兩個測試各自單獨斷言,避免互搶。
     */
    public function test_dry_run_summary_reveals_min_amount_diff(): void
    {
        $this->makeBank('XD10', ['min_amount' => 0, 'max_amount' => 100]);
        $this->makeBank('XD11', ['min_amount' => 50, 'max_amount' => 100]);

        $this->artisan('bank:converge-cross-code', ['--agent-group' => '903'])
            ->expectsOutputToContain('min:')
            ->assertExitCode(0);
    }

    /** I-2:max_amount 不等(min 相等)時同樣要揭露,且不誤標 min:。 */
    public function test_dry_run_summary_reveals_max_amount_diff_only(): void
    {
        $this->makeBank('XD12', ['min_amount' => 0, 'max_amount' => 100]);
        $this->makeBank('XD13', ['min_amount' => 0, 'max_amount' => 5000]);

        $this->artisan('bank:converge-cross-code', ['--agent-group' => '903'])
            ->expectsOutputToContain('max:')
            ->doesntExpectOutputToContain('min:')
            ->assertExitCode(0);
    }

    public function test_dry_run_lists_flagged_group_with_reasons(): void
    {
        $this->makeBank('XD03', ['type' => 1]);
        $this->makeBank('XD04', ['type' => 4]);

        $this->artisan('bank:converge-cross-code', ['--agent-group' => '903'])
            ->expectsOutputToContain('cross_type')
            ->assertExitCode(0);
    }

    public function test_no_groups_exits_cleanly(): void
    {
        $this->artisan('bank:converge-cross-code', ['--agent-group' => '999'])
            ->expectsOutputToContain('0 組')
            ->assertExitCode(0);
    }

    public function test_invalid_agent_group_fails_closed(): void
    {
        $this->artisan('bank:converge-cross-code', ['--agent-group' => 'abc'])->assertExitCode(1);
        $this->artisan('bank:converge-cross-code', ['--agent-group' => '0'])->assertExitCode(1);
        $this->artisan('bank:converge-cross-code', ['--agent-group' => '1,,2'])->assertExitCode(1);
    }

    public function test_execute_converges_auto_group_via_rename_bank(): void
    {
        $live = $this->makeBank('XE01');
        $zombie = $this->makeBank('XE02');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => 9903, 'currency_id' => $this->currencyId,
            'bank_id' => $live->id, 'bank_code' => 'XE01', 'provider_bank_code' => '', 'status' => 1,
        ]);

        $this->artisan('bank:converge-cross-code', [
            '--agent-group' => '903', '--execute' => true, '--force' => true,
        ])->assertExitCode(0);

        // rename-bank 收斂語意:loser 停用＋標刪(status=0 且 deleted_at 非 null)
        $loser = Bank::query()->whereKey($zombie->id)->first(['status', 'deleted_at']);
        $this->assertSame(0, (int) $loser->status);
        $this->assertNotNull($loser->deleted_at);
        // survivor 不動
        $this->assertSame(1, (int) Bank::query()->whereKey($live->id)->value('status'));
    }

    public function test_execute_skips_flagged_groups_entirely(): void
    {
        $a = $this->makeBank('XE03', ['type' => 1]);
        $b = $this->makeBank('XE04', ['type' => 4]);

        $this->artisan('bank:converge-cross-code', [
            '--agent-group' => '903', '--execute' => true, '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, (int) Bank::query()->whereKey($b->id)->value('status'));
        $this->assertNull(Bank::query()->whereKey($b->id)->value('deleted_at'));
    }

    public function test_execute_respects_limit(): void
    {
        // 兩個 auto 組(不同 standard),--limit=1 只收斂一組
        $live1 = $this->makeBank('XE05');
        $z1 = $this->makeBank('XE06');
        $std2 = StandardBank::query()->create([
            'code' => 'XCMD2', 'currency_id' => $this->currencyId, 'currency_code' => 'TCY',
            'type' => 1, 'name' => 'Converge Cmd Std 2', 'aliases' => [], 'status' => 1,
        ])->id;
        $live2 = $this->makeBank('XE07', ['standard_bank_id' => $std2]);
        $z2 = $this->makeBank('XE08', ['standard_bank_id' => $std2]);
        DB::table('cash_flow_banks')->insert([
            ['cash_flow_id' => 9904, 'currency_id' => $this->currencyId, 'bank_id' => $live1->id, 'bank_code' => 'XE05', 'provider_bank_code' => '', 'status' => 1],
            ['cash_flow_id' => 9905, 'currency_id' => $this->currencyId, 'bank_id' => $live2->id, 'bank_code' => 'XE07', 'provider_bank_code' => '', 'status' => 1],
        ]);

        $this->artisan('bank:converge-cross-code', [
            '--agent-group' => '903', '--execute' => true, '--force' => true, '--limit' => '1',
        ])->assertExitCode(0);

        $deleted = Bank::query()->whereIn('id', [$z1->id, $z2->id])->whereNotNull('deleted_at')->count();
        $this->assertSame(1, $deleted);
    }

    /**
     * C-2①:3 列 auto 組(1 現役 survivor＋2 零引用 loser)execute happy path。
     * 驗證 C-1 的單次彙總呼叫(--from 逗號分隔兩個 loser)能一次收斂兩個變體。
     */
    public function test_execute_converges_three_row_auto_group_in_single_aggregated_call(): void
    {
        $live = $this->makeBank('XE10');
        $loserA = $this->makeBank('XE11');
        $loserB = $this->makeBank('XE12');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => 9906, 'currency_id' => $this->currencyId,
            'bank_id' => $live->id, 'bank_code' => 'XE10', 'provider_bank_code' => '', 'status' => 1,
        ]);

        $this->artisan('bank:converge-cross-code', [
            '--agent-group' => '903', '--execute' => true, '--force' => true,
        ])
            // I-6:成功收斂需印稽核軌跡
            ->expectsOutputToContain(sprintf('converged:survivor %d ← losers %d,%d', $live->id, $loserA->id, $loserB->id))
            ->assertExitCode(0);

        foreach ([$loserA, $loserB] as $loser) {
            $row = Bank::query()->whereKey($loser->id)->first(['status', 'deleted_at']);
            $this->assertSame(0, (int) $row->status);
            $this->assertNotNull($row->deleted_at);
        }
        // survivor 不動
        $this->assertSame(1, (int) Bank::query()->whereKey($live->id)->value('status'));
    }

    /**
     * C-2②:3 列組探測阻斷情境。
     *
     * 建構真實(非 mock)FAILURE 條件:額外插入一列與 loserA 同碼、但 type 不同、
     * 不掛 standard_bank_id(不進本組偵測)的銀行——命中 RenameBankCommand::validateCodeSpace
     * 的「碼在本 group 同幣別下另有不同 type」阻斷,純結構檢查與現役引用證據無關,
     * 故不會讓該組在 classify() 就先被判為 flagged(仍是 auto 組,實際卡在 rename-bank
     * 自身 preflight)。驗證阻斷後兩個 loser 全零寫入。
     */
    public function test_execute_three_row_group_blocked_by_ambiguous_type_writes_nothing(): void
    {
        $live = $this->makeBank('XE20');
        $loserA = $this->makeBank('XE21');
        $loserB = $this->makeBank('XE22');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => 9907, 'currency_id' => $this->currencyId,
            'bank_id' => $live->id, 'bank_code' => 'XE20', 'provider_bank_code' => '', 'status' => 1,
        ]);
        // 碼歧義列:同碼(XE21)、不同 type、不掛 standard_bank_id → 不進本組偵測,
        // 但會被 rename-bank preflight 的 validateCodeSpace 命中而阻斷
        $this->makeBank('XE21', ['type' => 4, 'standard_bank_id' => null]);

        $this->artisan('bank:converge-cross-code', [
            '--agent-group' => '903', '--execute' => true, '--force' => true,
        ])->assertExitCode(1);

        foreach ([$loserA, $loserB] as $loser) {
            $row = Bank::query()->whereKey($loser->id)->first(['status', 'deleted_at']);
            $this->assertSame(1, (int) $row->status);
            $this->assertNull($row->deleted_at);
        }
        // survivor 也不動
        $this->assertSame(1, (int) Bank::query()->whereKey($live->id)->value('status'));
    }

    /** I-4:dry-run 探測有 blocked 時同樣回報 FAILURE(而非既有全綠案例的 SUCCESS)。 */
    public function test_dry_run_returns_failure_when_probe_blocked(): void
    {
        $live = $this->makeBank('XE23');
        $this->makeBank('XE24');
        $this->makeBank('XE25');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => 9908, 'currency_id' => $this->currencyId,
            'bank_id' => $live->id, 'bank_code' => 'XE23', 'provider_bank_code' => '', 'status' => 1,
        ]);
        $this->makeBank('XE24', ['type' => 4, 'standard_bank_id' => null]);

        $this->artisan('bank:converge-cross-code', ['--agent-group' => '903'])
            ->expectsOutputToContain('probe_ok=0、probe_blocked=1、flagged=0')
            ->assertExitCode(1);
    }

    /** I-5:--agent-group 有給但為空字串,與「未給」(整庫掃描)語意不同,須 fail-closed。 */
    public function test_agent_group_given_empty_string_fails_closed(): void
    {
        $this->artisan('bank:converge-cross-code', ['--agent-group' => ''])->assertExitCode(1);
    }

    /** I-5:--execute 時必須明確指定 --agent-group,禁止整庫一次全量執行(逐站 rollout)。 */
    public function test_execute_without_agent_group_is_rejected(): void
    {
        $this->artisan('bank:converge-cross-code', ['--execute' => true, '--force' => true])
            ->expectsOutputToContain('逐站 rollout')
            ->assertExitCode(1);
    }
}
