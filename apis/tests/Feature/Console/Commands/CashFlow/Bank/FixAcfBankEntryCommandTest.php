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
 * `bank:fix-acf-bank-entry`＝把 ACF `available_banks` 中某個配錯銀行碼的 entry 改指到正確的碼。
 *
 * 場景取自 Alfawin77 實例：同一個三方碼底下掛了別家銀行的 `BankCode_ELG`，
 * 以致該三方碼對應的銀行在此通道實質缺席。定位鍵是「現有 target ＋ 三方碼」兩者並用，
 * 因為同一個 target 碼可能重複出現（正是這類錯誤的表徵），只憑 target 無法指定要改哪一個。
 */
class FixAcfBankEntryCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const CASH_FLOW_ID = 99820;

    private const AGENT_GROUP = 99821;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS4', 'name' => 'Test Currency 4', 'rate' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Payout Entry Fix', 'info' => [],
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

    /** 預設情境：三方碼 118 底下掛了 Bank Nagari 的碼，應改指 ShopeePay。 */
    private function seedMisconfiguredCase(): AgentCashFlow
    {
        $this->seedBank('TS4116', '(TS4) - Bank Nagari');
        $this->seedBank('TS4004', '(TS4) - ShopeePay');

        return $this->seedAcf([
            ['BankCode_ELG' => 'TS4116', 'BankCode_Third' => '42'],
            ['BankCode_ELG' => 'TS4007', 'BankCode_Third' => '7'],
            ['BankCode_ELG' => 'TS4116', 'BankCode_Third' => '118'],
        ]);
    }

    /** @return list<array{BankCode_ELG: string, BankCode_Third: string}> */
    private function entriesOf(AgentCashFlow $acf): array
    {
        return $acf->fresh()->info['available_banks'];
    }

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        $acf = $this->seedMisconfiguredCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => 'ShopeePay 三方碼誤配 Bank Nagari',
        ])->assertExitCode(0);

        $this->assertSame('TS4116', $this->entriesOf($acf)[2]['BankCode_ELG'], '預設不得寫入');
        $this->assertSame(0, DB::table('available_banks_align_logs')->where('agent_cash_flow_id', $acf->id)->count());
    }

    public function test_fixes_only_the_matched_entry(): void
    {
        $acf = $this->seedMisconfiguredCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => 'ShopeePay 三方碼誤配 Bank Nagari',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $entries = $this->entriesOf($acf);

        $this->assertSame('TS4004', $entries[2]['BankCode_ELG'], '目標 entry 必須改碼');
        $this->assertSame('118', $entries[2]['BankCode_Third'], '三方碼不得被動到');
        $this->assertSame('TS4116', $entries[0]['BankCode_ELG'], '同碼但三方碼不同的另一項不得被波及');
        $this->assertSame('TS4007', $entries[1]['BankCode_ELG'], '無關項目不得被波及');
        $this->assertCount(3, $entries, '項目總數不得改變');
    }

    public function test_writes_audit_with_restorable_values(): void
    {
        $acf = $this->seedMisconfiguredCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => 'ShopeePay 三方碼誤配 Bank Nagari',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $log = DB::table('available_banks_align_logs')
            ->where('agent_cash_flow_id', $acf->id)
            ->first();

        $this->assertNotNull($log, '不可逆寫入必須留審計');
        $this->assertSame('manual_entry_fix', $log->action);
        $this->assertSame('TS4116', $log->old_code);
        $this->assertSame('TS4004', $log->new_code);
        $this->assertSame(self::CASH_FLOW_ID, (int) $log->cash_flow_id);
        $this->assertSame(self::AGENT_GROUP, (int) $log->agent_group_id);

        $detail = json_decode((string) $log->detail, true);
        $this->assertSame('118', $detail['third']);
        $this->assertSame('ShopeePay 三方碼誤配 Bank Nagari', $detail['reason']);
    }

    public function test_blocks_when_no_entry_matches(): void
    {
        $acf = $this->seedMisconfiguredCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '999',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => '定位不到的情境',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS4116', $this->entriesOf($acf)[2]['BankCode_ELG']);
    }

    public function test_blocks_when_multiple_entries_match(): void
    {
        $this->seedBank('TS4116', '(TS4) - Bank Nagari');
        $this->seedBank('TS4004', '(TS4) - ShopeePay');
        $acf = $this->seedAcf([
            ['BankCode_ELG' => 'TS4116', 'BankCode_Third' => '118'],
            ['BankCode_ELG' => 'TS4116', 'BankCode_Third' => '118'],
        ]);

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => '定位有歧義的情境',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS4116', $this->entriesOf($acf)[0]['BankCode_ELG'], '歧義時整批零寫入');
    }

    public function test_blocks_when_target_code_has_no_active_bank_row(): void
    {
        $this->seedBank('TS4116', '(TS4) - Bank Nagari');
        $this->seedBank('TS4004', '(TS4) - ShopeePay', status: 0);
        $acf = $this->seedAcf([
            ['BankCode_ELG' => 'TS4116', 'BankCode_Third' => '118'],
        ]);

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => '目標碼在該群組沒有服務中的列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS4116', $this->entriesOf($acf)[0]['BankCode_ELG']);
    }

    public function test_blocks_when_target_code_already_present_in_same_acf(): void
    {
        $this->seedBank('TS4116', '(TS4) - Bank Nagari');
        $this->seedBank('TS4004', '(TS4) - ShopeePay');
        $acf = $this->seedAcf([
            ['BankCode_ELG' => 'TS4004', 'BankCode_Third' => '4'],
            ['BankCode_ELG' => 'TS4116', 'BankCode_Third' => '118'],
        ]);

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => '改了會製造同碼重複',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS4116', $this->entriesOf($acf)[1]['BankCode_ELG'], '不得製造新的同碼重複');
    }

    public function test_one_blocked_acf_aborts_the_whole_batch(): void
    {
        $good = $this->seedMisconfiguredCase();
        $bad = $this->seedAcf([
            ['BankCode_ELG' => 'TS4116', 'BankCode_Third' => '42'],
        ]);

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$good->id, $bad->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => '一好一壞',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS4116', $this->entriesOf($good)[2]['BankCode_ELG'], '任一 ACF 不合格即整批零寫入');
        $this->assertSame(0, DB::table('available_banks_align_logs')->where('agent_cash_flow_id', $good->id)->count());
    }

    public function test_rejects_identical_from_and_to(): void
    {
        $acf = $this->seedMisconfiguredCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4116',
            '--reason' => '改成一樣的碼沒有意義',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);
    }

    public function test_requires_reason(): void
    {
        $acf = $this->seedMisconfiguredCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
        ])->assertExitCode(1);
    }

    public function test_rejects_non_numeric_acf_id(): void
    {
        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => ['57,58'],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => '逗號分隔不受支援',
        ])->assertExitCode(1);
    }

    // ── --to-third：改三方碼（我方銀行對、三方碼抄錯）─────────────────────────
    //
    // 與 --to 相反的錯誤形態：target 碼指的銀行是對的，但掛在它底下的 BankCode_Third
    // 其實是另一家銀行的碼。實例（TPower／Yes2Win，YeePay 通道）：Public Bank Vietnam
    // Limited 送 970412＝PVcomBank、Nam A 送 970434＝Indovina Bank。判準是「該銀行在
    // 該通道只有這一個項目、正確碼完全不存在」⇒ 意圖是原銀行、碼抄錯，該改的是三方碼。

    /** 預設情境：Nam A 的項目掛了 Indovina 的三方碼 970434，應改為 970428。 */
    private function seedWrongThirdCodeCase(): AgentCashFlow
    {
        $this->seedBank('TS4066', '(TS4) - Nam A Commercial Joint Stock Bank');

        return $this->seedAcf([
            ['BankCode_ELG' => 'TS4007', 'BankCode_Third' => '970407'],
            ['BankCode_ELG' => 'TS4066', 'BankCode_Third' => '970434'],
        ]);
    }

    public function test_to_third_defaults_to_preview_and_writes_nothing(): void
    {
        $acf = $this->seedWrongThirdCodeCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '970434',
            '--from' => 'TS4066',
            '--to-third' => '970428',
            '--reason' => 'Nam A 誤掛 Indovina 的 BIN',
        ])->assertExitCode(0);

        $this->assertSame('970434', $this->entriesOf($acf)[1]['BankCode_Third'], '預設不得寫入');
        $this->assertSame(0, DB::table('available_banks_align_logs')->where('agent_cash_flow_id', $acf->id)->count());
    }

    public function test_to_third_fixes_only_the_third_party_code(): void
    {
        $acf = $this->seedWrongThirdCodeCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '970434',
            '--from' => 'TS4066',
            '--to-third' => '970428',
            '--reason' => 'Nam A 誤掛 Indovina 的 BIN',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $entries = $this->entriesOf($acf);

        $this->assertSame('970428', $entries[1]['BankCode_Third'], '目標項目必須改三方碼');
        $this->assertSame('TS4066', $entries[1]['BankCode_ELG'], 'target 碼不得被動到');
        $this->assertSame('970407', $entries[0]['BankCode_Third'], '無關項目不得被波及');
        $this->assertCount(2, $entries, '項目總數不得改變');
    }

    /**
     * action 是判讀 old_code／new_code 指哪個欄位的唯一開關：欄位註解寫的是
     * BankCode_ELG，三方碼修正必須用不同的 action 標記，否則日後還原會回錯欄位。
     */
    public function test_to_third_writes_audit_under_distinct_action(): void
    {
        $acf = $this->seedWrongThirdCodeCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '970434',
            '--from' => 'TS4066',
            '--to-third' => '970428',
            '--reason' => 'Nam A 誤掛 Indovina 的 BIN',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $log = DB::table('available_banks_align_logs')
            ->where('agent_cash_flow_id', $acf->id)
            ->first();

        $this->assertNotNull($log, '不可逆寫入必須留審計');
        $this->assertSame('manual_entry_third_fix', $log->action);
        $this->assertSame('970434', $log->old_code, 'old_code 記舊三方碼');
        $this->assertSame('970428', $log->new_code, 'new_code 記新三方碼');

        $detail = json_decode((string) $log->detail, true);
        $this->assertSame('BankCode_Third', $detail['field'], '欄位語意必須落在審計裡');
        $this->assertSame('TS4066', $detail['target'], '未變動的 target 碼要留著供定位');
        $this->assertSame('Nam A 誤掛 Indovina 的 BIN', $detail['reason']);
    }

    public function test_rejects_both_to_and_to_third(): void
    {
        $acf = $this->seedWrongThirdCodeCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '970434',
            '--from' => 'TS4066',
            '--to' => 'TS4007',
            '--to-third' => '970428',
            '--reason' => '兩種修法的判準不同，不可同時套用',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $entries = $this->entriesOf($acf);
        $this->assertSame('970434', $entries[1]['BankCode_Third']);
        $this->assertSame('TS4066', $entries[1]['BankCode_ELG']);
    }

    public function test_requires_one_of_to_or_to_third(): void
    {
        $acf = $this->seedWrongThirdCodeCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '970434',
            '--from' => 'TS4066',
            '--reason' => '沒有指定要改成什麼',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);
    }

    public function test_to_third_rejects_identical_third_codes(): void
    {
        $acf = $this->seedWrongThirdCodeCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '970434',
            '--from' => 'TS4066',
            '--to-third' => '970434',
            '--reason' => '改成一樣的碼沒有意義',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);
    }

    public function test_to_third_blocks_when_target_third_already_present_in_same_acf(): void
    {
        $this->seedBank('TS4066', '(TS4) - Nam A Commercial Joint Stock Bank');
        $acf = $this->seedAcf([
            ['BankCode_ELG' => 'TS4028', 'BankCode_Third' => '970428'],
            ['BankCode_ELG' => 'TS4066', 'BankCode_Third' => '970434'],
        ]);

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '970434',
            '--from' => 'TS4066',
            '--to-third' => '970428',
            '--reason' => '改了會製造同三方碼重複',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('970434', $this->entriesOf($acf)[1]['BankCode_Third'], '不得製造新的同三方碼重複');
    }

    /**
     * 改三方碼時 target 碼不動，所以要驗的是「這個項目本身指向的列還在服務中」——
     * 停用的列改了三方碼，會員一樣選不到，等於白改。
     */
    public function test_to_third_blocks_when_elg_code_has_no_active_bank_row(): void
    {
        $this->seedBank('TS4066', '(TS4) - Nam A Commercial Joint Stock Bank', status: 0);
        $acf = $this->seedAcf([
            ['BankCode_ELG' => 'TS4066', 'BankCode_Third' => '970434'],
        ]);

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '970434',
            '--from' => 'TS4066',
            '--to-third' => '970428',
            '--reason' => '該項目的 target 碼已無服務中的列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('970434', $this->entriesOf($acf)[0]['BankCode_Third']);
    }

    /**
     * 寫入目標由容器的 -e DB_CONNECTION 決定、不在命令參數裡，各白標的 ACF id 區間又
     * 重疊，連錯站時預檢一樣可能全綠。故連線與庫名必須在預演階段就印出來。
     */
    public function test_preview_discloses_write_target_database(): void
    {
        $acf = $this->seedMisconfiguredCase();

        $this->artisan('bank:fix-acf-bank-entry', [
            '--acf' => [$acf->id],
            '--entry-third' => '118',
            '--from' => 'TS4116',
            '--to' => 'TS4004',
            '--reason' => 'ShopeePay 三方碼誤配 Bank Nagari',
        ])
            // 兩個斷言不可共存於同一行輸出：PendingCommand 逐行消耗，
            // 同一行同時滿足兩個期待時只會消掉第一個，第二個必然落空。
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(0);
    }
}
