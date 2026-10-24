<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `bank:fix-cfb-provider-code`＝把 `cash_flow_banks` 某一列的 `provider_bank_code` 改成正確的三方碼。
 *
 * 場景取自 Alfawin77 TopPay（cf94）實例：`cash-flow:sync` 以銀行名稱模糊匹配重建對映時，
 * 把「OCBC Bank UUS」的碼配到了「Bank OCBC NISP」列上，以致該行送款指向伊斯蘭事業單位。
 * 既有命令都碰不到這個欄位——sync 是全量重建（且它正是錯配來源）、backfill 只補缺漏不覆寫既有值。
 *
 * 定位鍵是「cfb id ＋ 現行三方碼」兩者並用：只憑 id 無法防止「值已被別人改過」的重跑誤傷。
 */
class FixCfbProviderCodeCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const CASH_FLOW_ID = 99830;

    private const AGENT_GROUP = 99831;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS5', 'name' => 'Test Currency 5', 'rate' => 1, 'admin' => 0,
        ]);

        CashFlow::create([
            'id' => self::CASH_FLOW_ID, 'name' => 'Test Payout Provider Code Fix', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(string $code, string $name): Bank
    {
        return Bank::create([
            'agent_group_id' => self::AGENT_GROUP,
            'type' => 1,
            'currency_id' => $this->currencyId,
            'name' => $name,
            'code' => $code,
            'status' => 1,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function seedCfb(Bank $bank, string $providerCode, int $status = 1): CashFlowBank
    {
        return CashFlowBank::create([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'currency_id' => $this->currencyId,
            'bank_id' => $bank->id,
            'bank_code' => $bank->code,
            'provider_bank_code' => $providerCode,
            'min_amount' => 0,
            'max_amount' => 0,
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function commandOptions(CashFlowBank $cfb, array $overrides = []): array
    {
        return array_merge([
            '--cfb' => (string) $cfb->id,
            '--from' => $cfb->provider_bank_code,
            '--to' => '028',
            '--reason' => '單元測試修正',
        ], $overrides);
    }

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb))
            ->assertExitCode(0);

        $after = CashFlowBank::find($cfb->id);
        $this->assertSame('0280', $after->provider_bank_code);
        $this->assertNull($after->manual_override_at);
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('action', 'fix_cfb_provider_code')->count());
    }

    public function test_writes_manual_override_marker(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        // 沒有標記，下次 cash-flow:sync 比對到這一列時會把三方碼改回設定檔的值
        $after = CashFlowBank::find($cfb->id);
        $this->assertNotNull($after->manual_override_at);
        $this->assertSame('bank:fix-cfb-provider-code：單元測試修正', $after->manual_override_note);
    }

    public function test_overwrites_existing_marker_and_audits_previous_value(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');
        $cfb->update(['manual_override_at' => '2026-01-02 03:04:05', 'manual_override_note' => '先前的人工覆寫']);

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $after = CashFlowBank::find($cfb->id);
        $this->assertSame('bank:fix-cfb-provider-code：單元測試修正', $after->manual_override_note);
        $this->assertNotSame('2026-01-02 03:04:05', $after->manual_override_at?->toDateTimeString());

        // 舊標記被改寫，還原只能靠審計裡的舊值
        $detail = json_decode(
            (string) DB::table('bank_dedup_logs')->where('action', 'fix_cfb_provider_code')->value('detail'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $this->assertSame('2026-01-02 03:04:05', $detail['was']['manual_override_at']);
        $this->assertSame('先前的人工覆寫', $detail['was']['manual_override_note']);
        $this->assertSame('bank:fix-cfb-provider-code：單元測試修正', $detail['aft']['manual_override_note']);
    }

    public function test_truncates_marker_note_to_column_length(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, [
            '--reason' => str_repeat('長', 300), '--execute' => true, '--force' => true,
        ]))->assertExitCode(0);

        // 理由上限 2000 字、欄位只有 255：標記截斷，完整理由仍在審計
        $this->assertSame(255, mb_strlen((string) CashFlowBank::find($cfb->id)->manual_override_note));
        $this->assertStringContainsString(
            str_repeat('長', 300),
            (string) DB::table('bank_dedup_logs')->where('action', 'fix_cfb_provider_code')->value('detail')
        );
    }

    public function test_fixes_provider_bank_code(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $this->assertSame('028', CashFlowBank::find($cfb->id)->provider_bank_code);
    }

    public function test_leaves_other_columns_untouched(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $after = CashFlowBank::find($cfb->id);
        $this->assertSame($bank->code, $after->bank_code);
        $this->assertSame($bank->id, $after->bank_id);
        $this->assertSame(1, $after->status);
    }

    public function test_writes_audit_log(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $log = DB::table('bank_dedup_logs')->where('action', 'fix_cfb_provider_code')->first();

        $this->assertNotNull($log);
        $this->assertSame($bank->code, $log->code);
        $this->assertSame(self::AGENT_GROUP, (int) $log->agent_group_id);
        // 單列改值沒有 survivor／loser 之分，沿用 recode-bank 的自我參照慣例
        $this->assertSame($bank->id, (int) $log->survivor_bank_id);
        $this->assertSame($bank->id, (int) $log->loser_bank_id);
        $this->assertStringContainsString('0280', (string) $log->detail);
        $this->assertStringContainsString('028', (string) $log->detail);
        $this->assertStringContainsString('單元測試修正', (string) $log->detail);
    }

    public function test_rejects_identical_from_and_to(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '028');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(1);

        $this->assertSame('028', CashFlowBank::find($cfb->id)->provider_bank_code);
    }

    public function test_rejects_when_current_value_does_not_match_from(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        // 現值是 0280，卻宣稱 from=999：可能指錯列，或這列已被別人改過
        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, [
            '--from' => '999', '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame('0280', CashFlowBank::find($cfb->id)->provider_bank_code);
    }

    public function test_rejects_missing_cfb_row(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, [
            '--cfb' => '99999999', '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame('0280', CashFlowBank::find($cfb->id)->provider_bank_code);
    }

    public function test_requires_reason(): void
    {
        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, [
            '--reason' => '', '--execute' => true, '--force' => true,
        ]))->assertExitCode(1);

        $this->assertSame('0280', CashFlowBank::find($cfb->id)->provider_bank_code);
    }

    public function test_blocks_when_target_code_already_used_by_active_row(): void
    {
        $target = $this->seedBank('TS5002', '(TS5) - OCBC Bank');
        $this->seedCfb($target, '028');

        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        // 同渠道已有服務中的列在用 028：改下去會讓兩列指向同一個三方目標，須明示放行
        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(1);

        $this->assertSame('0280', CashFlowBank::find($cfb->id)->provider_bank_code);
    }

    public function test_allows_duplicate_with_explicit_flag(): void
    {
        $target = $this->seedBank('TS5002', '(TS5) - OCBC Bank');
        $this->seedCfb($target, '028');

        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, [
            '--allow-duplicate' => true, '--execute' => true, '--force' => true,
        ]))->assertExitCode(0);

        $this->assertSame('028', CashFlowBank::find($cfb->id)->provider_bank_code);
    }

    public function test_ignores_inactive_row_when_checking_duplicate(): void
    {
        $target = $this->seedBank('TS5002', '(TS5) - OCBC Bank');
        $this->seedCfb($target, '028', status: 0);

        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        // 佔用者已停用＝代付查表取不到它，不構成衝突
        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $this->assertSame('028', CashFlowBank::find($cfb->id)->provider_bank_code);
    }

    public function test_ignores_other_channel_when_checking_duplicate(): void
    {
        $otherCashFlowId = self::CASH_FLOW_ID + 1;
        CashFlow::create([
            'id' => $otherCashFlowId, 'name' => 'Other Channel', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);

        $target = $this->seedBank('TS5002', '(TS5) - OCBC Bank');
        CashFlowBank::create([
            'cash_flow_id' => $otherCashFlowId,
            'currency_id' => $this->currencyId,
            'bank_id' => $target->id,
            'bank_code' => $target->code,
            'provider_bank_code' => '028',
            'min_amount' => 0, 'max_amount' => 0, 'status' => 1,
        ]);

        $bank = $this->seedBank('TS5001', '(TS5) - OCBC NISP');
        $cfb = $this->seedCfb($bank, '0280');

        // 三方碼的唯一性只在同一個渠道內有意義，別的金流商用同一個字串不衝突
        $this->artisan('bank:fix-cfb-provider-code', $this->commandOptions($cfb, ['--execute' => true, '--force' => true]))
            ->assertExitCode(0);

        $this->assertSame('028', CashFlowBank::find($cfb->id)->provider_bank_code);
    }
}
