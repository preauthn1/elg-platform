<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * `bank:reactivate-cfb-rows`＝把停用的 `cash_flow_banks` 列改回啟用，可選配改三方碼。
 *
 * 場景取自 TPower FPay 的 BCA：同步命令先把對照列整批停用、再靠名稱重建，重建配到別列，
 * 原本正確的那一列（銀行碼 TPID03）就一直停用，三方碼還停在換碼前的舊值。
 * 代付查表只取啟用中的列，所以開閘後 BCA 會查無碼而擋單；而既有命令只會新增列或停用列，
 * 沒有一支能把停用的列改回啟用。
 */
class ReactivateCfbRowsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const CASH_FLOW_ID = 99860;

    private const AGENT_GROUP = 99861;

    private const AUDIT_ACTION = 'reactivate_cfb';

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS8', 'name' => 'Test Currency 8', 'rate' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Reactivate Cfb', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(string $code, int $status = 1, int $agentGroupId = self::AGENT_GROUP): Bank
    {
        return Bank::create([
            'agent_group_id' => $agentGroupId,
            'type' => 1,
            'currency_id' => $this->currencyId,
            'name' => '(TS8) - ' . $code,
            'code' => $code,
            'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function seedCfb(Bank $bank, string $providerCode = '7', int $status = 0): CashFlowBank
    {
        return CashFlowBank::create([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'currency_id' => $this->currencyId,
            'bank_id' => $bank->id,
            'bank_code' => $bank->code,
            'provider_bank_code' => $providerCode,
            'min_amount' => 0, 'max_amount' => 0, 'status' => $status,
        ]);
    }

    /**
     * 啟用中的代付通道，清單含指定的銀行碼與三方碼。
     *
     * @param  list<array{0: string, 1: string}>  $entries  [銀行碼, 三方碼]
     */
    private function seedAcf(array $entries, int $status = 1): AgentCashFlow
    {
        return AgentCashFlow::create([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'cash_flow_type' => 3,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'name' => 'Test Reactivate Cfb ACF',
            'tax' => 0, 'tax_percentage' => 0,
            'info' => [
                'available_banks' => array_map(
                    static fn (array $e): array => ['BankCode_ELG' => $e[0], 'BankCode_Third' => $e[1]],
                    $entries
                ),
            ],
            'sorts' => 0, 'cost' => 0, 'status' => $status, 'backend_display' => 1, 'admin' => 0,
        ]);
    }

    /**
     * 典型場景：銀行列啟用、通道清單有這個碼、對照列卻停用且三方碼是舊值。
     *
     * @return array{bank: Bank, cfb: CashFlowBank}
     */
    private function seedBcaLikeCase(string $jsonThird = '13'): array
    {
        $bank = $this->seedBank('TS8BCA');
        $cfb = $this->seedCfb($bank, providerCode: '7', status: 0);
        $this->seedAcf([['TS8BCA', $jsonThird]]);

        return ['bank' => $bank, 'cfb' => $cfb];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandOptions(CashFlowBank $cfb, array $overrides = []): array
    {
        return array_merge([
            '--cfb' => [(string) $cfb->id],
            '--reason' => '單元測試重新啟用對照列',
        ], $overrides);
    }

    private function auditCount(): int
    {
        return DB::table('bank_dedup_logs')->where('action', self::AUDIT_ACTION)->count();
    }

    // ---- 預設預演、寫入、審計 ----

    #[Group('rules/payout-bank-code-mapping#R-11')]
    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        ['cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '7');

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb))
            ->assertExitCode(0);

        $after = CashFlowBank::find($cfb->id);
        $this->assertSame(0, $after->status);
        $this->assertNull($after->manual_override_at);
        $this->assertSame(0, $this->auditCount());
    }

    #[Group('rules/payout-bank-code-mapping#R-17')]
    public function test_reactivates_and_marks_manual_override(): void
    {
        ['cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '7');

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $after = CashFlowBank::find($cfb->id);
        $this->assertSame(1, $after->status);
        $this->assertSame('7', $after->provider_bank_code);
        $this->assertNotNull($after->manual_override_at);
        $this->assertStringContainsString('單元測試重新啟用對照列', (string) $after->manual_override_note);
    }

    #[Group('rules/payout-bank-code-mapping#R-17')]
    public function test_changes_provider_code_when_from_and_to_given(): void
    {
        ['cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '13');

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, [
            '--from-third' => '7', '--to-third' => '13', '--execute' => true, '--force' => true,
        ]))->assertExitCode(0);

        $after = CashFlowBank::find($cfb->id);
        $this->assertSame(1, $after->status);
        $this->assertSame('13', $after->provider_bank_code);
        $this->assertNotNull($after->manual_override_at);
    }

    #[Group('rules/payout-bank-code-mapping#R-17')]
    public function test_writes_audit_log_with_before_and_after(): void
    {
        ['bank' => $bank, 'cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '13');

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, [
            '--from-third' => '7', '--to-third' => '13', '--execute' => true, '--force' => true,
        ]))->assertExitCode(0);

        $log = DB::table('bank_dedup_logs')->where('action', self::AUDIT_ACTION)->first();
        $this->assertNotNull($log);
        $this->assertSame('TS8BCA', $log->code);
        $this->assertSame($bank->id, (int) $log->survivor_bank_id);

        $detail = json_decode((string) $log->detail, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($cfb->id, $detail['id']);
        $this->assertSame(['status' => 0, 'third' => '7'], $detail['was']);
        $this->assertSame(['status' => 1, 'third' => '13'], $detail['aft']);
        $this->assertSame('單元測試重新啟用對照列', $detail['reason']);
    }

    #[Group('rules/payout-bank-code-mapping#R-13')]
    #[Group('rules/payout-bank-code-mapping#R-18')]
    public function test_already_active_row_is_skipped_without_audit(): void
    {
        $bank = $this->seedBank('TS8LIVE');
        $cfb = $this->seedCfb($bank, providerCode: '7', status: 1);
        $this->seedAcf([['TS8LIVE', '7']]);

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->expectsOutputToContain('已是啟用狀態')
            ->assertExitCode(0);

        $this->assertNull(CashFlowBank::find($cfb->id)->manual_override_at);
        $this->assertSame(0, $this->auditCount());
    }

    // ---- 參數驗證 ----

    #[Group('rules/payout-bank-code-mapping#R-11')]
    public function test_reason_is_required(): void
    {
        ['cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '7');

        $this->artisan('bank:reactivate-cfb-rows', ['--cfb' => [(string) $cfb->id]])
            ->assertExitCode(1);
    }

    #[Group('rules/payout-bank-code-mapping#R-12')]
    public function test_to_third_requires_from_third(): void
    {
        ['cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '13');

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, ['--to-third' => '13']))
            ->expectsOutputToContain('--from-third')
            ->assertExitCode(1);
    }

    #[Group('rules/payout-bank-code-mapping#R-12')]
    public function test_from_third_must_match_current_value(): void
    {
        ['cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '13');

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, [
            '--from-third' => '8', '--to-third' => '13', '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame(0, CashFlowBank::find($cfb->id)->status);
        $this->assertSame(0, $this->auditCount());
    }

    // ---- 預檢關卡 ----

    #[Group('rules/payout-bank-code-mapping#R-14')]
    public function test_blocks_when_bound_bank_row_is_inactive(): void
    {
        $bank = $this->seedBank('TS8DEAD', status: 0);
        $cfb = $this->seedCfb($bank, providerCode: '7', status: 0);
        $this->seedAcf([['TS8DEAD', '7']]);

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->expectsOutputToContain('銀行列')
            ->assertExitCode(1);

        $this->assertSame(0, CashFlowBank::find($cfb->id)->status);
    }

    #[Group('rules/payout-bank-code-mapping#R-14')]
    public function test_blocks_when_bound_bank_row_is_soft_deleted(): void
    {
        $bank = $this->seedBank('TS8GONE');
        $cfb = $this->seedCfb($bank, providerCode: '7', status: 0);
        $this->seedAcf([['TS8GONE', '7']]);
        $bank->delete();

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(1);

        $this->assertSame(0, CashFlowBank::find($cfb->id)->status);
    }

    #[Group('rules/payout-bank-code-mapping#R-14')]
    public function test_blocks_when_another_active_row_already_serves_the_same_bank_code(): void
    {
        $bank = $this->seedBank('TS8DUP');
        $older = $this->seedCfb($bank, providerCode: '7', status: 0);
        $otherBank = $this->seedBank('TS8DUP', agentGroupId: self::AGENT_GROUP + 1);
        $this->seedCfb($otherBank, providerCode: '13', status: 1);
        $this->seedAcf([['TS8DUP', '13']]);

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($older, ['--execute' => true, '--force' => true]))
            ->expectsOutputToContain('已有啟用中的列')
            ->assertExitCode(1);

        $this->assertSame(0, CashFlowBank::find($older->id)->status);
    }

    #[Group('rules/payout-bank-code-mapping#R-14')]
    public function test_blocks_when_resulting_third_code_is_shared_unless_allow_alias(): void
    {
        ['cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '13');
        $aliasBank = $this->seedBank('TS8ALIAS');
        $this->seedCfb($aliasBank, providerCode: '13', status: 1);

        $blocked = $this->commandOptions($cfb, [
            '--from-third' => '7', '--to-third' => '13', '--execute' => true, '--force' => true,
        ]);

        $this->artisan('bank:reactivate-cfb-rows', $blocked)
            ->expectsOutputToContain('--allow-alias')
            ->assertExitCode(1);

        $this->assertSame(0, CashFlowBank::find($cfb->id)->status);

        $this->artisan('bank:reactivate-cfb-rows', $blocked + ['--allow-alias' => true])
            ->assertExitCode(0);

        $this->assertSame(1, CashFlowBank::find($cfb->id)->status);
    }

    #[Group('rules/payout-bank-code-mapping#R-14')]
    public function test_blocks_when_active_acf_list_has_different_third_code(): void
    {
        // 清單說這家銀行的三方碼是 13，對照列重新啟用後卻會是 7：兩條路徑答案不同
        ['cfb' => $cfb] = $this->seedBcaLikeCase(jsonThird: '13');

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->expectsOutputToContain('通道清單')
            ->assertExitCode(1);

        $this->assertSame(0, CashFlowBank::find($cfb->id)->status);
    }

    #[Group('rules/payout-bank-code-mapping#R-15')]
    public function test_warns_but_proceeds_when_no_active_acf_list_contains_the_code(): void
    {
        $bank = $this->seedBank('TS8NOACF');
        $cfb = $this->seedCfb($bank, providerCode: '7', status: 0);
        $this->seedAcf([['TS8OTHER', '9']]);

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->expectsOutputToContain('沒有任何啟用中的通道清單')
            ->assertExitCode(0);

        $this->assertSame(1, CashFlowBank::find($cfb->id)->status);
    }

    #[Group('rules/payout-bank-code-mapping#R-15')]
    public function test_bank_code_match_is_byte_exact(): void
    {
        // 銀行表的 code 欄不分大小寫，但銀行碼是識別字：大小寫不同就是不同的碼
        $bank = $this->seedBank('TS8Case');
        $cfb = $this->seedCfb($bank, providerCode: '7', status: 0);
        $this->seedAcf([['TS8CASE', '13']]);

        $this->artisan('bank:reactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->expectsOutputToContain('沒有任何啟用中的通道清單')
            ->assertExitCode(0);
    }

    #[Group('rules/payout-bank-code-mapping#R-16')]
    public function test_whole_batch_is_rolled_back_when_one_row_is_blocked(): void
    {
        ['cfb' => $good] = $this->seedBcaLikeCase(jsonThird: '7');
        $deadBank = $this->seedBank('TS8DEAD2', status: 0);
        $bad = $this->seedCfb($deadBank, providerCode: '7', status: 0);

        $this->artisan('bank:reactivate-cfb-rows', [
            '--cfb' => [(string) $good->id, (string) $bad->id],
            '--reason' => '單元測試整批回滾',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(0, CashFlowBank::find($good->id)->status);
        $this->assertSame(0, CashFlowBank::find($bad->id)->status);
        $this->assertSame(0, $this->auditCount());
    }
}
