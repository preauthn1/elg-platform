<?php

declare(strict_types=1);

namespace Tests\Feature\CashFlow;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlowBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * VerifyPayoutBankParityCommand 測試
 *
 * 使用 DatabaseTransactions 避免污染共享庫。
 * cash_flow_id 哨兵值：99555（與 B-T2 ConvertPayoutBankCodeTest 一致）。
 *
 * php artisan test tests/Feature/CashFlow/VerifyPayoutBankParityCommandTest.php
 */
class VerifyPayoutBankParityCommandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 插入測試幣別，返回 currency_id。
     * 用 DB::table 直寫避免 Currency model 的 rate/admin NOT NULL 無預設限制。
     */
    private function insertCurrency(): int
    {
        return (int) DB::table('currencies')->insertGetId([
            'code' => 'TST',
            'name' => 'Test Currency',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * 建立 AgentCashFlow，info.available_banks 由 $banks 組成。
     *
     * @param  array<array<string, string>>  $banks
     */
    private function makeAgentCashFlow(
        int $currencyId,
        array $banks = [],
        int $cashFlowId = 99555,
        int $status = 0,
        int $agentGroupId = 0,
    ): AgentCashFlow {
        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlowId,
            'currency_id' => $currencyId,
            'agent_group_id' => $agentGroupId,
            'status' => $status,
            'info' => ['available_banks' => $banks],
            'admin' => 0,
        ]);
    }

    /**
     * 建立代理群組層的 banks 列（--classify-gaps 判「群組層有無活列」用）。
     */
    private function makeBank(int $currencyId, string $code, int $agentGroupId, int $status = 1, ?string $deletedAt = null): Bank
    {
        return Bank::create([
            'deleted_at' => $deletedAt,
            'agent_group_id' => $agentGroupId,
            'type' => 1,
            'currency_id' => $currencyId,
            'name' => '(TST) - ' . $code,
            'code' => $code,
            'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    /**
     * 建立 CashFlowBank 記錄。
     * live 表 cash_flow_banks 無唯一約束，bank_id nullable。
     */
    private function makeCashFlowBank(
        int $cashFlowId,
        int $currencyId,
        string $bankCode,
        string $providerBankCode,
        int $status = 1,
        int $bankId = 0,
    ): void {
        CashFlowBank::create([
            'cash_flow_id' => $cashFlowId,
            'currency_id' => $currencyId,
            'bank_id' => $bankId,
            'bank_code' => $bankCode,
            'provider_bank_code' => $providerBankCode,
            'status' => $status,
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 1: parity 不一致 → EXIT SUCCESS（無硬 gate 違反，僅 parity 差異）
    // -------------------------------------------------------------------------

    public function test_parity_mismatch_exits_success_and_shows_elg_code(): void
    {
        $currencyId = $this->insertCurrency();

        // JSON: MAYBANK → MBB_JSON；Table: MAYBANK → MBB_TABLE（值不同 → parity 不一致）
        // 兩者均非空 → gate 2 不觸發；僅 1 列 → gate 1 不觸發；無 null → gate 3 不觸發
        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB_JSON'],
        ]);
        $this->makeCashFlowBank(99555, $currencyId, 'MAYBANK', 'MBB_TABLE');

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->expectsOutputToContain('MAYBANK')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // Test 2: 同鍵（cash_flow_id, currency_id, bank_code）status=1 兩列 → 硬 gate 1 → FAILURE
    // -------------------------------------------------------------------------

    public function test_duplicate_key_exits_failure_and_lists_key(): void
    {
        $currencyId = $this->insertCurrency();

        // live 表無唯一約束，可直接插入同 bank_code 兩列（用不同 bank_id 表意語意更清晰）
        $this->makeCashFlowBank(99555, $currencyId, 'MAYBANK', 'MBB_A', bankId: 1);
        $this->makeCashFlowBank(99555, $currencyId, 'MAYBANK', 'MBB_B', bankId: 2);

        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB_A'],
        ]);

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->expectsOutputToContain('MAYBANK')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 3: JSON 有非空 BankCode_Third 但 cash_flow_banks 查無 → 硬 gate 2 → FAILURE
    // -------------------------------------------------------------------------

    public function test_missing_table_entry_exits_failure(): void
    {
        $currencyId = $this->insertCurrency();

        // JSON 有 BankCode_Third='CIMB_CODE' 但 cash_flow_banks 無任何 CIMB 列
        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB_CODE'],
        ]);

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 3b: cfb 的碼只有大小寫與 JSON 不同 → 視為查無 → 硬 gate 2 → FAILURE
    //
    // bank_code 的 collation 是 utf8mb4_unicode_ci、大小寫不敏感，本命令若用一般 `=`
    // 查表就會撈到 MayBank 那列、判 parity 通過；但 runtime 是 BINARY 逐字比對，
    // 實際出款會查無碼而擋單 ⇒ 等於用寬鬆的尺量嚴格的路徑，給出假保證。
    // 驗證器的比對必須與 runtime 一模一樣。
    // -------------------------------------------------------------------------

    public function test_case_variant_table_entry_counts_as_missing(): void
    {
        $currencyId = $this->insertCurrency();

        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB'],
        ]);
        // 只差大小寫：runtime 認不得，驗證器也必須認不得
        $this->makeCashFlowBank(99555, $currencyId, 'MayBank', 'MBB');

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 4: --cash-flow-id 過濾生效（別家 cash_flow 的異常不影響本家結果）
    // -------------------------------------------------------------------------

    public function test_cash_flow_id_filter_isolates_results(): void
    {
        $currencyId = $this->insertCurrency();

        // cash_flow_id=99555：乾淨（JSON 與表一致，無任何 gate 違反）
        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB'],
        ], cashFlowId: 99555);
        $this->makeCashFlowBank(99555, $currencyId, 'MAYBANK', 'MBB');

        // cash_flow_id=99556：gate 1 違反（同 bank_code CIMB 兩列 status=1）
        $this->makeCashFlowBank(99556, $currencyId, 'CIMB', 'CIMB_CODE_A', bankId: 10);
        $this->makeCashFlowBank(99556, $currencyId, 'CIMB', 'CIMB_CODE_B', bankId: 11);

        // 只驗 99555 → gate 1 僅看 99555 → 無重複 → 所有 gate 通過 → SUCCESS
        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // Test 5: 全一致且無任何異常 → SUCCESS + "硬 gate 全數通過"
    // -------------------------------------------------------------------------

    public function test_all_consistent_exits_success_with_gate_message(): void
    {
        $currencyId = $this->insertCurrency();

        // JSON 與表完全一致（MBB === MBB），無 gate 違反
        // 同時以 DB raw update 覆蓋 fallback 分支（double-encoded info，$acf->info 讀回為 string）
        $acf = $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB'],
        ]);
        DB::table('agent_cash_flows')
            ->where('id', $acf->id)
            ->update(['info' => json_encode(json_encode(['available_banks' => [['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB']]]))]);
        $this->makeCashFlowBank(99555, $currencyId, 'MAYBANK', 'MBB');

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->expectsOutputToContain('硬 gate 全數通過')
            ->assertExitCode(0);
    }

    // -------------------------------------------------------------------------
    // Test 6: currency_id 為 null → 硬 gate 3 → FAILURE
    // -------------------------------------------------------------------------

    public function test_null_currency_id_exits_failure_gate3(): void
    {
        // currency_id 欄位 nullable（migration: $table->unsignedInteger('currency_id')->nullable()）
        // gate 3 查詢：WHERE cash_flow_id = 99555 AND (cash_flow_id IS NULL OR currency_id IS NULL)
        //             → 等效 WHERE cash_flow_id = 99555 AND currency_id IS NULL → 命中此列
        AgentCashFlow::create([
            'cash_flow_id' => 99555,
            'currency_id' => null,
            'info' => [],
            'admin' => 0,
        ]);

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->expectsOutputToContain('硬 gate 3')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 7: --classify-gaps：ACF 服務中 ＋ 該碼在 ACF 所屬群組有活列 ＋ cfb 無列 → 潛伏斷付
    // -------------------------------------------------------------------------

    public function test_classify_gaps_flags_active_acf_with_active_group_bank_as_latent_break(): void
    {
        $currencyId = $this->insertCurrency();

        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB_CODE'],
        ], status: 1, agentGroupId: 7);
        $this->makeBank($currencyId, 'CIMB', agentGroupId: 7);

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555', '--classify-gaps' => true])
            // 同一行輸出只會被第一個匹配的 expectsOutputToContain 吃掉（PendingCommand 子字串互搶），
            // 故 risk 鎖彙總行、cfb_state 鎖表格行，兩者不同行
            ->expectsOutputToContain('潛伏斷付 1 筆、死 entry 0 筆')
            ->expectsOutputToContain('無列')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 8: --classify-gaps：ACF 已停用 → 即使群組有活列也只是死 entry
    // -------------------------------------------------------------------------

    public function test_classify_gaps_marks_inactive_acf_as_dead_entry(): void
    {
        $currencyId = $this->insertCurrency();

        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB_CODE'],
        ], status: 0, agentGroupId: 7);
        $this->makeBank($currencyId, 'CIMB', agentGroupId: 7);

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555', '--classify-gaps' => true])
            ->expectsOutputToContain('潛伏斷付 0 筆、死 entry 1 筆')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 9: --classify-gaps：活列在別的群組、自己群組的列已停用 → 死 entry；cfb 有停用列 → 已停用
    // -------------------------------------------------------------------------

    public function test_classify_gaps_ignores_other_group_bank_and_reports_inactive_cfb(): void
    {
        $currencyId = $this->insertCurrency();

        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB_CODE'],
        ], status: 1, agentGroupId: 7);
        // 自己群組的列已停用；別群組（12）的活列不算
        $this->makeBank($currencyId, 'CIMB', agentGroupId: 7, status: 0);
        $this->makeBank($currencyId, 'CIMB', agentGroupId: 12);
        // cfb 有列但已停用（gate 2 仍成立：status=1 查無）
        $this->makeCashFlowBank(99555, $currencyId, 'CIMB', 'CIMB_CODE', status: 0);

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555', '--classify-gaps' => true])
            ->expectsOutputToContain('潛伏斷付 0 筆、死 entry 1 筆')
            ->expectsOutputToContain('已停用')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 10: 不帶 --classify-gaps → 輸出不含分類表（既有行為不變）
    // -------------------------------------------------------------------------

    public function test_without_classify_flag_prints_no_classification(): void
    {
        $currencyId = $this->insertCurrency();

        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB_CODE'],
        ], status: 1, agentGroupId: 7);
        $this->makeBank($currencyId, 'CIMB', agentGroupId: 7);

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->doesntExpectOutputToContain('gate 2 分類')
            ->doesntExpectOutputToContain('潛伏斷付')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 11: --classify-gaps：群組層的列已軟刪但仍 status=1 → 仍算活列
    //          （對齊會員端 WithdrawalController::getBanks 只篩群組＋status=1、不看 deleted_at）
    // -------------------------------------------------------------------------

    public function test_classify_gaps_counts_soft_deleted_but_active_bank_as_active(): void
    {
        $currencyId = $this->insertCurrency();

        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB_CODE'],
        ], status: 1, agentGroupId: 7);
        $this->makeBank($currencyId, 'CIMB', agentGroupId: 7, deletedAt: '2026-01-01 00:00:00');

        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555', '--classify-gaps' => true])
            ->expectsOutputToContain('潛伏斷付 1 筆、死 entry 0 筆')
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Test 12: 筆數依 ACF 是否啟用分開計數；停用的 ACF 不排除、照樣比對
    // -------------------------------------------------------------------------

    public function test_summary_counts_active_and_inactive_acf_separately(): void
    {
        $currencyId = $this->insertCurrency();

        // 啟用中的 ACF：兩筆項目，MAYBANK 一致、CIMB 不一致（兩側都非空 → 不觸發 gate 2）
        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB'],
            ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB_JSON'],
        ], status: 1);
        // 停用的 ACF：一筆項目，RHB 不一致
        $this->makeAgentCashFlow($currencyId, [
            ['BankCode_ELG' => 'RHB', 'BankCode_Third' => 'RHB_JSON'],
        ], status: 0);

        // bank_id 各給不同值：測試庫的 cash_flow_banks 有 (cash_flow_id, currency_id, bank_id) 唯一鍵
        $this->makeCashFlowBank(99555, $currencyId, 'MAYBANK', 'MBB', bankId: 1);
        $this->makeCashFlowBank(99555, $currencyId, 'CIMB', 'CIMB_TABLE', bankId: 2);
        $this->makeCashFlowBank(99555, $currencyId, 'RHB', 'RHB_TABLE', bankId: 3);

        // 兩組數字各印一行，才能在同一個測試裡分別斷言（同一行輸出只會被第一個匹配吃掉）
        $this->artisan('cash-flow:verify-payout-bank-parity', ['--cash-flow-id' => '99555'])
            ->expectsOutputToContain('啟用中的通道：2 筆，1 筆不一致')
            ->expectsOutputToContain('停用的通道：1 筆，1 筆不一致')
            ->assertExitCode(0);
    }
}
