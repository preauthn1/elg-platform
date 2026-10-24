<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DeactivateBankRowsCommand 測試（錯置／廢棄 banks 列的停用）
 *
 * 守備重點有二：
 * 1. 停用必須同時寫 status=0 與 deleted_at——光寫 status 會被 AgentBankSyncService
 *    打回（它挑 target 只濾 deleted_at 不看 status，見 RenameBankCommand 的實案註記）。
 * 2. 任一列只要還被引用（會員綁定／available_banks 舊碼／cash_flow_banks），整批
 *    零寫入。停用被引用中的列＝該銀行選不到或代付查表落空，寧可停手轉人工。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Bank/DeactivateBankRowsCommandTest.php
 */
class DeactivateBankRowsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const AGENT_GROUP = 99800;

    private const CASH_FLOW_ID = 99801;

    private const MEMBER_ID = 99802;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS2', 'name' => 'Test Currency 2', 'rate' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(string $code, int $status = 1): Bank
    {
        return Bank::create([
            'agent_group_id' => self::AGENT_GROUP,
            'type' => 4,
            'currency_id' => $this->currencyId,
            'name' => '(TS2) - Misplaced Bank ' . $code,
            'code' => $code,
            'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function seedAcfReferencing(string $elgCode): AgentCashFlow
    {
        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Payout Deact', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);

        return AgentCashFlow::create([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => $this->currencyId,
            'info' => ['available_banks' => [['BankCode_ELG' => $elgCode, 'BankCode_Third' => 'T_THIRD']]],
            'admin' => 0,
        ]);
    }

    public function test_deactivates_clean_row_writing_both_status_and_deleted_at(): void
    {
        $bank = $this->seedBank('TSTDEA01');

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = DB::table('banks')->where('id', $bank->id)->first();
        $this->assertSame(0, (int) $fresh->status);
        $this->assertNotNull($fresh->deleted_at, 'deleted_at 必須一併寫入，否則會被 sync 打回 status=1');
    }

    private function seedWithdrawalOrder(int $bankId, int $status): int
    {
        return (int) DB::table('withdrawal_orders')->insertGetId([
            'bank_id' => $bankId,
            'bank_name' => '(TS2) - Misplaced Bank',
            'bank_code' => 'TSTDEA00',
            'account_name' => 'TEST NAME',
            'account_no' => '0000000000',
            'member_id' => self::MEMBER_ID,
            'amount' => 100,
            'third_party_order_no' => '',
            'change_logs' => '[]',
            'status' => $status,
        ]);
    }

    public function test_blocks_when_row_has_unsettled_withdrawal_order(): void
    {
        // 未終態的提款單指向一列已停用的銀行，會在後續流程查無而卡單
        $bank = $this->seedBank('TSTDEA30');
        $this->seedWithdrawalOrder($bank->id, WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '廢棄列清理',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_allows_when_all_withdrawal_orders_are_terminal(): void
    {
        // 歷史單必然永遠存在；全數計數會讓任何用過的銀行都停不掉
        $bank = $this->seedBank('TSTDEA31');
        $this->seedWithdrawalOrder($bank->id, WithdrawalOrderStatusEnum::TRANSFERRED->value);
        $this->seedWithdrawalOrder($bank->id, WithdrawalOrderStatusEnum::REFUND->value);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '廢棄列清理',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_blocks_when_row_is_referenced_by_agent_bank_account(): void
    {
        $bank = $this->seedBank('TSTDEA32');
        DB::table('agent_bank_accounts')->insert([
            'agent_id' => 99899,
            'bank_id' => $bank->id,
            'account_name' => 'TEST AGENT',
            'account_no' => '1111111111',
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '廢棄列清理',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_rejects_comma_separated_bank_ids(): void
    {
        // intval("9,14") === 9：靜默只處理第一個，其餘完全不提
        $bank = $this->seedBank('TSTDEA33');

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id . ',99899999'],
            '--reason' => '廢棄列清理',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_rejects_reason_with_invalid_utf8_before_writing(): void
    {
        // json_encode 遇無效 UTF-8 會回 false，靜默寫入空審計——而停用那筆
        // 不可逆的寫入照樣 commit。不可逆操作失去審計，且沒有人會發現。
        $bank = $this->seedBank('TSTDEA20');

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => "廢棄列清理\xB1\x31",
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('action', 'deactivate_row')->where('loser_bank_id', $bank->id)->count()
        );
    }

    public function test_rejects_overlong_reason_before_writing(): void
    {
        $bank = $this->seedBank('TSTDEA21');

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => str_repeat('清', 2001),
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_blocks_when_row_has_member_binding(): void
    {
        $bank = $this->seedBank('TSTDEA02');
        DB::table('member_bank_accounts')->insert([
            'member_id' => self::MEMBER_ID, 'bank_id' => $bank->id,
            'account_name' => 'TEST NAME', 'account_no' => '0000000000',
            'status' => 1, 'operator_cs' => 0,
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_blocks_when_code_is_referenced_by_available_banks(): void
    {
        $bank = $this->seedBank('TSTDEA03');
        $this->seedAcfReferencing('TSTDEA03');

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_blocks_when_row_is_referenced_by_cash_flow_banks(): void
    {
        $bank = $this->seedBank('TSTDEA04');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => self::CASH_FLOW_ID, 'currency_id' => $this->currencyId,
            'bank_id' => $bank->id, 'bank_code' => 'TSTDEA04', 'provider_bank_code' => 'T_PROV',
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_inactive_cash_flow_bank_reference_still_blocks_by_default(): void
    {
        $bank = $this->seedBank('TSTDEA10');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => self::CASH_FLOW_ID, 'currency_id' => $this->currencyId,
            'bank_id' => $bank->id, 'bank_code' => 'TSTDEA10', 'provider_bank_code' => 'T_PROV',
            'status' => 0,
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '廢棄列清理',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_allow_inactive_cfb_permits_deactivation_when_reference_is_inactive(): void
    {
        $bank = $this->seedBank('TSTDEA11');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => self::CASH_FLOW_ID, 'currency_id' => $this->currencyId,
            'bank_id' => $bank->id, 'bank_code' => 'TSTDEA11', 'provider_bank_code' => 'T_PROV',
            'status' => 0,
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '廢棄列清理',
            '--allow-inactive-cfb' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_allow_inactive_cfb_still_blocks_an_active_cfb_reference(): void
    {
        $bank = $this->seedBank('TSTDEA12');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => self::CASH_FLOW_ID, 'currency_id' => $this->currencyId,
            'bank_id' => $bank->id, 'bank_code' => 'TSTDEA12', 'provider_bank_code' => 'T_PROV',
            'status' => 1,
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '廢棄列清理',
            '--allow-inactive-cfb' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_one_blocked_row_aborts_the_whole_batch(): void
    {
        $clean = $this->seedBank('TSTDEA05');
        $bound = $this->seedBank('TSTDEA06');
        DB::table('member_bank_accounts')->insert([
            'member_id' => self::MEMBER_ID, 'bank_id' => $bound->id,
            'account_name' => 'TEST NAME', 'account_no' => '0000000000',
            'status' => 1, 'operator_cs' => 0,
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$clean->id, $bound->id],
            '--reason' => '幣別錯置的孤立列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        // 乾淨那列也不能被寫：阻斷一律零寫入
        $this->assertSame(1, (int) DB::table('banks')->where('id', $clean->id)->value('status'));
    }

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        $bank = $this->seedBank('TSTDEA07');

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
        ])->assertExitCode(0);

        $fresh = DB::table('banks')->where('id', $bank->id)->first();
        $this->assertSame(1, (int) $fresh->status);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('loser_bank_id', $bank->id)->count());
    }

    public function test_writes_audit_row_with_restorable_before_values(): void
    {
        $bank = $this->seedBank('TSTDEA08');

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $log = DB::table('bank_dedup_logs')->where('loser_bank_id', $bank->id)->first();
        $this->assertNotNull($log, '停用必須留審計');
        $this->assertSame('deactivate_row', $log->action);

        // detail 要能反演：還原就是把 was 寫回去
        $detail = json_decode((string) $log->detail, true);
        $this->assertSame(1, $detail['was']['status']);
        $this->assertNull($detail['was']['deleted_at']);
        $this->assertSame('幣別錯置的孤立列', $detail['reason']);
    }

    public function test_already_deactivated_row_is_skipped_not_rewritten(): void
    {
        $bank = $this->seedBank('TSTDEA09', status: 0);
        DB::table('banks')->where('id', $bank->id)->update(['deleted_at' => '2026-01-01 00:00:00']);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        // 原始收斂時間不可被刷成當下
        $this->assertSame(
            '2026-01-01 00:00:00',
            (string) DB::table('banks')->where('id', $bank->id)->value('deleted_at')
        );
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('loser_bank_id', $bank->id)->count());
    }

    /**
     * `status=1` 但 `deleted_at` 非空是本專案真實存在的矛盾形態（VWorld 實測），
     * 它不會被「已停用且已標刪」那條跳過，會一路走進寫入。無條件覆寫 `deleted_at`
     * 會把原始標刪時間刷成當下，讓 `updated_at` 回推式的誤傷稽核與審計時序一併失真。
     */
    public function test_preserves_original_deleted_at_on_soft_deleted_but_active_row(): void
    {
        $bank = $this->seedBank('TSTDEA10', status: 1);
        DB::table('banks')->where('id', $bank->id)->update(['deleted_at' => '2026-01-01 00:00:00']);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = DB::table('banks')->where('id', $bank->id)->first();

        $this->assertSame(0, (int) $fresh->status, 'status 仍須被停用');
        $this->assertSame(
            '2026-01-01 00:00:00',
            (string) $fresh->deleted_at,
            '原始標刪時間不可被本次執行刷成當下'
        );

        $detail = json_decode(
            (string) DB::table('bank_dedup_logs')->where('loser_bank_id', $bank->id)->value('detail'),
            true
        );

        $this->assertSame('2026-01-01 00:00:00', $detail['was']['deleted_at']);
        $this->assertSame(
            '2026-01-01 00:00:00',
            $detail['aft']['deleted_at'],
            '審計的事後值必須等於庫裡的實值，否則反演會把時間寫錯'
        );
    }

    /**
     * 寫入目標由容器的 -e DB_CONNECTION 決定、不在命令參數裡，各白標 banks.id 區間又
     * 重疊，連錯站時預檢一樣會全綠。故連線與庫名必須在預演階段就印出來——那是操作者
     * 唯一能在寫入前察覺「我打錯站」的機會。
     */
    public function test_preview_discloses_write_target_database(): void
    {
        $bank = $this->seedBank('TSTDEA11');

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
        ])
            // 兩個斷言不可共存於同一行輸出：PendingCommand 逐行消耗，
            // 同一行同時滿足兩個期待時只會消掉第一個，第二個必然落空。
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(0);
    }

    /**
     * 阻斷才是最需要核對環境的時刻：操作者要判斷「這是真的有引用，還是我連錯站」。
     * 揭露若排在預檢之後，這個失敗出口就看不到自己打的是哪個庫。
     */
    public function test_blocked_run_still_discloses_write_target_database(): void
    {
        $bank = $this->seedBank('TSTDEA12');
        $this->seedWithdrawalOrder($bank->id, WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '幣別錯置的孤立列',
        ])
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(1);
    }

    private const OTHER_AGENT_GROUP = 99810;

    private const OTHER_CASH_FLOW_ID = 99811;

    private function seedBankInGroup(string $code, int $agentGroupId): Bank
    {
        return Bank::create([
            'agent_group_id' => $agentGroupId,
            'type' => 4,
            'currency_id' => $this->currencyId,
            'name' => '(TS2) - Scoped Bank ' . $code,
            'code' => $code,
            'status' => 1,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    /**
     * @param  list<string>  $elgCodes
     */
    private function seedPayoutAcf(int $cashFlowId, int $agentGroupId, int $status, array $elgCodes): AgentCashFlow
    {
        if (!CashFlow::query()->whereKey($cashFlowId)->exists()) {
            CashFlow::create([
                'id' => $cashFlowId, 'name' => 'Test Payout Scope ' . $cashFlowId, 'info' => [],
                'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
            ]);
        }

        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlowId,
            'agent_group_id' => $agentGroupId,
            'currency_id' => $this->currencyId,
            'status' => $status,
            'info' => ['available_banks' => array_map(
                static fn (string $code): array => ['BankCode_ELG' => $code, 'BankCode_Third' => 'T_THIRD'],
                $elgCodes
            )],
            'admin' => 0,
        ]);
    }

    /**
     * 預設模式維持全站範圍：其他群組清單裡的同碼項目照樣阻斷。
     * 鎖住這條，避免日後有人把縮小範圍的判準悄悄變成預設。
     */
    public function test_default_scope_blocks_available_banks_reference_from_other_group(): void
    {
        $bank = $this->seedBank('TSTDEA40');
        $this->seedPayoutAcf(self::CASH_FLOW_ID, self::OTHER_AGENT_GROUP, 1, ['TSTDEA40']);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '測試組殘列清理',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    /**
     * 代付查的是訂單所屬群組的 ACF：別的群組清單裡的同碼項目，不會因為停用本列而查不到。
     */
    public function test_scope_refs_to_group_ignores_available_banks_reference_from_other_group(): void
    {
        $bank = $this->seedBank('TSTDEA41');
        $this->seedPayoutAcf(self::CASH_FLOW_ID, self::OTHER_AGENT_GROUP, 1, ['TSTDEA41']);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '測試組殘列清理',
            '--scope-refs-to-group' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('banks')->where('id', $bank->id)->value('status'));

        $detail = json_decode(
            (string) DB::table('bank_dedup_logs')->where('loser_bank_id', $bank->id)->value('detail'),
            true
        );

        $this->assertSame('group', $detail['refs_scope'], '審計要能分辨這批是用縮小範圍的判準放行的');
    }

    public function test_scope_refs_to_group_still_blocks_active_list_in_same_group(): void
    {
        $bank = $this->seedBank('TSTDEA42');
        $this->seedPayoutAcf(self::CASH_FLOW_ID, self::AGENT_GROUP, 1, ['TSTDEA42']);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '測試組殘列清理',
            '--scope-refs-to-group' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    /**
     * 停用中的 ACF 不送單，裡面的項目是死引用；重新啟用時會員也選不到已停用的銀行。
     */
    public function test_scope_refs_to_group_ignores_inactive_list_in_same_group(): void
    {
        $bank = $this->seedBank('TSTDEA43');
        $this->seedPayoutAcf(self::CASH_FLOW_ID, self::AGENT_GROUP, 0, ['TSTDEA43']);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '測試組殘列清理',
            '--scope-refs-to-group' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    /**
     * 本群組沒有接這家金流商：它的 cfb 列不會拿本群組訂單的碼去查。
     */
    public function test_scope_refs_to_group_ignores_cfb_code_of_cash_flow_not_used_by_group(): void
    {
        $bank = $this->seedBank('TSTDEA44');
        $elsewhere = $this->seedBankInGroup('TSTDEA44', self::OTHER_AGENT_GROUP);
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => self::OTHER_CASH_FLOW_ID, 'currency_id' => $this->currencyId,
            'bank_id' => $elsewhere->id, 'bank_code' => 'TSTDEA44', 'provider_bank_code' => 'T_PROV',
            'status' => 1,
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '測試組殘列清理',
            '--scope-refs-to-group' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    /**
     * 本群組有啟用 ACF 接這家金流商：代付查表以 (cash_flow_id, currency_id, bank_code)
     * 為鍵，會拿本群組訂單的碼去查這張 cfb。
     */
    public function test_scope_refs_to_group_blocks_cfb_code_of_cash_flow_used_by_group(): void
    {
        $bank = $this->seedBank('TSTDEA45');
        $this->seedPayoutAcf(self::CASH_FLOW_ID, self::AGENT_GROUP, 1, ['TSTOTHER45']);
        $elsewhere = $this->seedBankInGroup('TSTDEA45', self::OTHER_AGENT_GROUP);
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => self::CASH_FLOW_ID, 'currency_id' => $this->currencyId,
            'bank_id' => $elsewhere->id, 'bank_code' => 'TSTDEA45', 'provider_bank_code' => 'T_PROV',
            'status' => 1,
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '測試組殘列清理',
            '--scope-refs-to-group' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    public function test_scope_refs_to_group_still_blocks_cfb_referencing_row_id(): void
    {
        $bank = $this->seedBank('TSTDEA46');
        DB::table('cash_flow_banks')->insert([
            'cash_flow_id' => self::OTHER_CASH_FLOW_ID, 'currency_id' => $this->currencyId,
            'bank_id' => $bank->id, 'bank_code' => 'TSTDEA46', 'provider_bank_code' => 'T_PROV',
            'status' => 1,
        ]);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '測試組殘列清理',
            '--scope-refs-to-group' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }

    /**
     * 基底層列是 AgentBankSyncService 複製到各群組的模板，引用面本來就是全站。
     */
    public function test_scope_refs_to_group_rejects_base_layer_row(): void
    {
        $bank = $this->seedBankInGroup('TSTDEA47', 0);

        $this->artisan('bank:deactivate-rows', [
            '--bank' => [$bank->id],
            '--reason' => '測試組殘列清理',
            '--scope-refs-to-group' => true,
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(1, (int) DB::table('banks')->where('id', $bank->id)->value('status'));
    }
}
