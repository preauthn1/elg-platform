<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

/**
 * `bank:add-cfb-rows`＝依代付 ACF 的 `available_banks` 補上缺少的 `cash_flow_banks` 列。
 *
 * 場景取自 VWorld MaxPay（`cash_flow_id=114`）的 cfb 零列：舊的 JSON 路徑通得過，
 * 代付查表灰度一開閘就整批查空而擋單。
 *
 * 分成兩組群組層來對應真實的 g70（正式）／g93（測試）孿生組——本命令最容易出錯的地方，
 * 就是兩張 ACF 對同一個銀行碼各有自己的銀行列，而 cfb 的唯一鍵不含 `bank_code`。
 */
class AddCfbRowsCommandTest extends TestCase
{
    use DatabaseTransactions;
    use DetachesBanksActiveGuard;

    private const CASH_FLOW_ID = 99860;

    private const DEPOSIT_CASH_FLOW_ID = 99861;

    /** base 層＝全站共用的銀行列 */
    private const BASE_GROUP = 0;

    /** 對應 g70 正式組 */
    private const PRIMARY_GROUP = 99862;

    /** 對應 g93 測試組 */
    private const SECONDARY_GROUP = 99863;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS7', 'name' => 'Test Currency 7', 'rate' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Payout Cfb Add', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::DEPOSIT_CASH_FLOW_ID, 'name' => 'Test Deposit Cfb Add', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 1, 'status' => 1, 'admin' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedBank(string $code, int $agentGroupId, array $overrides = []): Bank
    {
        return Bank::create(array_merge([
            'agent_group_id' => $agentGroupId,
            'type' => 1,
            'currency_id' => $this->currencyId,
            'name' => '(TS7) - ' . $code,
            'code' => $code,
            'status' => 1,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ], $overrides));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, mixed>  $overrides
     */
    private function seedAcf(array $entries, int $agentGroupId = self::PRIMARY_GROUP, array $overrides = []): AgentCashFlow
    {
        return AgentCashFlow::create(array_merge([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'agent_group_id' => $agentGroupId,
            'currency_id' => $this->currencyId,
            'info' => ['available_banks' => $entries],
            'status' => 1,
            'admin' => 0,
        ], $overrides));
    }

    private function seedCfb(int $bankId, string $bankCode, string $third, int $status = 1): int
    {
        return (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'currency_id' => $this->currencyId,
            'bank_id' => $bankId,
            'bank_code' => $bankCode,
            'provider_bank_code' => $third,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{BankCode_ELG: string, BankCode_Third: mixed} */
    private function entry(string $target, mixed $third): array
    {
        return ['BankCode_ELG' => $target, 'BankCode_Third' => $third];
    }

    /** @return Collection<int, object> */
    private function cfbRows(): Collection
    {
        return DB::table('cash_flow_banks')
            ->where('cash_flow_id', self::CASH_FLOW_ID)
            ->where('currency_id', $this->currencyId)
            ->orderBy('id')
            ->get();
    }

    private function auditFor(string $code): ?object
    {
        return DB::table('bank_dedup_logs')
            ->where('action', 'manual_cfb_add')
            ->where('code', $code)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function runCommand(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('bank:add-cfb-rows', array_merge([
            '--reason' => '依 PayLoh v1.9 §8 官方清單補列',
        ], $options));
    }

    // ── 預設不寫入 ────────────────────────────────────────────────

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS')]);

        $this->runCommand(['--acf' => [$acf->id]])
            ->expectsOutputToContain('[新增]')
            ->assertExitCode(0);

        $this->assertCount(0, $this->cfbRows(), '預設不得寫入');
        $this->assertNull($this->auditFor('TS7DBS'), '預演不得留審計');
    }

    // ── 正常路徑與審計 ────────────────────────────────────────────

    public function test_creates_one_active_row_per_entry(): void
    {
        $dbs = $this->seedBank('TS7DBS', self::BASE_GROUP);
        $ocbc = $this->seedBank('TS7OCBC', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS'), $this->entry('TS7OCBC', 'OCBC')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->assertExitCode(0);

        $rows = $this->cfbRows();

        $this->assertCount(2, $rows);
        $this->assertSame(
            [(int) $dbs->id, (int) $ocbc->id],
            $rows->map(static fn (object $row): int => (int) $row->bank_id)->all()
        );
        $this->assertSame(['TS7DBS', 'TS7OCBC'], $rows->map(static fn (object $row): string => (string) $row->bank_code)->all());
        $this->assertSame(['DBS', 'OCBC'], $rows->map(static fn (object $row): string => (string) $row->provider_bank_code)->all());
        $this->assertSame([1, 1], $rows->map(static fn (object $row): int => (int) $row->status)->all(), 'runtime 只讀 status=1');
    }

    public function test_writes_audit_that_distinguishes_source_group_from_bank_group(): void
    {
        $bank = $this->seedBank('TS7MBBG', self::PRIMARY_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7MBBG', 'MBB')], self::PRIMARY_GROUP);

        $this->runCommand([
            '--acf' => [$acf->id],
            '--reason' => '依 PayLoh v1.9 §8 官方清單補列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $log = $this->auditFor('TS7MBBG');

        $this->assertNotNull($log);
        $this->assertSame(self::PRIMARY_GROUP, (int) $log->agent_group_id, 'agent_group_id 記的是 bank 所屬群組');
        $this->assertSame((int) $bank->id, (int) $log->survivor_bank_id);
        $this->assertSame((int) $bank->id, (int) $log->loser_bank_id, '單列新增自我參照');

        $detail = json_decode((string) $log->detail, true);

        $this->assertSame((int) $this->cfbRows()->first()->id, $detail['id'], '沒有 cfb id 就無從還原');
        $this->assertSame('MBB', $detail['third']);
        $this->assertSame('group', $detail['layer']);
        $this->assertSame('依 PayLoh v1.9 §8 官方清單補列', $detail['reason']);
        $this->assertSame(
            [['acf' => (int) $acf->id, 'g' => self::PRIMARY_GROUP, 'idx' => 0]],
            $detail['src'],
            '來源群組與項目位置都要落審計'
        );
    }

    // ── D2／D15 定位 ──────────────────────────────────────────────

    public function test_prefers_base_layer_over_group_layer(): void
    {
        $base = $this->seedBank('TS7DBS', self::BASE_GROUP);
        $this->seedBank('TS7DBS', self::PRIMARY_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame((int) $base->id, (int) $this->cfbRows()->first()->bank_id);
    }

    public function test_falls_back_to_group_layer_when_base_row_is_inactive(): void
    {
        $this->seedBank('TS7MBBG', self::BASE_GROUP, ['status' => 0]);
        $group = $this->seedBank('TS7MBBG', self::PRIMARY_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7MBBG', 'MBB')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame((int) $group->id, (int) $this->cfbRows()->first()->bank_id);
    }

    public function test_locates_bank_by_byte_exact_code(): void
    {
        // banks.code 的 collation 是 utf8mb4_unicode_ci：不加 BINARY 這一筆會誤配
        $this->seedBank('TS7MayBank', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7MAYBANK', 'MBB')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('定位不到服務中的銀行列')
            ->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows(), '只差大小寫就是不同的碼，不得誤配');
    }

    public function test_ignores_soft_deleted_bank_rows(): void
    {
        // Bank 沒有掛 SoftDeletes trait，deleted_at 必須自己濾
        $this->seedBank('TS7UOB', self::BASE_GROUP, ['deleted_at' => now()]);
        $acf = $this->seedAcf([$this->entry('TS7UOB', 'UOB')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('定位不到服務中的銀行列')
            ->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows());
    }

    public function test_blocks_when_priority_layer_has_two_active_candidates(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('多個服務中的候選銀行列')
            ->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows(), '身分不明不能用 first() 解決');
    }

    public function test_blocks_when_base_and_group_disagree_on_bank_identity(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP, ['standard_bank_id' => 990001]);
        $this->seedBank('TS7DBS', self::PRIMARY_GROUP, ['standard_bank_id' => 990002]);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('銀行身分不一致')
            ->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows());
    }

    // ── D3／D14 定位不到的處置 ────────────────────────────────────

    public function test_one_unresolvable_entry_aborts_the_whole_batch(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS'), $this->entry('TS7UOB', 'UOB')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows(), '同批中合格的那筆也不得寫入');
    }

    public function test_skip_unresolvable_lets_the_remaining_rows_through(): void
    {
        $dbs = $this->seedBank('TS7DBS', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS'), $this->entry('TS7UOB', 'UOB')]);

        $this->runCommand([
            '--acf' => [$acf->id],
            '--skip-unresolvable' => true,
            '--execute' => true,
            '--force' => true,
        ])->expectsOutputToContain('[跳過]')->assertExitCode(0);

        $rows = $this->cfbRows();

        $this->assertCount(1, $rows);
        $this->assertSame((int) $dbs->id, (int) $rows->first()->bank_id);
    }

    // ── D5 批內收斂 ───────────────────────────────────────────────

    public function test_blocks_when_two_source_acfs_point_the_same_code_at_different_banks(): void
    {
        $this->seedBank('TS7WTT', self::PRIMARY_GROUP);
        $this->seedBank('TS7WTT', self::SECONDARY_GROUP);
        $primary = $this->seedAcf([$this->entry('TS7WTT', 'WTT')], self::PRIMARY_GROUP);
        $secondary = $this->seedAcf([$this->entry('TS7WTT', 'WTT')], self::SECONDARY_GROUP);

        $this->runCommand([
            '--acf' => [$primary->id, $secondary->id],
            '--execute' => true,
            '--force' => true,
        ])->expectsOutputToContain('指向不同的銀行列')->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows(), '兩個候選都還沒建，任選等於憑執行順序決定銀行身分');
    }

    public function test_merges_identical_candidates_from_two_source_acfs(): void
    {
        $base = $this->seedBank('TS7DBS', self::BASE_GROUP);
        $primary = $this->seedAcf([$this->entry('TS7DBS', 'DBS')], self::PRIMARY_GROUP);
        $secondary = $this->seedAcf([$this->entry('TS7DBS', 'DBS')], self::SECONDARY_GROUP);

        $this->runCommand([
            '--acf' => [$primary->id, $secondary->id],
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $rows = $this->cfbRows();

        $this->assertCount(1, $rows);
        $this->assertSame((int) $base->id, (int) $rows->first()->bank_id);

        $detail = json_decode((string) $this->auditFor('TS7DBS')->detail, true);

        $this->assertSame(
            [(int) $primary->id, (int) $secondary->id],
            array_column($detail['src'], 'acf'),
            '併成一列時兩張來源都要留在審計裡'
        );
    }

    // ── D12 別名 ──────────────────────────────────────────────────

    public function test_blocks_alias_by_default_and_writes_with_the_flag(): void
    {
        $this->seedBank('TS7MBBG', self::BASE_GROUP);
        $this->seedBank('TS7MBBE', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7MBBG', 'MBB'), $this->entry('TS7MBBE', 'MBB')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('--allow-alias')
            ->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows());

        $this->runCommand([
            '--acf' => [$acf->id],
            '--allow-alias' => true,
            '--execute' => true,
            '--force' => true,
        ])->expectsOutputToContain('[別名放行]')->assertExitCode(0);

        $this->assertCount(2, $this->cfbRows());
    }

    public function test_alias_detection_also_covers_existing_rows(): void
    {
        $existing = $this->seedBank('TS7MBBG', self::BASE_GROUP);
        $this->seedBank('TS7MBBE', self::BASE_GROUP);
        $this->seedCfb((int) $existing->id, 'TS7MBBG', 'MBB');
        $acf = $this->seedAcf([$this->entry('TS7MBBE', 'MBB')]);

        // 實務上的別名就是這樣出現的：第一趟建了 MBBGBSG→MBB，第二趟才輪到 MBBESG→MBB
        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('個銀行碼共用')
            ->assertExitCode(1);

        $this->assertCount(1, $this->cfbRows());
    }

    // ── D11 對既有列 ──────────────────────────────────────────────

    public function test_skips_row_that_already_satisfies(): void
    {
        $base = $this->seedBank('TS7DBS', self::BASE_GROUP);
        $this->seedCfb((int) $base->id, 'TS7DBS', 'DBS');
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('[已滿足]')
            ->assertExitCode(0);

        $this->assertCount(1, $this->cfbRows(), '不得重複建列');
    }

    public function test_blocks_when_the_existing_row_is_inactive(): void
    {
        $base = $this->seedBank('TS7DBS', self::BASE_GROUP);
        $this->seedCfb((int) $base->id, 'TS7DBS', 'DBS', status: 0);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('已存在但已停用')
            ->assertExitCode(1);
    }

    public function test_blocks_when_the_existing_row_has_a_different_provider_code(): void
    {
        $base = $this->seedBank('TS7DBS', self::BASE_GROUP);
        $this->seedCfb((int) $base->id, 'TS7DBS', 'OLD');
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('三方碼不同')
            ->assertExitCode(1);
    }

    public function test_skips_when_the_runtime_key_is_already_served_by_another_bank(): void
    {
        $primaryBank = $this->seedBank('TS7WTT', self::PRIMARY_GROUP);
        $this->seedBank('TS7WTT', self::SECONDARY_GROUP);
        $this->seedCfb((int) $primaryBank->id, 'TS7WTT', 'WTT');
        $acf = $this->seedAcf([$this->entry('TS7WTT', 'WTT')], self::SECONDARY_GROUP);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('銀行身分待核')
            ->assertExitCode(0);

        $this->assertCount(1, $this->cfbRows(), '再建一列 runtime 也取不到，故不建');
    }

    // ── D6 跨 ACF 一致性 ──────────────────────────────────────────

    public function test_blocks_when_another_active_acf_maps_the_code_to_a_different_third_code(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $source = $this->seedAcf([$this->entry('TS7DBS', 'DBS')], self::PRIMARY_GROUP);
        $this->seedAcf([$this->entry('TS7DBS', 'DBS_X')], self::SECONDARY_GROUP);

        $this->runCommand(['--acf' => [$source->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('三方碼與 acf')
            ->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows(), 'cfb 不分代理群組，一列會同時服務兩張 ACF');
    }

    public function test_ignores_disabled_acfs_in_the_cross_check(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $source = $this->seedAcf([$this->entry('TS7DBS', 'DBS')], self::PRIMARY_GROUP);
        $this->seedAcf([$this->entry('TS7DBS', 'DBS_X')], self::SECONDARY_GROUP, ['status' => 0]);

        $this->runCommand(['--acf' => [$source->id], '--execute' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertCount(1, $this->cfbRows(), '停用的通道不會有訂單走到，舊映射不構成現行衝突');
    }

    // ── D13 結構驗證 ──────────────────────────────────────────────

    public function test_blocks_malformed_entries_without_cherry_picking_the_good_ones(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $this->seedBank('TS7OCBC', self::BASE_GROUP);
        $acf = $this->seedAcf([
            $this->entry('TS7DBS', 'DBS'),
            $this->entry('TS7OCBC', ['x']),
        ]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('結構不合法')
            ->assertExitCode(1);

        $this->assertCount(0, $this->cfbRows(), '權威來源可疑時整張擋下，不挑好的用');
    }

    public function test_blocks_when_one_acf_maps_a_code_to_two_different_third_codes(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS'), $this->entry('TS7DBS', 'OTHER')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('對到兩個不同的三方碼')
            ->assertExitCode(1);
    }

    public function test_blocks_code_that_does_not_fit_the_audit_column(): void
    {
        $acf = $this->seedAcf([$this->entry('TS7VERYLONGBANKCODE', 'X')]);

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('bank_dedup_logs.code 放不下')
            ->assertExitCode(1);
    }

    public function test_blocks_when_the_cash_flow_is_not_payout(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $acf = $this->seedAcf(
            [$this->entry('TS7DBS', 'DBS')],
            self::PRIMARY_GROUP,
            ['cash_flow_id' => self::DEPOSIT_CASH_FLOW_ID]
        );

        $this->runCommand(['--acf' => [$acf->id], '--execute' => true, '--force' => true])
            ->expectsOutputToContain('不是代付渠道')
            ->assertExitCode(1);
    }

    // ── 參數層 ────────────────────────────────────────────────────

    public function test_elg_option_limits_the_batch_to_one_code(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $this->seedBank('TS7OCBC', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS'), $this->entry('TS7OCBC', 'OCBC')]);

        $this->runCommand([
            '--acf' => [$acf->id],
            '--target' => 'TS7OCBC',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $rows = $this->cfbRows();

        $this->assertCount(1, $rows);
        $this->assertSame('TS7OCBC', (string) $rows->first()->bank_code);
    }

    public function test_blocks_when_the_elg_option_is_not_present_in_any_source(): void
    {
        $this->seedBank('TS7DBS', self::BASE_GROUP);
        $acf = $this->seedAcf([$this->entry('TS7DBS', 'DBS')]);

        $this->runCommand(['--acf' => [$acf->id], '--target' => 'TS7NOPE'])
            ->expectsOutputToContain('不在任何來源 ACF')
            ->assertExitCode(1);
    }

    public function test_requires_acf_and_reason(): void
    {
        $acf = $this->seedAcf([]);

        $this->artisan('bank:add-cfb-rows', ['--acf' => [$acf->id]])
            ->expectsOutputToContain('--reason 必填')
            ->assertExitCode(1);

        $this->artisan('bank:add-cfb-rows', ['--reason' => '沒給來源'])
            ->expectsOutputToContain('--acf 必填')
            ->assertExitCode(1);
    }

    public function test_rejects_non_numeric_acf_id(): void
    {
        $this->artisan('bank:add-cfb-rows', ['--acf' => ['319,315'], '--reason' => '逗號分隔不受支援'])
            ->expectsOutputToContain('純數字')
            ->assertExitCode(1);
    }
}
