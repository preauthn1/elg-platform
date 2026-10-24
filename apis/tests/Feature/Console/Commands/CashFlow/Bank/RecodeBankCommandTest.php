<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\Bank;
use App\Models\CashFlow;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `bank:recode-bank`＝把指定 banks 列的 `code` 改成另一個碼（單列改碼，非兩列收斂）。
 *
 * 場景取自 Alfawin77 實例：重複清理批次在 base 層與代理群組層挑了相反的存活碼，
 * 使得同一家銀行在兩層碼不同——base 只剩一列，沒有第二列可供 `bank:rename-bank`
 * 那種「同行不同碼收斂」使用，故需要單列改碼。
 */
class RecodeBankCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const AGENT_GROUP = 99830;

    private const CASH_FLOW_ID = 99831;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS5', 'name' => 'Test Currency 5', 'rate' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(string $code, int $status = 1, int $type = 1, int $agentGroup = self::AGENT_GROUP): Bank
    {
        return Bank::create([
            'agent_group_id' => $agentGroup,
            'type' => $type,
            'currency_id' => $this->currencyId,
            'name' => '(TS5) - Bank ' . $code,
            'code' => $code,
            'status' => $status,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function seedCashFlowBank(int $bankId, string $bankCode): int
    {
        CashFlow::firstOrCreate(['id' => self::CASH_FLOW_ID], [
            'name' => 'Test Payout Recode', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ]);

        return (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => self::CASH_FLOW_ID,
            'currency_id' => $this->currencyId,
            'bank_id' => $bankId,
            'bank_code' => $bankCode,
            'provider_bank_code' => 'PROV1',
            'status' => 1,
        ]);
    }

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        $bank = $this->seedBank('TS5OLD1');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5NEW1'],
            '--reason' => 'base 層與代理群組層存活碼不同步',
        ])->assertExitCode(0);

        $this->assertSame('TS5OLD1', (string) DB::table('banks')->where('id', $bank->id)->value('code'));
        $this->assertSame(0, DB::table('bank_dedup_logs')->where('loser_bank_id', $bank->id)->count());
    }

    public function test_recodes_and_writes_audit(): void
    {
        $bank = $this->seedBank('TS5OLD2');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5NEW2'],
            '--reason' => 'base 層與代理群組層存活碼不同步',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('TS5NEW2', (string) DB::table('banks')->where('id', $bank->id)->value('code'));

        $log = DB::table('bank_dedup_logs')
            ->where('action', 'recode_bank')
            ->where('loser_bank_id', $bank->id)
            ->first();

        $this->assertNotNull($log, '不可逆寫入必須留審計');
        $this->assertSame('TS5OLD2', $log->code, '審計的 code 欄記原碼，供反查');

        $detail = json_decode((string) $log->detail, true);
        $this->assertSame('TS5OLD2', $detail['was']['code']);
        $this->assertSame('TS5NEW2', $detail['aft']['code']);
        $this->assertSame('base 層與代理群組層存活碼不同步', $detail['reason']);
    }

    public function test_blocks_when_new_code_collides_with_active_row(): void
    {
        $this->seedBank('TS5TAKEN');
        $bank = $this->seedBank('TS5OLD3');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5TAKEN'],
            '--reason' => '會撞 active 唯一鍵',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('TS5OLD3', (string) DB::table('banks')->where('id', $bank->id)->value('code'));
    }

    /**
     * active 唯一鍵只約束 status=1 的列（generated column 對停用列產生 NULL），
     * 故目標碼若只存在於停用列，改碼不會撞鍵——不該誤擋。
     */
    public function test_allows_when_colliding_row_is_inactive(): void
    {
        $this->seedBank('TS5FREE', status: 0);
        $bank = $this->seedBank('TS5OLD4');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5FREE'],
            '--reason' => '目標碼只存在於停用列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('TS5FREE', (string) DB::table('banks')->where('id', $bank->id)->value('code'));
    }

    /** 唯一鍵含 agent_group_id，別的群組用同一個碼不構成衝突。 */
    public function test_allows_when_colliding_row_is_in_another_agent_group(): void
    {
        $this->seedBank('TS5OTHER', agentGroup: self::AGENT_GROUP + 1);
        $bank = $this->seedBank('TS5OLD5');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5OTHER'],
            '--reason' => '別的代理群組用同碼不衝突',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame('TS5OTHER', (string) DB::table('banks')->where('id', $bank->id)->value('code'));
    }

    /**
     * 代付查表以 bank_code 為鍵，banks 改碼後 cash_flow_banks 不跟上就查不到；
     * 引用本列且碼逐字等於原碼的列要同一交易內改成新碼，並把 id 記進審計供還原。
     */
    public function test_rewrites_cash_flow_bank_code_that_equals_old_code(): void
    {
        $bank = $this->seedBank('TS5OLD6');
        $cfbId = $this->seedCashFlowBank($bank->id, 'TS5OLD6');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5NEW6'],
            '--reason' => 'cfb 連帶改寫',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('連帶改寫 1 筆 cash_flow_banks')
            ->assertExitCode(0);

        $this->assertSame('TS5NEW6', (string) DB::table('cash_flow_banks')->where('id', $cfbId)->value('bank_code'));

        $detail = json_decode((string) DB::table('bank_dedup_logs')
            ->where('action', 'recode_bank')
            ->where('loser_bank_id', $bank->id)
            ->value('detail'), true);
        $this->assertSame([$cfbId], $detail['cfb_synced'], '審計要記被連帶改寫的 cfb id，還原時才知道哪些列要改回去');
        $this->assertSame([], $detail['cfb_kept']);
    }

    /**
     * bank_code 已經不是原碼的列是別的工具刻意寫的（例如補的群組層碼），不能順手蓋掉；
     * 大小寫不同的碼也算不同（欄位 collation 不分大小寫，比對必須逐字）。
     */
    public function test_keeps_cash_flow_bank_rows_whose_code_already_differs(): void
    {
        $bank = $this->seedBank('TS5OLD7');
        $driftedId = $this->seedCashFlowBank($bank->id, 'TS5GROUP7');
        // 唯一鍵是 (cash_flow_id, currency_id, bank_id)，第二列要掛在另一個代付通道上
        $otherCashFlowId = (int) CashFlow::create([
            'name' => 'Test Payout Recode 2', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ])->id;
        $caseVariantId = (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => $otherCashFlowId,
            'currency_id' => $this->currencyId,
            'bank_id' => $bank->id,
            'bank_code' => 'ts5old7',
            'provider_bank_code' => 'PROV2',
            'status' => 1,
            'info' => 'case-variant',
        ]);

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5NEW7'],
            '--reason' => 'cfb 保留判定',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('保留不動')
            ->assertExitCode(0);

        $this->assertSame('TS5NEW7', (string) DB::table('banks')->where('id', $bank->id)->value('code'));
        $this->assertSame('TS5GROUP7', (string) DB::table('cash_flow_banks')->where('id', $driftedId)->value('bank_code'));
        $this->assertSame('ts5old7', (string) DB::table('cash_flow_banks')->where('id', $caseVariantId)->value('bank_code'), '大小寫不同的碼不是原碼，不得改寫');

        $detail = json_decode((string) DB::table('bank_dedup_logs')
            ->where('action', 'recode_bank')
            ->where('loser_bank_id', $bank->id)
            ->value('detail'), true);
        $this->assertSame([], $detail['cfb_synced']);
        $this->assertEqualsCanonicalizing([$driftedId, $caseVariantId], $detail['cfb_kept']);
    }

    public function test_preview_does_not_touch_cash_flow_banks(): void
    {
        $bank = $this->seedBank('TS5OLD8');
        $cfbId = $this->seedCashFlowBank($bank->id, 'TS5OLD8');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5NEW8'],
            '--reason' => '預演',
        ])
            ->expectsOutputToContain('連帶改寫 1 筆 cash_flow_banks')
            ->assertExitCode(0);

        $this->assertSame('TS5OLD8', (string) DB::table('cash_flow_banks')->where('id', $cfbId)->value('bank_code'));
    }

    public function test_blocks_when_bank_not_found(): void
    {
        $this->artisan('bank:recode-bank', [
            '--pair' => ['99999999:TS5NEW7'],
            '--reason' => '不存在的列',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);
    }

    public function test_blocks_when_new_code_equals_current(): void
    {
        $bank = $this->seedBank('TS5SAME');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5SAME'],
            '--reason' => '改成一樣的碼沒有意義',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);
    }

    public function test_rejects_malformed_pair(): void
    {
        $this->artisan('bank:recode-bank', [
            '--pair' => ['not-a-pair'],
            '--reason' => '格式錯誤',
        ])->assertExitCode(1);
    }

    public function test_one_blocked_pair_aborts_the_whole_batch(): void
    {
        $good = $this->seedBank('TS5OLD8');
        $this->seedBank('TS5TAKEN2');
        $bad = $this->seedBank('TS5OLD9');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$good->id . ':TS5NEW8', $bad->id . ':TS5TAKEN2'],
            '--reason' => '一好一壞',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(
            'TS5OLD8',
            (string) DB::table('banks')->where('id', $good->id)->value('code'),
            '任一配對不合格即整批零寫入'
        );
    }

    public function test_requires_reason(): void
    {
        $bank = $this->seedBank('TS5OLDA');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5NEWA'],
        ])->assertExitCode(1);
    }

    public function test_preview_discloses_write_target_database(): void
    {
        $bank = $this->seedBank('TS5OLDB');

        $this->artisan('bank:recode-bank', [
            '--pair' => [$bank->id . ':TS5NEWB'],
            '--reason' => 'base 層與代理群組層存活碼不同步',
        ])
            // 兩個斷言不可共存於同一行輸出：PendingCommand 逐行消耗，
            // 同一行同時滿足兩個期待時只會消掉第一個，第二個必然落空。
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(0);
    }
}
