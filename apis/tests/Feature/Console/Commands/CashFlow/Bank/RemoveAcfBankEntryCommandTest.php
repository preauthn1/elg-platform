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
 * `bank:remove-acf-bank-entry`＝把 ACF `available_banks` 中某個死項目整個移除。
 *
 * 場景取自 Alfawin77 TopPay（cf94）的死結：要把服務中那個項目的三方碼改成正確值，
 * 但該值正被同一張 ACF 的死項目佔著；而既有的 `--prune-duplicate-entries` 要刪那個死項目
 * 又要求兩個項目的三方碼相同——兩道 gate 互為前提，誰都動不了。
 *
 * 這支只做一件事：移除一個「已經沒有服務中銀行列對應」的項目，把碼讓出來。
 * 它不改任何其他項目的值，也不負責判斷讓出來的碼要給誰。
 */
class RemoveAcfBankEntryCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const CASH_FLOW_ID = 99840;

    private const AGENT_GROUP = 99841;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS6', 'name' => 'Test Currency 6', 'rate' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Payout Entry Removal', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(string $code, string $name, int $status = 1): Bank
    {
        return Bank::create([
            'agent_group_id' => self::AGENT_GROUP,
            'type' => 1,
            'currency_id' => $this->currencyId,
            'name' => $name,
            'code' => $code,
            'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    /**
     * @param  list<array{BankCode_ELG: string, BankCode_Third: string}>  $entries
     */
    private function seedAcf(array $entries): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => $this->currencyId,
            'info' => ['available_banks' => $entries],
            'admin' => 0,
        ]);
    }

    /**
     * 一個典型場景：canonical 活著、死碼那列已停用，兩者三方碼不同。
     *
     * @return array{acf: AgentCashFlow, dead: Bank}
     */
    private function seedDeadEntryScenario(): array
    {
        $this->seedBank('TS6001', '(TS6) - OCBC NISP');
        $dead = $this->seedBank('TS6002', '(TS6) - OCBC Bank', status: 0);

        $acf = $this->seedAcf([
            ['BankCode_ELG' => 'TS6001', 'BankCode_Third' => '0280'],
            ['BankCode_ELG' => 'TS6002', 'BankCode_Third' => '028'],
            ['BankCode_ELG' => 'TS6003', 'BankCode_Third' => '014'],
        ]);

        return ['acf' => $acf, 'dead' => $dead];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandOptions(AgentCashFlow $acf, array $overrides = []): array
    {
        return array_merge([
            '--acf' => [(string) $acf->id],
            '--entry-third' => '028',
            '--from' => 'TS6002',
            '--reason' => '單元測試移除死項目',
        ], $overrides);
    }

    /**
     * @return list<array{BankCode_ELG: string, BankCode_Third: string}>
     */
    private function entriesOf(int $acfId): array
    {
        return AgentCashFlow::find($acfId)->info['available_banks'];
    }

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        ['acf' => $acf] = $this->seedDeadEntryScenario();

        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf))
            ->assertExitCode(0);

        $this->assertCount(3, $this->entriesOf($acf->id));
        $this->assertSame(0, DB::table('available_banks_align_logs')->where('action', 'manual_entry_remove')->count());
    }

    public function test_removes_the_targeted_entry(): void
    {
        ['acf' => $acf] = $this->seedDeadEntryScenario();

        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $codes = array_column($this->entriesOf($acf->id), 'BankCode_ELG');
        $this->assertSame(['TS6001', 'TS6003'], $codes);
    }

    public function test_keeps_other_entries_intact(): void
    {
        ['acf' => $acf] = $this->seedDeadEntryScenario();

        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        // 其餘項目的三方碼與相對順序都不得被動到——這支只負責移除，不負責改值
        $this->assertSame([
            ['BankCode_ELG' => 'TS6001', 'BankCode_Third' => '0280'],
            ['BankCode_ELG' => 'TS6003', 'BankCode_Third' => '014'],
        ], $this->entriesOf($acf->id));
    }

    public function test_writes_audit_log(): void
    {
        ['acf' => $acf] = $this->seedDeadEntryScenario();

        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $log = DB::table('available_banks_align_logs')->where('action', 'manual_entry_remove')->first();

        $this->assertNotNull($log);
        $this->assertSame($acf->id, (int) $log->agent_cash_flow_id);
        $this->assertSame('TS6002', $log->old_code);
        // 移除沒有承接碼，new_code 留空才不會被誤讀成「改指到某碼」
        $this->assertNull($log->new_code);
        $this->assertStringContainsString('028', (string) $log->detail);
        $this->assertStringContainsString('單元測試移除死項目', (string) $log->detail);
    }

    public function test_requires_reason(): void
    {
        ['acf' => $acf] = $this->seedDeadEntryScenario();

        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, [
            '--reason' => '', '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertCount(3, $this->entriesOf($acf->id));
    }

    public function test_rejects_when_entry_not_found(): void
    {
        ['acf' => $acf] = $this->seedDeadEntryScenario();

        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, [
            '--entry-third' => '999', '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertCount(3, $this->entriesOf($acf->id));
    }

    public function test_rejects_when_locator_matches_multiple_entries(): void
    {
        $this->seedBank('TS6002', '(TS6) - OCBC Bank', status: 0);

        $acf = $this->seedAcf([
            ['BankCode_ELG' => 'TS6002', 'BankCode_Third' => '028'],
            ['BankCode_ELG' => 'TS6002', 'BankCode_Third' => '028'],
        ]);

        // 兩鍵並用仍匹配到多個：刪哪一個都是猜，一律拒絕
        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertCount(2, $this->entriesOf($acf->id));
    }

    public function test_blocks_when_bank_row_is_still_active(): void
    {
        $this->seedBank('TS6001', '(TS6) - OCBC NISP');
        // 這次那列是服務中的：刪掉 entry 會讓會員選得到銀行卻轉不出碼
        $this->seedBank('TS6002', '(TS6) - OCBC Bank');

        $acf = $this->seedAcf([
            ['BankCode_ELG' => 'TS6001', 'BankCode_Third' => '0280'],
            ['BankCode_ELG' => 'TS6002', 'BankCode_Third' => '028'],
        ]);

        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertCount(2, $this->entriesOf($acf->id));
    }

    public function test_blocks_when_in_flight_orders_carry_the_code(): void
    {
        ['acf' => $acf, 'dead' => $dead] = $this->seedDeadEntryScenario();

        $this->seedWithdrawalOrder($dead, WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value);

        // 這些單以 bank_code 快照回查 entry，刪了會查不到碼而擋單
        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertCount(3, $this->entriesOf($acf->id));
    }

    public function test_ignores_settled_orders(): void
    {
        ['acf' => $acf, 'dead' => $dead] = $this->seedDeadEntryScenario();

        $this->seedWithdrawalOrder($dead, WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value);

        // 已結清的單不會再回查 entry，不構成攔阻
        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($acf, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(0);

        $this->assertCount(2, $this->entriesOf($acf->id));
    }

    public function test_is_all_or_nothing_across_multiple_acfs(): void
    {
        ['acf' => $good] = $this->seedDeadEntryScenario();

        // 第二張缺這個項目：整批應零寫入，第一張也不能被刪掉
        $bad = $this->seedAcf([
            ['BankCode_ELG' => 'TS6001', 'BankCode_Third' => '0280'],
        ]);

        $this->artisan('bank:remove-acf-bank-entry', $this->commandOptions($good, [
            '--acf' => [(string) $good->id, (string) $bad->id],
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertCount(3, $this->entriesOf($good->id));
        $this->assertCount(1, $this->entriesOf($bad->id));
    }

    private function seedWithdrawalOrder(Bank $bank, int $status): void
    {
        DB::table('withdrawal_orders')->insert([
            'member_id' => 998401,
            'bank_id' => $bank->id,
            'bank_code' => $bank->code,
            'bank_name' => $bank->name,
            'account_name' => 'TEST HOLDER',
            'account_no' => '0000000002',
            'amount' => '100.0000',
            'status' => $status,
            'change_logs' => '[]',
            'third_party_order_no' => 'TESTWO9840',
        ]);
    }
}
