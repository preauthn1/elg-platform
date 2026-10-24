<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashFlow\Bank;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Services\CashFlow\Bank\AvailableBanksAlignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AvailableBanksAlignService 單元測試。
 * DatabaseTransactions 避免污染共享庫；哨兵 cash_flow_id=99555（代付 type=3）。
 */
class AvailableBanksAlignServiceTest extends TestCase
{
    use DatabaseTransactions;

    private const CASH_FLOW_ID = 99555;

    private const AGENT_GROUP = 99700;

    private function insertCurrency(string $code = 'TST'): int
    {
        return (int) DB::table('currencies')->insertGetId([
            'code' => $code, 'name' => $code . ' Currency', 'rate' => 1, 'admin' => 0,
        ]);
    }

    private function makeWithdrawalCashFlow(): void
    {
        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Payout', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);
    }

    /** @param array<int, array<string, mixed>> $availableBanks */
    private function makeAgentCashFlow(?int $currencyId, array $availableBanks): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => self::CASH_FLOW_ID, 'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => $currencyId, 'info' => ['available_banks' => $availableBanks], 'admin' => 0,
        ]);
    }

    private function makeBank(int $currencyId, string $name, string $code, int $status = 1, int $type = 1): void
    {
        Bank::create([
            'agent_group_id' => self::AGENT_GROUP, 'type' => $type, 'currency_id' => $currencyId,
            'name' => $name, 'code' => $code, 'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0, 'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function detect(): Collection
    {
        return app(AvailableBanksAlignService::class)->detectDrift(self::AGENT_GROUP, self::CASH_FLOW_ID);
    }

    public function test_drift_with_canonical_resolves_align(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'TTP_MB']]);

        $items = $this->detect();
        $this->assertCount(1, $items);
        $this->assertSame('align', $items[0]['resolution']);
        $this->assertSame('MayBank', $items[0]['new_code']);
    }

    public function test_no_row_resolves_unmatched(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'GHOST', 'BankCode_Third' => 'X']]);

        $this->assertSame('unmatched', $this->detect()[0]['resolution']);
    }

    public function test_no_active_canonical_resolves_unmatched(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Old Bank', 'WTMY02', status: 0);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'X']]);

        $this->assertSame('unmatched', $this->detect()[0]['resolution']);
    }

    public function test_multiple_active_canonical_resolves_conflict(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Ambiguous', 'OLD01', status: 0);
        $this->makeBank($c, '(TST) - Ambiguous', 'NEW_A', status: 1);
        $this->makeBank($c, '(TST) - Ambiguous', 'NEW_B', status: 1);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'OLD01', 'BankCode_Third' => 'X']]);

        $this->assertSame('conflict', $this->detect()[0]['resolution']);
    }

    public function test_already_aligned_entry_skipped(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'TTP_MB']]);

        $this->assertTrue($this->detect()->isEmpty());
    }

    public function test_null_currency_resolves_unmatched(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 1);
        $this->makeAgentCashFlow(null, [['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'X']]);

        $items = $this->detect();
        $this->assertSame('unmatched', $items[0]['resolution']);
        $this->assertNull($items[0]['currency_id']);
    }

    public function test_old_code_multiple_type_name_resolves_conflict(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Bank A', 'DUP01', status: 0, type: 1);
        $this->makeBank($c, '(TST) - Bank B', 'DUP01', status: 0, type: 2);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'DUP01', 'BankCode_Third' => 'X']]);

        $this->assertSame('conflict', $this->detect()[0]['resolution']);
    }

    public function test_cross_type_active_code_resolves_conflict(): void
    {
        // target 恰為另一 type 的 active code（type=2 active + type=1 inactive）→ conflict，不誤判已對齊
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Ewallet', 'DUP', status: 1, type: 2);
        $this->makeBank($c, '(TST) - Bank', 'DUP', status: 0, type: 1);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'DUP', 'BankCode_Third' => 'X']]);

        $this->assertSame('conflict', $this->detect()[0]['resolution']);
    }

    public function test_name_case_variant_aligns(): void
    {
        // 舊碼 row name 與 canonical name 僅大小寫不同 → PHP nameKey 視為同組，可 align
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, 'MAYBANK', 'MayBank', status: 1);
        $this->makeBank($c, 'Maybank', 'WTMY02', status: 0);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'X']]);

        $items = $this->detect();
        $this->assertSame('align', $items[0]['resolution']);
        $this->assertSame('MayBank', $items[0]['new_code']);
    }

    public function test_name_trailing_space_consistent(): void
    {
        // old name 尾隨空白，PHP nameKey(trim) 分組與 canonical PHP filter 同源 → 一致對齊
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - CIMB', 'CIMB', status: 1);
        $this->makeBank($c, '(TST) - CIMB ', 'CIMB_OLD', status: 0);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'CIMB_OLD', 'BankCode_Third' => 'X']]);

        $items = $this->detect();
        $this->assertSame('align', $items[0]['resolution']);
        $this->assertSame('CIMB', $items[0]['new_code']);
    }

    public function test_code_binary_exact_no_case_variant_match(): void
    {
        // target 與 banks.code 僅大小寫不同，BINARY exact 下不匹配 → unmatched（不誤 case-fold 匹配）
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'maybank', 'BankCode_Third' => 'X']]);

        $this->assertSame('unmatched', $this->detect()[0]['resolution']);
    }

    /** @return array{aligned: int, unmatched: int, conflict: int} */
    private function align(): array
    {
        return app(AvailableBanksAlignService::class)->align(self::AGENT_GROUP, self::CASH_FLOW_ID);
    }

    public function test_align_renames_elg_preserving_third_code_and_audits(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $acf = $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'TTP_MB', 'min_amount' => 50]]);

        $summary = $this->align();

        $this->assertSame(1, $summary['aligned']);
        $entry = $acf->fresh()->info['available_banks'][0];
        $this->assertSame('MayBank', $entry['BankCode_ELG']);
        $this->assertSame('TTP_MB', $entry['BankCode_Third']);
        $this->assertSame(50, $entry['min_amount']);
        $this->assertSame(1, DB::table('available_banks_align_logs')
            ->where('agent_cash_flow_id', $acf->id)->where('action', 'align')->count());
    }

    public function test_align_is_idempotent(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $acf = $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'TTP_MB']]);

        $this->align();
        $second = $this->align();

        $this->assertSame(0, $second['aligned']);
        $this->assertSame('MayBank', $acf->fresh()->info['available_banks'][0]['BankCode_ELG']);
    }

    public function test_align_records_unmatched_without_changing_data(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $acf = $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'GHOST', 'BankCode_Third' => 'X']]);

        $summary = $this->align();

        $this->assertSame(1, $summary['unmatched']);
        $this->assertSame('GHOST', $acf->fresh()->info['available_banks'][0]['BankCode_ELG']);
        $this->assertSame(1, DB::table('available_banks_align_logs')
            ->where('agent_cash_flow_id', $acf->id)->where('action', 'unmatched')->count());
    }

    public function test_align_mixed_resolutions_in_one_acf(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $this->makeBank($c, '(TST) - CIMB', 'CIMB01', status: 1);
        $acf = $this->makeAgentCashFlow($c, [
            ['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'A'],
            ['BankCode_ELG' => 'GHOST', 'BankCode_Third' => 'B'],
            ['BankCode_ELG' => 'CIMB01', 'BankCode_Third' => 'C'],
        ]);

        $summary = $this->align();

        $this->assertSame(1, $summary['aligned']);
        $this->assertSame(1, $summary['unmatched']);
        $entries = $acf->fresh()->info['available_banks'];
        $this->assertSame('MayBank', $entries[0]['BankCode_ELG']); // align
        $this->assertSame('GHOST', $entries[1]['BankCode_ELG']);   // unmatched 不改
        $this->assertSame('CIMB01', $entries[2]['BankCode_ELG']);  // 已對齊不動
    }

    public function test_align_becomes_conflict_when_canonical_already_in_available_banks(): void
    {
        // F1（2026-07-11 Dev 實跑抓到）：目標 canonical 已存在於同 ACF 的其他 entry 時，
        // rename 會製造重複 target entry（Third 不同時代付行為隨陣列順序不確定）→ 應標 conflict 不動、待人工合併
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $acf = $this->makeAgentCashFlow($c, [
            ['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'A'],
            ['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'C'],
        ]);

        $summary = $this->align();

        $this->assertSame(0, $summary['aligned']);
        $this->assertSame(1, $summary['conflict']);
        $entries = $acf->fresh()->info['available_banks'];
        $this->assertSame('WTMY02', $entries[0]['BankCode_ELG'], '目標碼已存在時不得 rename（防重複 entry）');
        $this->assertSame('MayBank', $entries[1]['BankCode_ELG']);
        $this->assertSame(
            1,
            collect($acf->fresh()->info['available_banks'])->where('BankCode_ELG', 'MayBank')->count(),
            '同 target 不得出現重複 entry',
        );
    }

    /**
     * `--prune-duplicate-entries` gate 全過：canonical 已在同 ACF＋Third 嚴格相同＋無攔阻中
     * 舊碼單 ⇒ 死 entry 整個刪除、canonical 保留，審計 action=prune。
     */
    public function test_prune_deletes_duplicate_entry_when_third_matches(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $acf = $this->makeAgentCashFlow($c, [
            ['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'TTP_MB'],
            ['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'TTP_MB'],
        ]);

        $summary = app(AvailableBanksAlignService::class)->align(self::AGENT_GROUP, self::CASH_FLOW_ID, pruneDuplicates: true);

        $this->assertSame(1, $summary['pruned']);
        $this->assertSame(0, $summary['conflict']);

        $entries = $acf->fresh()->info['available_banks'];
        $this->assertCount(1, $entries, '死 entry 要被刪除');
        $this->assertSame('MayBank', $entries[0]['BankCode_ELG'], 'canonical entry 必須保留');

        $this->assertSame(
            'prune',
            DB::table('available_banks_align_logs')->where('agent_cash_flow_id', $acf->id)->value('action'),
            '刪除必須落審計'
        );
    }

    /**
     * gate 2 擋下：兩 entry 的 Third 不同——刪除會改變送三方的碼，維持 conflict 不動。
     */
    public function test_prune_blocked_when_third_differs(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $acf = $this->makeAgentCashFlow($c, [
            ['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'A'],
            ['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'C'],
        ]);

        $summary = app(AvailableBanksAlignService::class)->align(self::AGENT_GROUP, self::CASH_FLOW_ID, pruneDuplicates: true);

        $this->assertSame(0, $summary['pruned']);
        $this->assertSame(1, $summary['conflict']);
        $this->assertCount(2, $acf->fresh()->info['available_banks'], 'Third 不同時兩 entry 都不得動');
    }

    /**
     * gate 3 擋下：有 in-flight／可重發舊碼單（status 21/22/29；23 已退役）——這些單以 bank_code
     * 快照查 JSON entry，刪了會查不到碼而擋單。
     */
    public function test_prune_blocked_when_blocking_withdrawal_order_exists(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $acf = $this->makeAgentCashFlow($c, [
            ['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'TTP_MB'],
            ['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'TTP_MB'],
        ]);

        DB::table('withdrawal_orders')->insert([
            'bank_name' => 'Maybank', 'bank_code' => 'WTMY02', 'account_name' => 'test',
            'account_no' => '0000000000', 'member_id' => 0, 'amount' => 100,
            'third_party_order_no' => '', 'status' => 22, 'change_logs' => '',
        ]);

        $summary = app(AvailableBanksAlignService::class)->align(self::AGENT_GROUP, self::CASH_FLOW_ID, pruneDuplicates: true);

        $this->assertSame(0, $summary['pruned']);
        $this->assertSame(1, $summary['conflict']);
        $this->assertCount(2, $acf->fresh()->info['available_banks'], '有攔阻中舊碼單時不得刪 entry');
    }

    /**
     * 不帶 prune 旗標時維持原 conflict 行為（detect 層也一致）。
     */
    public function test_detect_reports_prune_only_with_flag(): void
    {
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $this->makeAgentCashFlow($c, [
            ['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'TTP_MB'],
            ['BankCode_ELG' => 'MayBank', 'BankCode_Third' => 'TTP_MB'],
        ]);

        $service = app(AvailableBanksAlignService::class);

        $this->assertSame('conflict', $service->detectDrift(self::AGENT_GROUP, self::CASH_FLOW_ID)[0]['resolution'], '無旗標維持 conflict');
        $this->assertSame('prune', $service->detectDrift(self::AGENT_GROUP, self::CASH_FLOW_ID, pruneDuplicates: true)[0]['resolution'], '帶旗標判 prune');
    }

    public function test_align_handles_double_encoded_info(): void
    {
        // $acf->info 讀回 string（double-encoded legacy）→ readInfo 容錯、execute 正確 rename
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $this->makeBank($c, '(TST) - Maybank', 'WTMY02', status: 0);
        $acf = $this->makeAgentCashFlow($c, [['BankCode_ELG' => 'X', 'BankCode_Third' => 'Y']]);
        DB::table('agent_cash_flows')->where('id', $acf->id)->update([
            'info' => json_encode(json_encode(['available_banks' => [['BankCode_ELG' => 'WTMY02', 'BankCode_Third' => 'TTP_MB']]])),
        ]);

        $summary = $this->align();

        $this->assertSame(1, $summary['aligned']);
        $this->assertSame('MayBank', $acf->fresh()->info['available_banks'][0]['BankCode_ELG']);
    }

    public function test_align_truncates_long_old_code_in_audit(): void
    {
        // target 超 64 字（JSON 原值任意長），audit old_code truncate 到 64、insert 不 rollback
        $c = $this->insertCurrency();
        $this->makeWithdrawalCashFlow();
        $this->makeBank($c, '(TST) - Maybank', 'MayBank', status: 1);
        $longElg = str_repeat('A', 70);
        $acf = $this->makeAgentCashFlow($c, [['BankCode_ELG' => $longElg, 'BankCode_Third' => 'X']]);

        $summary = $this->align();

        $this->assertSame(1, $summary['unmatched']); // 70 字 code 無對應 → unmatched
        $audited = (string) DB::table('available_banks_align_logs')
            ->where('agent_cash_flow_id', $acf->id)->value('old_code');
        $this->assertSame(64, mb_strlen($audited)); // truncate 到 64、insert 成功不 rollback
    }
}
