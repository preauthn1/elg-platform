<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Bank;

use App\Models\Bank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SetBankAmountLimitsCommand 測試（逐列調整 banks 提款限額）
 *
 * 守備重點有三：
 * 1. 只動點名的列。既有的 cash-flow:reset-banks-min-max-amount 是「整群組依 ACF 重算」，
 *    收單一敞口時會連帶改動同群組其餘數十列；本命令以 banks.id 點名，範圍即選取。
 * 2. 拒絕 0。banks 的 0 在前台是「回退代理層自助提款設定」而非「不限制」，
 *    把限額設成 0 等於把該行放大到代理預設值（VPlus33 實案為 500,000）。
 * 3. 結果值必須 min <= max。判定用「寫入後的值」而非選項值——只給 --max 時
 *    仍要與該列既有 min 比較，否則會寫出一個永遠提不了款的區間。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Bank/SetBankAmountLimitsCommandTest.php
 */
class SetBankAmountLimitsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const AGENT_GROUP = 99810;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = (int) DB::table('currencies')->insertGetId([
            'code' => 'TS3', 'name' => 'Test Currency 3', 'rate' => 1, 'admin' => 0,
        ]);
    }

    private function seedBank(string $code, int|string $min = 20, int|string $max = 500000): Bank
    {
        return Bank::create([
            'agent_group_id' => self::AGENT_GROUP,
            'type' => 4,
            'currency_id' => $this->currencyId,
            'name' => '(TS3) - Limit Bank ' . $code,
            'code' => $code,
            'status' => 1,
            'rate' => 1, 'tax_type' => 0, 'tax' => 0,
            'min_amount' => $min, 'max_amount' => $max, 'sort' => 0, 'admin' => 0,
        ]);
    }

    public function test_updates_max_amount_and_leaves_min_untouched(): void
    {
        $bank = $this->seedBank('TSTLIM01', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '30000',
            '--reason' => '收斂超出通道能力的單筆敞口',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = DB::table('banks')->where('id', $bank->id)->first();
        $this->assertSame(30000, (int) $fresh->max_amount);
        $this->assertSame(20, (int) $fresh->min_amount, '未指定 --min 時不得動到既有下限');
    }

    public function test_records_previous_values_in_audit_log(): void
    {
        $bank = $this->seedBank('TSTLIM02', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '30000',
            '--reason' => '收斂超出通道能力的單筆敞口',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $log = DB::table('bank_dedup_logs')
            ->where('action', 'set_amount_limits')
            ->where('loser_bank_id', $bank->id)
            ->first();

        $this->assertNotNull($log, '必須寫審計，還原時要靠它取回異動前的值');

        $detail = json_decode((string) $log->detail, true);
        $this->assertSame(500000, (int) $detail['was']['max_amount']);
        $this->assertSame(20, (int) $detail['was']['min_amount']);
        $this->assertSame('收斂超出通道能力的單筆敞口', $detail['reason']);
    }

    public function test_defaults_to_preview_and_writes_nothing(): void
    {
        $bank = $this->seedBank('TSTLIM03', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '30000',
            '--reason' => '收斂超出通道能力的單筆敞口',
        ])->assertExitCode(0);

        $this->assertSame(500000, (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'));
        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('action', 'set_amount_limits')->where('loser_bank_id', $bank->id)->count()
        );
    }

    public function test_rejects_zero_because_it_means_fallback_to_agent_default(): void
    {
        $bank = $this->seedBank('TSTLIM04', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '0',
            '--reason' => '想要不限制',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(500000, (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'));
    }

    public function test_blocks_when_resulting_min_exceeds_max(): void
    {
        // 只給 --max，且新上限低於該列既有下限：寫下去會是永遠提不了款的區間
        $bank = $this->seedBank('TSTLIM05', min: 5000, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '3000',
            '--reason' => '收斂敞口',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(500000, (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'));
    }

    public function test_requires_reason(): void
    {
        $bank = $this->seedBank('TSTLIM06');

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '30000',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(500000, (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'));
    }

    public function test_requires_at_least_one_of_min_or_max(): void
    {
        $bank = $this->seedBank('TSTLIM07');

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--reason' => '沒給任何值',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);
    }

    public function test_one_missing_id_aborts_the_whole_batch(): void
    {
        $bank = $this->seedBank('TSTLIM08', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id, 99899999],
            '--max' => '30000',
            '--reason' => '收斂敞口',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(
            500000,
            (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'),
            '批次內任一列不合格即整批零寫入'
        );
    }

    public function test_skips_row_already_at_target_value(): void
    {
        $bank = $this->seedBank('TSTLIM09', min: 20, max: 30000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '30000',
            '--reason' => '重跑同一批',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('action', 'set_amount_limits')->where('loser_bank_id', $bank->id)->count(),
            '值沒變就不該留審計，重跑不製造噪音'
        );
    }

    public function test_does_not_touch_decimal_min_when_only_max_given(): void
    {
        // banks.min_amount 是 decimal(18,4)：以 int 處理會把 0.5 截成 0，
        // 而 0 正是本命令拒絕接受的 sentinel（前台當「回退代理層設定」）。
        $bank = $this->seedBank('TSTLIM11', min: '0.5000', max: '10000');

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '5000',
            '--reason' => '收斂上限，不動下限',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = DB::table('banks')->where('id', $bank->id)->first();
        $this->assertSame('0.5000', (string) $fresh->min_amount, '未指定 --min 的欄位不得被寫入，更不得被截斷成 0');
        $this->assertSame(5000, (int) $fresh->max_amount);
    }

    public function test_audit_records_original_decimal_value_not_truncated(): void
    {
        $bank = $this->seedBank('TSTLIM12', min: '0.5000', max: '10000');

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '5000',
            '--reason' => '收斂上限，不動下限',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $log = DB::table('bank_dedup_logs')
            ->where('action', 'set_amount_limits')
            ->where('loser_bank_id', $bank->id)
            ->first();

        $detail = json_decode((string) $log->detail, true);
        $this->assertSame(
            '0.5000',
            (string) $detail['was']['min_amount'],
            '審計是唯一的反演來源，記截斷值等於真值再也拿不回來'
        );
    }

    public function test_blocks_when_resulting_min_is_zero_sentinel(): void
    {
        // 漏擋方向：min=0 代表下限走代理層預設（可能遠高於 50），
        // 拿本列的 0 去比 50 會誤判為合法，寫出永遠提不了款的區間。
        $bank = $this->seedBank('TSTLIM13', min: 0, max: 10000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '50',
            '--reason' => '收上限',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(10000, (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'));
    }

    public function test_blocks_when_resulting_max_is_zero_sentinel(): void
    {
        // 誤擋方向：max=0 代表上限走代理層預設（VPlus33 實案為 500,000），
        // 舊實作會說「該行將無法提款」——與事實相反。
        $bank = $this->seedBank('TSTLIM14', min: 20, max: 0);

        // 舊實作也會阻斷（100 > 0），但理由是「該行將無法提款」——與事實相反。
        // 斷言訊息才能守住「阻斷的理由正確」這個性質。
        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--min' => '100',
            '--reason' => '提高下限',
            '--execute' => true,
            '--force' => true,
        ])
            ->expectsOutputToContain('回退代理層設定')
            ->assertExitCode(1);

        $this->assertSame(20, (int) DB::table('banks')->where('id', $bank->id)->value('min_amount'));
    }

    public function test_allows_zero_sided_row_when_both_limits_are_given(): void
    {
        // 兩側都給了明確值，結果值不含 sentinel，區間就驗得出來 → 放行
        $bank = $this->seedBank('TSTLIM15', min: 0, max: 0);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--min' => '20',
            '--max' => '30000',
            '--reason' => '補齊未設定的限額',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = DB::table('banks')->where('id', $bank->id)->first();
        $this->assertSame(20, (int) $fresh->min_amount);
        $this->assertSame(30000, (int) $fresh->max_amount);
    }

    public function test_rejects_comma_separated_bank_ids(): void
    {
        // intval("9,14") === 9：靜默只收第一個，敞口留著沒收而輸出看起來成功
        $bank = $this->seedBank('TSTLIM18', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id . ',99899999'],
            '--max' => '30000',
            '--reason' => '收斂敞口',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(500000, (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'));
    }

    public function test_rejects_reason_with_invalid_utf8_before_writing(): void
    {
        // json_encode 遇無效 UTF-8 會回 false，靜默寫入空審計——而 banks 那筆
        // 不可逆的寫入照樣 commit。不可逆操作失去審計，且沒有人會發現。
        $bank = $this->seedBank('TSTLIM16', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '30000',
            '--reason' => "收斂敞口\xB1\x31",
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(500000, (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'));
        $this->assertSame(
            0,
            DB::table('bank_dedup_logs')->where('action', 'set_amount_limits')->where('loser_bank_id', $bank->id)->count()
        );
    }

    public function test_rejects_overlong_reason_before_writing(): void
    {
        $bank = $this->seedBank('TSTLIM17', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '30000',
            '--reason' => str_repeat('補', 2001),
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame(500000, (int) DB::table('banks')->where('id', $bank->id)->value('max_amount'));
    }

    public function test_updates_both_min_and_max_together(): void
    {
        $bank = $this->seedBank('TSTLIM10', min: 100, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--min' => '20',
            '--max' => '30000',
            '--reason' => '同時收上下限',
            '--execute' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = DB::table('banks')->where('id', $bank->id)->first();
        $this->assertSame(20, (int) $fresh->min_amount);
        $this->assertSame(30000, (int) $fresh->max_amount);
    }

    /**
     * 寫入目標由容器的 -e DB_CONNECTION 決定、不在命令參數裡，各白標 banks.id 區間又
     * 重疊，連錯站時預檢一樣會全綠。故連線與庫名必須在預演階段就印出來——那是操作者
     * 唯一能在寫入前察覺「我打錯站」的機會。
     */
    public function test_preview_discloses_write_target_database(): void
    {
        $bank = $this->seedBank('TSTLIM11', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--max' => '30000',
            '--reason' => '收斂超出通道能力的單筆敞口',
        ])
            // 兩個斷言不可共存於同一行輸出：PendingCommand 逐行消耗，
            // 同一行同時滿足兩個期待時只會消掉第一個，第二個必然落空。
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(0);
    }

    /**
     * 阻斷才是最需要核對環境的時刻：操作者要判斷「這是真的區間顛倒，還是我連錯站」。
     * 揭露若排在預檢之後，這個失敗出口就看不到自己打的是哪個庫。
     */
    public function test_blocked_run_still_discloses_write_target_database(): void
    {
        $bank = $this->seedBank('TSTLIM12', min: 20, max: 500000);

        $this->artisan('bank:set-amount-limits', [
            '--bank' => [$bank->id],
            '--min' => '900000',
            '--reason' => '收斂超出通道能力的單筆敞口',
        ])
            ->expectsOutputToContain('寫入目標 connection')
            ->expectsOutputToContain((string) DB::connection()->getDatabaseName())
            ->assertExitCode(1);
    }
}
