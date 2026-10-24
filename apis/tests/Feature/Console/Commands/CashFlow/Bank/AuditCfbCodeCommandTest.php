<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Enums\DriftSubjectEnum;
use App\Facades\BotNotify;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\DriftBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `bank:audit-cfb-code`＝每天比對代付對照表 `cash_flow_banks.bank_code` 與 `banks.code`，
 * 脫節清單與基準不同才告警並更新基準。
 *
 * 命令掃的是整張對照表，共用測試庫裡可能有別的測試留下的列，所以斷言一律用相對值
 * （本測試建立的列有沒有進清單、基準列數有沒有多一筆），不假設表是空的。
 */
class AuditCfbCodeCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const AGENT_GROUP = 99840;

    private int $currencyId;

    private int $cashFlowId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS6', 'name' => 'Test Currency 6', 'rate' => 1, 'admin' => 0,
        ]);
        $this->cashFlowId = (int) CashFlow::create([
            'name' => 'Test Payout Audit', 'info' => [],
            'rate' => 1, 'percentage' => 0, 'type' => 3, 'status' => 1, 'admin' => 0,
        ])->id;
    }

    private function seedBank(string $code): Bank
    {
        return Bank::create([
            'agent_group_id' => self::AGENT_GROUP,
            'type' => 1,
            'currency_id' => $this->currencyId,
            'name' => '(TS6) - Bank ' . $code,
            'code' => $code,
            'status' => 1,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => 0, 'max_amount' => 0, 'sort' => 0, 'admin' => 0,
        ]);
    }

    private function seedCfb(int $bankId, string $bankCode, int $status = 1): int
    {
        return (int) DB::table('cash_flow_banks')->insertGetId([
            'cash_flow_id' => $this->cashFlowId,
            'currency_id' => $this->currencyId,
            'bank_id' => $bankId,
            'bank_code' => $bankCode,
            'provider_bank_code' => 'PROV',
            'status' => $status,
        ]);
    }

    private function baselines(): Builder
    {
        return DriftBaseline::query()
            ->where('subject_key', DriftSubjectEnum::CFB_CODE_DRIFT->value)
            ->where('scope_key', 'all')
            ->orderByDesc('id');
    }

    /**
     * 把目前的脫節清單存成基準：先用 --notify 跑一次（沒有基準時只建基準、不告警）。
     */
    private function establishBaseline(): void
    {
        $this->artisan('bank:audit-cfb-code --notify')->assertSuccessful();
    }

    public function test_first_run_creates_baseline_without_alerting(): void
    {
        $bank = $this->seedBank('TS6A');
        $cfbId = $this->seedCfb($bank->id, 'TS6A-OLD');
        $before = $this->baselines()->count();
        BotNotify::spy();

        $this->artisan('bank:audit-cfb-code --notify')->assertSuccessful();

        $this->assertSame($before + 1, $this->baselines()->count(), '第一次跑應該建立一筆基準');
        BotNotify::shouldNotHaveReceived('send');
        $payload = (array) $this->baselines()->first()?->payload;
        $this->assertSame(['cfb' => 'TS6A-OLD', 'bank' => 'TS6A'], $payload[$cfbId]);
    }

    public function test_matching_rows_and_inactive_rows_are_not_drift(): void
    {
        $bank = $this->seedBank('TS6B');
        $okId = $this->seedCfb($bank->id, 'TS6B');
        $inactiveDriftId = $this->seedCfb($this->seedBank('TS6B2')->id, 'TS6B2-OLD', status: 0);

        $this->establishBaseline();

        $payload = (array) $this->baselines()->first()?->payload;
        $this->assertArrayNotHasKey($okId, $payload, '碼相同不是脫節');
        $this->assertArrayNotHasKey($inactiveDriftId, $payload, '停用的對照表列不在稽核範圍');
    }

    /**
     * 欄位 collation 不分大小寫，但銀行碼是識別碼，只差大小寫也算脫節。
     */
    public function test_case_variant_counts_as_drift(): void
    {
        $bank = $this->seedBank('TS6C');
        $cfbId = $this->seedCfb($bank->id, 'ts6c');

        $this->establishBaseline();

        $payload = (array) $this->baselines()->first()?->payload;
        $this->assertSame(['cfb' => 'ts6c', 'bank' => 'TS6C'], $payload[$cfbId]);
    }

    public function test_missing_bank_row_counts_as_drift(): void
    {
        $cfbId = $this->seedCfb(999999901, 'TS6D');

        $this->establishBaseline();

        $payload = (array) $this->baselines()->first()?->payload;
        $this->assertSame(['cfb' => 'TS6D', 'bank' => null], $payload[$cfbId]);
    }

    public function test_no_change_does_not_alert_and_writes_nothing(): void
    {
        $this->seedCfb($this->seedBank('TS6E')->id, 'TS6E-OLD');
        $this->establishBaseline();
        $count = $this->baselines()->count();
        BotNotify::spy();

        $this->artisan('bank:audit-cfb-code --notify')
            ->expectsOutputToContain('清單沒有變動')
            ->assertSuccessful();

        BotNotify::shouldNotHaveReceived('send');
        $this->assertSame($count, $this->baselines()->count(), '內容沒變就不該新增列，那一天的執行等同唯讀');
    }

    public function test_new_drift_alerts_and_advances_baseline(): void
    {
        $this->establishBaseline();
        $count = $this->baselines()->count();
        $bank = $this->seedBank('TS6F');
        $cfbId = $this->seedCfb($bank->id, 'TS6F-OLD');
        BotNotify::spy();

        $this->artisan('bank:audit-cfb-code --notify')
            ->expectsOutputToContain('新出現 1 筆')
            ->assertSuccessful();

        BotNotify::shouldHaveReceived('send')->once();
        $this->assertSame($count + 1, $this->baselines()->count(), '清單變了要存新基準，下次才不會重複告警');
        $payload = (array) $this->baselines()->first()?->payload;
        $this->assertSame(['cfb' => 'TS6F-OLD', 'bank' => 'TS6F'], $payload[$cfbId]);
    }

    public function test_resolved_drift_alerts_and_advances_baseline(): void
    {
        $bank = $this->seedBank('TS6G');
        $cfbId = $this->seedCfb($bank->id, 'TS6G-OLD');
        $this->establishBaseline();
        $count = $this->baselines()->count();
        DB::table('cash_flow_banks')->where('id', $cfbId)->update(['bank_code' => 'TS6G']);
        BotNotify::spy();

        $this->artisan('bank:audit-cfb-code --notify')
            ->expectsOutputToContain('已消失 1 筆')
            ->assertSuccessful();

        BotNotify::shouldHaveReceived('send')->once();
        $this->assertSame($count + 1, $this->baselines()->count());
        $this->assertArrayNotHasKey($cfbId, (array) $this->baselines()->first()?->payload);
    }

    /**
     * 人工執行只印報表：不建基準也不更新基準，否則會把當天排程該報的變動吃掉。
     */
    public function test_manual_run_never_writes_baseline(): void
    {
        $before = $this->baselines()->count();
        $this->seedCfb($this->seedBank('TS6H')->id, 'TS6H-OLD');
        BotNotify::spy();

        $this->artisan('bank:audit-cfb-code')->assertSuccessful();
        $this->assertSame($before, $this->baselines()->count(), '沒有基準時人工執行也不建基準');

        $this->establishBaseline();
        $count = $this->baselines()->count();
        $this->seedCfb($this->seedBank('TS6H2')->id, 'TS6H2-OLD');

        $this->artisan('bank:audit-cfb-code')->assertExitCode(1);

        $this->assertSame($count, $this->baselines()->count(), '有變動時人工執行回非零但不更新基準');
        BotNotify::shouldNotHaveReceived('send');
    }
}
