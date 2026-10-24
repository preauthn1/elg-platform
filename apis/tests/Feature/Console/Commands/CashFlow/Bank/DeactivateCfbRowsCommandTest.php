<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `bank:deactivate-cfb-rows`＝停用「上游 banks 列已停用、自己卻還 active」的孤兒 cfb 列。
 *
 * 這是四層鏈「上游停用、下游不連動」通病在 `cash_flow_banks` 這一層的表現：
 * `banks` 沒有 FK cascade 也沒有 Observer 連動，收斂或下架一列銀行之後，
 * 掛在它底下的 cfb 列會留著 `status=1`。代付查表以 `(cash_flow_id, currency_id, bank_code)`
 * 為鍵、不 join banks，所以這些列仍查得到。
 *
 * 場景取自 Alfawin77 base 層收斂的尾巴：`bank:rename-bank` 遇到撞唯一鍵時會把 cfb 列
 * 標為「留置轉人工」，收斂完成後那些列就成了孤兒。
 */
class DeactivateCfbRowsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const CASH_FLOW_ID = 99850;

    private const AGENT_GROUP = 99851;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS7', 'name' => 'Test Currency 7', 'rate' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Orphan Cfb Cleanup', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(string $code, int $status = 1, int $agentGroupId = self::AGENT_GROUP): Bank
    {
        return Bank::create([
            'agent_group_id' => $agentGroupId,
            'type' => 1,
            'currency_id' => $this->currencyId,
            'name' => '(TS7) - ' . $code,
            'code' => $code,
            'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function seedCfb(Bank $bank, string $providerCode = '028', int $status = 1): CashFlowBank
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
     * 典型孤兒：banks 列已停用，cfb 列還 active。
     *
     * @return array{bank: Bank, cfb: CashFlowBank}
     */
    private function seedOrphan(): array
    {
        $bank = $this->seedBank('TS7DEAD', status: 0);

        return ['bank' => $bank, 'cfb' => $this->seedCfb($bank)];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandOptions(CashFlowBank $cfb, array $overrides = []): array
    {
        return array_merge([
            '--cfb' => [(string) $cfb->id],
            '--reason' => '單元測試停用孤兒列',
        ], $overrides);
    }

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        ['cfb' => $cfb] = $this->seedOrphan();

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb))
            ->assertExitCode(0);

        $this->assertSame(1, CashFlowBank::find($cfb->id)->status);
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'deactivate_orphan_cfb')->count());
    }

    public function test_deactivates_the_orphan_row(): void
    {
        ['cfb' => $cfb] = $this->seedOrphan();

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $this->assertSame(0, CashFlowBank::find($cfb->id)->status);
    }

    public function test_leaves_other_columns_untouched(): void
    {
        ['cfb' => $cfb] = $this->seedOrphan();

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $after = CashFlowBank::find($cfb->id);
        $this->assertSame('TS7DEAD', $after->bank_code);
        $this->assertSame('028', $after->provider_bank_code);
        $this->assertSame($cfb->bank_id, $after->bank_id);
    }

    public function test_writes_audit_log(): void
    {
        ['bank' => $bank, 'cfb' => $cfb] = $this->seedOrphan();

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $log = DB::table('bank_dedup_logs')->where('action', 'deactivate_orphan_cfb')->first();

        $this->assertNotNull($log);
        $this->assertSame('TS7DEAD', $log->code);
        // 單列停用沒有 survivor／loser 之分：自我參照表示「本列即標的」
        $this->assertSame($bank->id, (int) $log->survivor_bank_id);
        $this->assertSame($bank->id, (int) $log->loser_bank_id);
        $this->assertStringContainsString('單元測試停用孤兒列', (string) $log->detail);
    }

    public function test_requires_reason(): void
    {
        ['cfb' => $cfb] = $this->seedOrphan();

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, [
            '--reason' => '', '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame(1, CashFlowBank::find($cfb->id)->status);
    }

    public function test_rejects_missing_cfb_row(): void
    {
        ['cfb' => $cfb] = $this->seedOrphan();

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, [
            '--cfb' => ['99999999'], '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame(1, CashFlowBank::find($cfb->id)->status);
    }

    public function test_blocks_when_own_bank_row_is_still_active(): void
    {
        $bank = $this->seedBank('TS7LIVE');
        $cfb = $this->seedCfb($bank);

        // 上游還活著就不是孤兒：停用會讓會員選得到銀行卻查不到送單設定
        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame(1, CashFlowBank::find($cfb->id)->status);
    }

    public function test_blocks_when_the_code_is_active_in_another_agent_group(): void
    {
        $base = $this->seedBank('TS7DEAD', status: 0, agentGroupId: 0);
        $cfb = $this->seedCfb($base);
        // 同一個碼在別的代理群組仍服務中＝那個群組的會員綁得到，仍需要這筆送單設定
        $this->seedBank('TS7DEAD', status: 1, agentGroupId: self::AGENT_GROUP);

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame(1, CashFlowBank::find($cfb->id)->status);
    }

    public function test_blocks_when_in_flight_orders_carry_the_code(): void
    {
        ['bank' => $bank, 'cfb' => $cfb] = $this->seedOrphan();

        $this->seedWithdrawalOrder($bank, WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value);

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame(1, CashFlowBank::find($cfb->id)->status);
    }

    public function test_ignores_settled_orders(): void
    {
        ['bank' => $bank, 'cfb' => $cfb] = $this->seedOrphan();

        $this->seedWithdrawalOrder($bank, WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value);

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(0);

        $this->assertSame(0, CashFlowBank::find($cfb->id)->status);
    }

    public function test_skips_already_deactivated_row(): void
    {
        $bank = $this->seedBank('TS7DEAD', status: 0);
        $cfb = $this->seedCfb($bank, status: 0);

        // 冪等：已停用的視為無事可做，不算失敗也不重複寫審計
        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($cfb, [
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(0);

        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'deactivate_orphan_cfb')->count());
    }

    public function test_is_all_or_nothing_across_multiple_rows(): void
    {
        ['cfb' => $good] = $this->seedOrphan();

        $liveBank = $this->seedBank('TS7LIVE');
        $bad = $this->seedCfb($liveBank, providerCode: '111');

        $this->artisan('bank:deactivate-cfb-rows', $this->commandOptions($good, [
            '--cfb' => [(string) $good->id, (string) $bad->id],
            '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame(1, CashFlowBank::find($good->id)->status);
        $this->assertSame(1, CashFlowBank::find($bad->id)->status);
    }

    private function seedWithdrawalOrder(Bank $bank, int $status): void
    {
        DB::table('withdrawal_orders')->insert([
            'member_id' => 998501,
            'bank_id' => $bank->id,
            'bank_code' => $bank->code,
            'bank_name' => $bank->name,
            'account_name' => 'TEST HOLDER',
            'account_no' => '0000000003',
            'amount' => '100.0000',
            'status' => $status,
            'change_logs' => '[]',
            'third_party_order_no' => 'TESTWO9850',
        ]);
    }
}
