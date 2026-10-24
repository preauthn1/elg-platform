<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use App\Models\Bank;
use App\Models\StandardBank;
use App\Services\CashFlow\Bank\CrossCodeConvergenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DetachesBanksActiveGuard;
use Tests\TestCase;

class CrossCodeConvergenceServiceTest extends TestCase
{
    use DatabaseTransactions;
    use DetachesBanksActiveGuard;

    private int $currencyId;

    private int $stdId;

    private CrossCodeConvergenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // currencies 的 rate / admin 是 NOT NULL 無預設;比照 SyncProjectionCommandTest 直插
        $this->currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TCX',
            'name' => 'Test CrossCode Currency',
            'rate' => 1,
            'admin' => 0,
        ]);

        $this->stdId = StandardBank::query()->create([
            'code' => 'XCODE',
            'currency_id' => $this->currencyId,
            'currency_code' => 'TCX',
            'type' => 1,
            'name' => 'CrossCode Std Bank',
            'aliases' => [],
            'status' => 1,
        ])->id;

        $this->service = app(CrossCodeConvergenceService::class);
    }

    /** 建 bank 列的最小 helper;預設同 group 同 standard 同幣別 type=1 status=1。 */
    private function makeBank(string $code, array $overrides = []): Bank
    {
        $bank = new Bank;
        $bank->forceFill(array_merge([
            'agent_group_id' => 901,
            'standard_bank_id' => $this->stdId,
            'code' => $code,
            'name' => '(TCX) - ' . $code,
            'type' => 1,
            'currency_id' => $this->currencyId,
            'status' => 1,
            'min_amount' => 0,
            'max_amount' => 0,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
        ], $overrides));
        $bank->save();

        return $bank;
    }

    public function test_detects_cross_code_group_and_skips_same_code_and_soft_deleted(): void
    {
        $a = $this->makeBank('XC01');
        $b = $this->makeBank('XC02');
        // 同碼列:不構成跨碼(distinct code = 1 時不收)
        $this->makeBank('SAME', ['agent_group_id' => 902]);
        $this->makeBank('SAME', ['agent_group_id' => 902]);
        // 軟刪列:不算存活
        $this->makeBank('XC03', ['deleted_at' => now()]);

        $groups = $this->service->detectCrossCodeGroups([901, 902]);

        $this->assertCount(1, $groups);
        $this->assertSame(901, $groups[0]['agent_group_id']);
        $this->assertSame($this->stdId, $groups[0]['standard_bank_id']);
        $this->assertSame([$a->id, $b->id], $groups[0]['rows']->pluck('id')->all());
    }

    public function test_scope_filter_excludes_other_groups(): void
    {
        $this->makeBank('XC01');
        $this->makeBank('XC02');

        $this->assertCount(0, $this->service->detectCrossCodeGroups([999]));
    }

    /** 直插 cfb 列(必填欄依 migration 補齊)。 */
    private function makeCfb(array $attrs): int
    {
        return (int) DB::table('cash_flow_banks')->insertGetId(array_merge([
            'cash_flow_id' => 9901,
            'currency_id' => $this->currencyId,
            'bank_id' => 0,
            'bank_code' => '',
            'provider_bank_code' => '',
            'status' => 1,
        ], $attrs));
    }

    public function test_evidence_cfb_code_ref_counts_as_live_even_when_bank_id_points_elsewhere(): void
    {
        $bank = $this->makeBank('XC10');
        // drift 形態:bank_id 指別列、bank_code 才是本列碼 → 仍算現役(設計 C1)
        $this->makeCfb(['bank_id' => 999999, 'bank_code' => 'XC10']);

        $evidence = $this->service->gatherEvidence($bank->refresh());

        $this->assertSame(1, $evidence['cfb_code_refs']);
        $this->assertTrue($evidence['live']);
    }

    public function test_evidence_zero_reference_bank_is_not_live(): void
    {
        $bank = $this->makeBank('XC11');

        $evidence = $this->service->gatherEvidence($bank->refresh());

        $this->assertFalse($evidence['live']);
        $this->assertSame(0, $evidence['active_bindings']);
        $this->assertFalse($evidence['base_active']);
    }

    public function test_evidence_base_active_detects_regrowth_source(): void
    {
        $bank = $this->makeBank('XC12');
        // base(group 0)同碼 active 列 → regrowth 風險源
        $this->makeBank('XC12', ['agent_group_id' => 0, 'standard_bank_id' => null]);

        $this->assertTrue($this->service->gatherEvidence($bank->refresh())['base_active']);
    }

    /** I-1:對齊 RenameBankCommand::warnAboutRegrowth(:589-597)——不濾 deleted_at。 */
    public function test_evidence_base_active_ignores_deleted_at_like_warn_about_regrowth(): void
    {
        $bank = $this->makeBank('XC12D');
        // base(group 0)同碼 status=1 但已標刪:AgentBankSyncService 仍會拿它當模板,
        // 濾掉 deleted_at 會漏掉真實 regrowth 風險。
        $this->makeBank('XC12D', ['agent_group_id' => 0, 'standard_bank_id' => null, 'deleted_at' => now()]);

        $this->assertTrue($this->service->gatherEvidence($bank->refresh())['base_active']);
    }

    /** I-1:base 列幣別不同不算 regrowth 風險源(對齊 warnAboutRegrowth 的 currency_id 過濾)。 */
    public function test_evidence_base_active_excludes_other_currency(): void
    {
        $bank = $this->makeBank('XC12C');
        $otherCurrencyId = DB::table('currencies')->insertGetId([
            'code' => 'TCX2', 'name' => 'Test CrossCode Currency 2', 'rate' => 1, 'admin' => 0,
        ]);
        // base 同碼但幣別不同 → 不應計入 base_active
        $this->makeBank('XC12C', [
            'agent_group_id' => 0, 'standard_bank_id' => null, 'currency_id' => $otherCurrencyId,
        ]);

        $this->assertFalse($this->service->gatherEvidence($bank->refresh())['base_active']);
    }

    public function test_evidence_json_hit_limited_to_payout_acf_same_currency(): void
    {
        $bank = $this->makeBank('XC13');
        $cashFlowId = (int) DB::table('cash_flows')->insertGetId([
            // 必填欄依 create_cash_flows migration 補齊;type=3 代付
            'name' => 'XC Payout CF',
            'type' => 3,
            'status' => 1,
            'admin' => 0,
        ]);
        $acfId = (int) DB::table('agent_cash_flows')->insertGetId([
            // 必填欄依 create_agent_cash_flows migration 補齊
            'cash_flow_info_id' => 0,
            'cash_flow_id' => $cashFlowId,
            'agent_group_id' => 901,
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax' => 0,
            'tax_percentage' => 0,
            'name' => 'XC ACF',
            'info' => json_encode(['available_banks' => [['BankCode_ELG' => 'XC13', 'BankCode_Third' => 'T13']]]),
            'sorts' => 0,
            'cost' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $this->assertSame([$acfId], $this->service->gatherEvidence($bank->refresh())['json_acf_ids']);
    }

    /** 直插 withdrawal_orders 列(必填欄依 migration 補齊)。 */
    private function makeWithdrawalOrder(array $attrs): int
    {
        return (int) DB::table('withdrawal_orders')->insertGetId(array_merge([
            'bank_name' => 'Test Bank',
            'bank_code' => '',
            'account_name' => 'Tester',
            'account_no' => '',
            'member_id' => 1,
            'amount' => 100,
            'third_party_order_no' => 'XC-' . uniqid(),
            'change_logs' => '',
            'status' => 0,
        ], $attrs));
    }

    /** 直插 member_bank_accounts 列(必填欄依 migration 補齊)。 */
    private function makeMemberBankAccount(array $attrs): int
    {
        return (int) DB::table('member_bank_accounts')->insertGetId(array_merge([
            'member_id' => 1,
            'account_name' => 'Tester',
            'account_no' => '1234567890',
            'operator_cs' => 0,
            'status' => 1,
        ], $attrs));
    }

    public function test_evidence_cfb_id_ref_counts_as_live_when_bank_code_differs(): void
    {
        $bank = $this->makeBank('XC14');
        // bank_id 命中本列、bank_code 卻是別碼 → 仍計入 cfb_id_refs(設計 C1 對稱路徑)
        $this->makeCfb(['bank_id' => $bank->id, 'bank_code' => 'OTHERCODE']);

        $evidence = $this->service->gatherEvidence($bank->refresh());

        $this->assertSame(1, $evidence['cfb_id_refs']);
        $this->assertTrue($evidence['live']);
    }

    public function test_evidence_active_binding_counts_as_live(): void
    {
        $bank = $this->makeBank('XC15');
        $this->makeMemberBankAccount(['bank_id' => $bank->id, 'status' => 1]);

        $evidence = $this->service->gatherEvidence($bank->refresh());

        $this->assertSame(1, $evidence['active_bindings']);
        $this->assertTrue($evidence['live']);
    }

    public function test_evidence_recent_order_counts_as_live_but_old_order_excluded(): void
    {
        $bank = $this->makeBank('XC16');
        $this->makeWithdrawalOrder(['bank_id' => $bank->id, 'created_at' => now()->subDays(10)]);
        // 91 天前:超出近 90 日窗口,不計入 recent_orders
        $this->makeWithdrawalOrder(['bank_id' => $bank->id, 'created_at' => now()->subDays(91)]);

        $evidence = $this->service->gatherEvidence($bank->refresh());

        $this->assertSame(1, $evidence['recent_orders']);
        $this->assertTrue($evidence['live']);
    }

    public function test_evidence_pending_order_status_23_counts_without_affecting_live(): void
    {
        $bank = $this->makeBank('XC17');
        // status=23(代付處理中,在途單)、created_at 拉遠避免同時觸發 recent_orders
        $this->makeWithdrawalOrder(['bank_id' => $bank->id, 'status' => 23, 'created_at' => now()->subDays(200)]);

        $evidence = $this->service->gatherEvidence($bank->refresh());

        $this->assertSame(1, $evidence['pending_orders']);
        $this->assertFalse($evidence['live']);
    }

    public function test_evidence_historical_orders_and_bindings_count_regardless_of_status(): void
    {
        $bank = $this->makeBank('XC18');
        // 終態單(status=9)與停用綁定(status=0):不影響 live,但仍計入歷史計數
        $this->makeWithdrawalOrder(['bank_id' => $bank->id, 'status' => 9, 'created_at' => now()->subDays(400)]);
        $this->makeMemberBankAccount(['bank_id' => $bank->id, 'status' => 0]);

        $evidence = $this->service->gatherEvidence($bank->refresh());

        $this->assertSame(1, $evidence['historical_orders']);
        $this->assertSame(1, $evidence['historical_bindings']);
        $this->assertSame(0, $evidence['active_bindings']);
        $this->assertFalse($evidence['live']);
    }

    public function test_classify_exactly_one_live_row_is_auto_with_live_survivor(): void
    {
        $live = $this->makeBank('XC20');
        $zombie = $this->makeBank('XC21');
        $this->makeCfb(['bank_code' => 'XC20']);

        $result = $this->service->classify(collect([$live->refresh(), $zombie->refresh()]));

        $this->assertSame('auto', $result['tier']);
        $this->assertSame($live->id, $result['survivor_id']);
        $this->assertSame([$zombie->id], $result['loser_ids']);
    }

    public function test_classify_all_zero_picks_more_history_then_smaller_id(): void
    {
        $a = $this->makeBank('XC22');
        $b = $this->makeBank('XC23');

        $result = $this->service->classify(collect([$a->refresh(), $b->refresh()]));

        $this->assertSame('auto', $result['tier']);
        $this->assertSame($a->id, $result['survivor_id']); // 全平手取 id 小

        // 給 b 一筆歷史綁定(status=0 不算現役、算歷史)後,b 勝出
        DB::table('member_bank_accounts')->insert([
            // 必填欄依 migration 補齊:account_name、operator_cs 為 NOT NULL 無預設
            'member_id' => 1, 'bank_id' => $b->id, 'account_name' => 'Tester', 'account_no' => 'X',
            'operator_cs' => 0, 'status' => 0,
        ]);
        $result2 = $this->service->classify(collect([$a->refresh(), $b->refresh()]));
        $this->assertSame($b->id, $result2['survivor_id']);
    }

    public function test_classify_two_live_rows_is_flagged_multi_live(): void
    {
        $a = $this->makeBank('XC24');
        $b = $this->makeBank('XC25');
        // cash_flow_banks 對 (cash_flow_id, currency_id, bank_id) 有唯一鍵,bank_id 需相異避免撞碼
        $this->makeCfb(['bank_id' => $a->id, 'bank_code' => 'XC24']);
        $this->makeCfb(['bank_id' => $b->id, 'bank_code' => 'XC25']);

        $result = $this->service->classify(collect([$a->refresh(), $b->refresh()]));

        $this->assertSame('flagged', $result['tier']);
        $this->assertContains('multi_live', $result['reasons']);
        $this->assertNull($result['survivor_id']);
        $this->assertSame([], $result['loser_ids']);
    }

    public function test_classify_cross_type_is_flagged(): void
    {
        $a = $this->makeBank('XC26');
        $b = $this->makeBank('XC27', ['type' => 4]);

        $result = $this->service->classify(collect([$a->refresh(), $b->refresh()]));

        $this->assertSame('flagged', $result['tier']);
        $this->assertContains('cross_type', $result['reasons']);
    }

    public function test_classify_fee_mismatch_is_flagged(): void
    {
        $a = $this->makeBank('XC28', ['rate' => 1]);
        $b = $this->makeBank('XC29', ['rate' => 0.98]);

        $result = $this->service->classify(collect([$a->refresh(), $b->refresh()]));

        $this->assertSame('flagged', $result['tier']);
        $this->assertContains('fee_mismatch', $result['reasons']);
    }

    public function test_classify_crypto_and_semantic_codes_are_flagged(): void
    {
        $a = $this->makeBank('ERC20');
        $b = $this->makeBank('XC30');

        $result = $this->service->classify(collect([$a->refresh(), $b->refresh()]));

        $this->assertSame('flagged', $result['tier']);
        $this->assertContains('code_semantics', $result['reasons']);
    }

    public function test_classify_pending_orders_is_flagged(): void
    {
        $a = $this->makeBank('XC31');
        $b = $this->makeBank('XC32');
        DB::table('withdrawal_orders')->insert([
            // 必填欄依 migration 補齊;status=23 非終態
            'member_id' => 1, 'bank_id' => $b->id, 'bank_code' => 'XC32',
            'bank_name' => 'X', 'account_name' => 'Tester', 'account_no' => 'X', 'amount' => 100,
            'third_party_order_no' => 'XC-' . uniqid(), 'change_logs' => '',
            'status' => 23, 'created_at' => now(),
        ]);

        $result = $this->service->classify(collect([$a->refresh(), $b->refresh()]));

        $this->assertSame('flagged', $result['tier']);
        $this->assertContains('pending_orders', $result['reasons']);
    }

    public function test_classify_loser_base_active_is_flagged_regrowth(): void
    {
        $live = $this->makeBank('XC33');
        $zombie = $this->makeBank('XC34');
        $this->makeCfb(['bank_code' => 'XC33']);
        $this->makeBank('XC34', ['agent_group_id' => 0, 'standard_bank_id' => null]); // loser 碼 base 仍 active

        $result = $this->service->classify(collect([$live->refresh(), $zombie->refresh()]));

        $this->assertSame('flagged', $result['tier']);
        $this->assertContains('regrowth_risk', $result['reasons']);
    }

    /** I-7①:status=0 列不影響 T1 偵測收組(detectCrossCodeGroups 只濾 deleted_at,不濾 status)。 */
    public function test_detect_includes_inactive_status_rows(): void
    {
        $a = $this->makeBank('XC40', ['status' => 0]);
        $b = $this->makeBank('XC41');

        $groups = $this->service->detectCrossCodeGroups([901]);

        $this->assertCount(1, $groups);
        $this->assertSame([$a->id, $b->id], $groups[0]['rows']->pluck('id')->all());
    }

    /** I-7②:全零組(無現役引用)時,啟用(status=1)列優先當 survivor,凌駕「平手取 id 小」。 */
    public function test_classify_all_zero_prefers_active_status_row_over_smaller_id(): void
    {
        $inactive = $this->makeBank('XC42', ['status' => 0]); // id 較小,但非啟用
        $active = $this->makeBank('XC43', ['status' => 1]);

        $result = $this->service->classify(collect([$inactive->refresh(), $active->refresh()]));

        $this->assertSame('auto', $result['tier']);
        $this->assertSame($active->id, $result['survivor_id']);
        $this->assertSame([$inactive->id], $result['loser_ids']);
    }

    /** I-7③:組內完全沒有 status=1 列時,無合法 survivor 可選,flagged no_active_row。 */
    public function test_classify_no_active_row_is_flagged(): void
    {
        $a = $this->makeBank('XC44', ['status' => 0]);
        $b = $this->makeBank('XC45', ['status' => 0]);

        $result = $this->service->classify(collect([$a->refresh(), $b->refresh()]));

        $this->assertSame('flagged', $result['tier']);
        $this->assertContains('no_active_row', $result['reasons']);
        $this->assertNull($result['survivor_id']);
        $this->assertSame([], $result['loser_ids']);
    }

    /** I-7:恰一現役列但該列本身非啟用時,退回在啟用列中擇優,不能選一個 status=0 的 survivor。 */
    public function test_classify_exactly_one_live_row_falls_back_to_active_when_live_row_inactive(): void
    {
        $liveInactive = $this->makeBank('XC46', ['status' => 0]);
        $this->makeCfb(['bank_code' => 'XC46']); // 現役引用掛在已停用列上
        $activeZombie = $this->makeBank('XC47', ['status' => 1]);

        $result = $this->service->classify(collect([$liveInactive->refresh(), $activeZombie->refresh()]));

        $this->assertSame('auto', $result['tier']);
        $this->assertSame($activeZombie->id, $result['survivor_id']);
        $this->assertSame([$liveInactive->id], $result['loser_ids']);
    }
}
