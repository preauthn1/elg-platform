<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `bank:add-acf-bank-entry`＝在代付 ACF 的 `available_banks` 追加一個缺少的項目。
 *
 * 場景取自 VWorld 代理群組 70：`ICBKSG` 有服務中的銀行列與會員綁定，MaxPay 官方清單也支援，
 * 但該通道的 ACF 沒有對應項目 ⇒ 查碼回空字串、訂單卡在「不支援銀行」。
 * 預檢的四道（代付渠道、銀行列服務中、銀行碼未重複、三方碼未重複）任一不過即整批零寫入。
 */
class AddAcfBankEntryCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const CASH_FLOW_ID = 99840;

    private const DEPOSIT_CASH_FLOW_ID = 99841;

    private const AGENT_GROUP = 99842;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS6', 'name' => 'Test Currency 6', 'rate' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Payout Entry Add', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::DEPOSIT_CASH_FLOW_ID, 'name' => 'Test Deposit Entry Add', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 1, 'status' => 1, 'admin' => 0,
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
    private function seedAcf(array $entries, int $cashFlowId = self::CASH_FLOW_ID): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlowId,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => $this->currencyId,
            'info' => ['available_banks' => $entries],
            'admin' => 0,
        ]);
    }

    /** 預設情境：TS6ICBC 的銀行列服務中，但通道少了這個項目。 */
    private function seedMissingEntryCase(): AgentCashFlow
    {
        $this->seedBank('TS6ICBC', '(TS6) - ICBC Bank');
        $this->seedBank('TS6DBS', '(TS6) - DBS Bank');

        return $this->seedAcf([
            ['BankCode_ELG' => 'TS6DBS', 'BankCode_Third' => 'DBS'],
        ]);
    }

    /** @return list<array{BankCode_ELG: string, BankCode_Third: string}> */
    private function entriesOf(AgentCashFlow $acf): array
    {
        return $acf->fresh()->info['available_banks'];
    }

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        $acf = $this->seedMissingEntryCase();

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '官方清單支援 ICBC，通道缺項',
        ])->assertExitCode(0);

        $this->assertCount(1, $this->entriesOf($acf), '預設不得寫入');
        $this->assertSame(0, DB::table('available_banks_align_logs')->where('agent_cash_flow_id', $acf->id)->count());
    }

    public function test_appends_entry_at_the_end_without_touching_existing_ones(): void
    {
        $acf = $this->seedMissingEntryCase();

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '官方清單支援 ICBC，通道缺項',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $entries = $this->entriesOf($acf);

        $this->assertCount(2, $entries);
        $this->assertSame(['BankCode_ELG' => 'TS6DBS', 'BankCode_Third' => 'DBS'], $entries[0], '既有項目與其索引不得變動');
        $this->assertSame(['BankCode_ELG' => 'TS6ICBC', 'BankCode_Third' => 'ICBC'], $entries[1], '新項目追加在最後');
    }

    public function test_writes_audit_with_restorable_values(): void
    {
        $acf = $this->seedMissingEntryCase();

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '依 PayLoh v1.9 官方清單追加',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $log = DB::table('available_banks_align_logs')->where('agent_cash_flow_id', $acf->id)->first();

        $this->assertNotNull($log);
        $this->assertSame('manual_entry_add', $log->action);
        $this->assertSame('', $log->old_code, '追加沒有舊值');
        $this->assertSame('TS6ICBC', $log->new_code);

        $detail = json_decode((string) $log->detail, true);
        $this->assertSame('ICBC', $detail['third'], '三方碼必須落審計，否則無從還原');
        $this->assertSame(1, $detail['idx']);
        $this->assertSame('依 PayLoh v1.9 官方清單追加', $detail['reason']);
    }

    public function test_blocks_when_bank_row_is_not_active(): void
    {
        $this->seedBank('TS6ICBC', '(TS6) - ICBC Bank', status: 0);
        $acf = $this->seedAcf([['BankCode_ELG' => 'TS6DBS', 'BankCode_Third' => 'DBS']]);

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '銀行列已停用',
            '--execute' => true,
            '--force' => true,
        ])->expectsOutputToContain('沒有服務中的')->assertExitCode(1);

        $this->assertCount(1, $this->entriesOf($acf), '預檢未過不得寫入');
    }

    public function test_blocks_when_bank_code_already_present(): void
    {
        $this->seedBank('TS6ICBC', '(TS6) - ICBC Bank');
        $acf = $this->seedAcf([['BankCode_ELG' => 'TS6ICBC', 'BankCode_Third' => 'OLD']]);

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '應改用 fix',
            '--execute' => true,
            '--force' => true,
        ])->expectsOutputToContain('bank:fix-acf-bank-entry')->assertExitCode(1);

        $this->assertCount(1, $this->entriesOf($acf));
    }

    public function test_blocks_when_third_party_code_already_used_by_another_entry(): void
    {
        $this->seedBank('TS6ICBC', '(TS6) - ICBC Bank');
        $acf = $this->seedAcf([['BankCode_ELG' => 'TS6DBS', 'BankCode_Third' => 'ICBC']]);

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '三方碼已被佔用',
            '--execute' => true,
            '--force' => true,
        ])->expectsOutputToContain('實質缺席')->assertExitCode(1);

        $this->assertCount(1, $this->entriesOf($acf));
    }

    public function test_blocks_when_cash_flow_is_not_payout(): void
    {
        $this->seedBank('TS6ICBC', '(TS6) - ICBC Bank');
        $acf = $this->seedAcf([], self::DEPOSIT_CASH_FLOW_ID);

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '代收渠道不該有 available_banks',
            '--execute' => true,
            '--force' => true,
        ])->expectsOutputToContain('不是代付渠道')->assertExitCode(1);

        $this->assertCount(0, $this->entriesOf($acf));
    }

    public function test_one_blocked_acf_aborts_the_whole_batch(): void
    {
        $good = $this->seedMissingEntryCase();
        $bad = $this->seedAcf([['BankCode_ELG' => 'TS6ICBC', 'BankCode_Third' => 'OLD']]);

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$good->id, $bad->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '一筆不合格即整批零寫入',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertCount(1, $this->entriesOf($good), '同批中合格的那筆也不得寫入');
        $this->assertSame(0, DB::table('available_banks_align_logs')->where('agent_cash_flow_id', $good->id)->count());
    }

    public function test_requires_reason_and_both_codes(): void
    {
        $acf = $this->seedMissingEntryCase();

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
        ])->expectsOutputToContain('--reason 必填')->assertExitCode(1);

        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--target' => 'TS6ICBC',
            '--reason' => '缺三方碼',
        ])->expectsOutputToContain('皆為必填')->assertExitCode(1);
    }

    public function test_rejects_non_numeric_acf_id(): void
    {
        $this->artisan('bank:add-acf-bank-entry', [
            '--acf' => ['12,13'],
            '--target' => 'TS6ICBC',
            '--third' => 'ICBC',
            '--reason' => '逗號分隔不受支援',
        ])->expectsOutputToContain('純數字')->assertExitCode(1);
    }
}
