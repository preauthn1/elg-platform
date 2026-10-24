<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Guard;

use App\Console\Commands\CashFlow\Guard\RemapTpowerFpayIdrBankCodes;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use App\Models\Currency;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * RemapTpowerFpayIdrBankCodes 測試
 *
 * 把代付設定與 cash_flow_banks 的 FPay 印尼盾銀行代號由舊換新（target/op_tickets#3174）：
 * 預設只出報表、寫入冪等、FPay 清單對不上就整體攔截零寫入。
 * 用 DatabaseTransactions 避免污染共用測試庫；FPay 清單一律 Http::fake，不會真的打三方。
 *
 * ⚠️ 測試資料挑代號的注意事項：有 8 個代號新舊都用得到（FPay 把它們換給別家銀行），
 * 只用這幾個當樣本，命令會判「分不出換過沒有」而攔截。cash_flow_banks 的預設樣本因此一律取
 * 「新代號不在舊代號集合裡」的對照，好讓判定成立。
 *
 * 🔴 這個排除只是為了讓預設樣本判得出新舊，**不代表那 8 個代號不必驗**。它們要兩邊都覆蓋：
 * 整組只剩共用代號而攔截，以 test 9 覆蓋；已是新代號時共用代號不得被二次換碼，以 test 12 覆蓋。
 * 缺了 test 12 的那段期間，正式環境被寫壞了 7 列（target/op_tickets#3174）。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Guard/RemapTpowerFpayIdrBankCodesTest.php
 */
class RemapTpowerFpayIdrBankCodesTest extends TestCase
{
    use DatabaseTransactions;

    private const string COMMAND = 'cash-flow:remap-tpower-fpay-idr-bankcodes';

    private const string TEST_BANK_CODE_PREFIX = 'FPAYTESTIDR';

    /** cash_flow_banks 取幾筆對照當樣本 */
    private const int SAMPLE_SIZE = 3;

    private int $currencyId = 0;

    /** @var list<int> 測試建立的 banks.id，供 cash_flow_banks 關聯 */
    private array $bankIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = $this->resolveCurrencyId();
        $this->seedCashFlow(RemapTpowerFpayIdrBankCodes::CASH_FLOW_THIRD_PARTY_CODE);
        $this->seedBanks();
    }

    // -------------------------------------------------------------------------
    // Test 1: 預設只出報表、零寫入
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_dry_run_writes_nothing(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $this->artisan(self::COMMAND)->assertExitCode(0);

        foreach (RemapTpowerFpayIdrBankCodes::TARGETS as $target) {
            $this->assertSame($this->oldCodes(), $this->thirdCodesOf($target['acf_id']));
        }

        $this->assertSame($this->sampleCodes('old'), $this->activeProviderCodes());
    }

    // -------------------------------------------------------------------------
    // Test 2: --execute 換成新代號，只動三方碼
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_execute_rewrites_third_party_codes_only(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $this->runExecute();

        foreach (RemapTpowerFpayIdrBankCodes::TARGETS as $target) {
            $this->assertSame($this->newCodes(), $this->thirdCodesOf($target['acf_id']));
            // 我方銀行代號與其他設定值不動
            $this->assertSame($this->elgCodes(), $this->elgCodesOf($target['acf_id']));
            $this->assertSame('保留', AgentCashFlow::query()->find($target['acf_id'])?->info['DisplayName']);
        }

        $this->assertSame($this->sampleCodes('new'), $this->activeProviderCodes());
        // 停用中的列不動
        $this->assertSame([$this->sampleCodes('old')[0]], $this->disabledProviderCodes());
    }

    // -------------------------------------------------------------------------
    // Test 3: 重跑冪等，第二次零變更
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_execute_is_idempotent(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $this->runExecute();
        $this->runExecute();

        $this->assertSame($this->newCodes(), $this->thirdCodesOf(RemapTpowerFpayIdrBankCodes::TARGETS[0]['acf_id']));
        $this->assertSame($this->sampleCodes('new'), $this->activeProviderCodes());
    }

    // -------------------------------------------------------------------------
    // Test 4: FPay 把某個代號換成別家銀行（名稱對不上）→ 整體攔截零寫入
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_aborts_when_fpay_bank_name_changed(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();

        $list = $this->bankListRows();
        $list[0]['bank_name'] = '別家銀行';
        $this->fakeBankList($list);

        $this->runExecute(1);

        $this->assertNothingWritten();
    }

    // -------------------------------------------------------------------------
    // Test 5: 對照表的新代號已不在 FPay 清單上 → 整體攔截零寫入
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_aborts_when_new_code_missing_from_fpay_list(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();

        $list = $this->bankListRows();
        array_shift($list);
        $this->fakeBankList($list);

        $this->runExecute(1);

        $this->assertNothingWritten();
    }

    // -------------------------------------------------------------------------
    // Test 6: 現況新舊代號混雜 → 整體攔截零寫入（轉人工）
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_aborts_when_codes_are_mixed(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $mixed = $this->oldCodes();
        $mixed[0] = $this->sampleCodes('new')[0];
        $this->replaceAvailableBanks(RemapTpowerFpayIdrBankCodes::TARGETS[0]['acf_id'], $mixed);

        $this->runExecute(1);

        $this->assertSame($this->oldCodes(), $this->thirdCodesOf(RemapTpowerFpayIdrBankCodes::TARGETS[1]['acf_id']));
        $this->assertSame($this->sampleCodes('old'), $this->activeProviderCodes());
    }

    // -------------------------------------------------------------------------
    // Test 7: 金流商不是 FPay 代付（跑錯白標 DB）→ 整體攔截零寫入
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_aborts_when_cash_flow_is_not_fpay(): void
    {
        $this->seedCashFlow('W_OTHER');
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $this->runExecute(1);

        $this->assertNothingWritten();
    }

    // -------------------------------------------------------------------------
    // Test 8: 兩條代付設定查到的 FPay 清單不一致 → 整體攔截零寫入
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_aborts_when_two_acfs_get_different_lists(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();

        $second = $this->bankListRows();
        array_pop($second);

        Http::fake(['*/wallet/withdraw_bank_list' => Http::sequence()
            ->push(['status' => true, 'data' => $this->bankListRows()])
            ->push(['status' => true, 'data' => $second])]);

        $this->runExecute(1);

        $this->assertNothingWritten();
    }

    // -------------------------------------------------------------------------
    // Test 9: 只剩新舊共用的代號、判不出換過沒有 → 整體攔截零寫入
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_aborts_when_only_reused_codes_remain(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $reused = $this->reusedCodes();
        $this->assertNotSame([], $reused, '對照表應有新舊共用的代號');
        $this->replaceAvailableBanks(
            RemapTpowerFpayIdrBankCodes::TARGETS[0]['acf_id'],
            array_slice($reused, 0, 3),
        );

        $this->runExecute(1);

        $this->assertSame($this->oldCodes(), $this->thirdCodesOf(RemapTpowerFpayIdrBankCodes::TARGETS[1]['acf_id']));
        $this->assertSame($this->sampleCodes('old'), $this->activeProviderCodes());
    }

    // -------------------------------------------------------------------------
    // 以下為測試輔助
    // -------------------------------------------------------------------------

    // -------------------------------------------------------------------------
    // Test 10: --execute 為啟用中的列補上人工覆寫標記（擋住 cash-flow:sync 沖回舊碼）
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_execute_marks_active_rows_as_manual_override(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $this->runExecute();

        $this->assertSame(self::SAMPLE_SIZE, $this->markedRowCount(1));
        // 停用中的列本命令一律不動，當然也不該被標記
        $this->assertSame(0, $this->markedRowCount(0));
    }

    // -------------------------------------------------------------------------
    // Test 11: 代號早就換好、只缺標記時仍要補上
    //
    // 這正是 2026-09-16 正式環境的狀態：353 筆代號已經換成新碼，但當時的版本還不會寫標記，
    // 所以那批列在加上守衛之後仍然是沒保護的。舊版的「已是新代號就直接回傳」會讓它們永遠補不到。
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_execute_backfills_marks_when_codes_are_already_new(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $this->runExecute();

        // 清掉標記，模擬「代號換過了、但標記沒寫」的存量狀態
        CashFlowBank::query()
            ->where('cash_flow_id', RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID)
            ->where('currency_id', $this->currencyId)
            ->update(['manual_override_at' => null, 'manual_override_note' => null]);

        $this->runExecute();

        $this->assertSame(self::SAMPLE_SIZE, $this->markedRowCount(1));
        // 代號維持新碼，沒有被重複改寫
        $this->assertSame($this->sampleCodes('new'), $this->activeProviderCodes());
    }

    // -------------------------------------------------------------------------
    // Test 12: 已是新代號時，新舊共用的那 8 個代號不得被二次換碼
    //
    // 2026-09-17 正式環境的實際災情：那 8 個代號在新舊兩套裡都有效（11 舊指 UOB、新指 BRI）。
    // 當時寫入端是逐列去查舊→新對照表，對這些代號必然命中，於是把已經正確的 BRI（11）換成了
    // UOB 的新代號（309），7 列指到別家銀行。報表那側用整組判定、算出「待更新 0 筆」，
    // 兩邊結論不一致，所以報表看起來完全正常、沒有任何異狀可循。
    // -------------------------------------------------------------------------
    #[Group('rules/payout-bank-code-mapping#R-30')]
    public function test_execute_keeps_reused_code_untouched_when_already_new(): void
    {
        $this->seedTargetAcfs();
        $this->seedCashFlowBanks();
        $this->fakeBankList();

        $this->runExecute();

        // 造出正式環境那幾列的形狀：代號已經換成新的，而該新代號恰好也是某個舊代號。
        // 其餘兩列維持「只有新代號才有」的碼，整組才判得出是「已是新代號」。
        $reusedCode = $this->reusedCodes()[0];
        $firstRow = CashFlowBank::query()
            ->where('cash_flow_id', RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID)
            ->where('currency_id', $this->currencyId)
            ->where('status', 1)
            ->orderBy('id')
            ->firstOrFail();
        $firstRow->provider_bank_code = $reusedCode;
        $firstRow->manual_override_at = null;
        $firstRow->manual_override_note = null;
        $firstRow->save();

        $this->runExecute();

        $expected = $this->sampleCodes('new');
        $expected[0] = $reusedCode;
        // 代號必須原封不動：缺陷版本會把它換成對照表裡該舊代號對應的新代號
        $this->assertSame($expected, $this->activeProviderCodes());
        // 標記照樣補回來
        $this->assertSame(self::SAMPLE_SIZE, $this->markedRowCount(1));
    }

    // -------------------------------------------------------------------------
    // Test 13: 校正模式把對不上代付設定的代號改回來
    //
    // 換碼模式救不了這種列：它持有的 309 本身就是一個合法的新代號（UOB 的），
    // 整組判定會是「已經是新代號」而不動它。只有拿代付設定逐列比對才改得回來。
    // -------------------------------------------------------------------------
    public function test_align_mode_restores_codes_from_payout_config(): void
    {
        $this->seedTargetAcfs();
        $this->seedAlignSamples();

        $this->runAlign(execute: true);

        $payoutConfigCodes = $this->oldCodes();
        $seeded = $this->alignSampleCodes();
        // 第一列改回代付設定的值；第二列本來就一致；第三列在代付設定裡查無對應條目，不動
        $this->assertSame(
            [$payoutConfigCodes[0], $payoutConfigCodes[1], $seeded[2]],
            $this->activeProviderCodes(),
        );
        // 只有真的被改的那一列會順手補上人工覆寫標記
        $this->assertSame(1, $this->markedRowCount(1));
    }

    // -------------------------------------------------------------------------
    // Test 14: 校正模式預設只出報表，不寫入
    // -------------------------------------------------------------------------
    public function test_align_mode_writes_nothing_without_execute(): void
    {
        $this->seedTargetAcfs();
        $this->seedAlignSamples();

        $this->runAlign();

        $this->assertSame($this->alignSampleCodes(), $this->activeProviderCodes());
        $this->assertSame(0, $this->markedRowCount(1));
    }

    // -------------------------------------------------------------------------
    // Test 15: 兩條代付設定的對映不一致 → 沒有可信的權威來源，整體攔截零寫入
    // -------------------------------------------------------------------------
    public function test_align_mode_aborts_when_payout_configs_disagree(): void
    {
        $this->seedTargetAcfs();
        $this->seedAlignSamples();

        $divergent = $this->oldCodes();
        $divergent[0] = $this->sampleCodes('new')[0];
        $this->replaceAvailableBanks(RemapTpowerFpayIdrBankCodes::TARGETS[1]['acf_id'], $divergent);

        $this->runAlign(execute: true, expectedExitCode: 1);

        $this->assertSame($this->alignSampleCodes(), $this->activeProviderCodes());
        $this->assertSame(0, $this->markedRowCount(1));
    }

    /**
     * 校正模式的樣本，刻意做成正式環境的形狀：
     * 第一列被寫成別家銀行的新代號（309 是 UOB 的）、第二列與代付設定一致、
     * 第三列的我方銀行代號在代付設定裡查無對應條目。
     *
     * @return list<string>
     */
    private function alignSampleCodes(): array
    {
        return ['309', $this->oldCodes()[1], 'ORPHAN'];
    }

    private function seedAlignSamples(): void
    {
        CashFlowBank::query()
            ->where('cash_flow_id', RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID)
            ->where('currency_id', $this->currencyId)
            ->delete();

        $codes = $this->alignSampleCodes();

        $this->createCashFlowBank(0, $codes[0], 1);
        $this->createCashFlowBank(1, $codes[1], 1);
        $this->createCashFlowBank(2, $codes[2], 1, 'TPID999');
    }

    private function runAlign(bool $execute = false, int $expectedExitCode = 0): void
    {
        $options = ['--align-from-payout-config' => true];

        if ($execute) {
            $options['--execute'] = true;
            $options['--force'] = true;
        }

        $this->artisan(self::COMMAND, $options)->assertExitCode($expectedExitCode);
    }

    private function runExecute(int $expectedExitCode = 0): void
    {
        $this->artisan(self::COMMAND, ['--execute' => true, '--force' => true])
            ->assertExitCode($expectedExitCode);
    }

    private function assertNothingWritten(): void
    {
        foreach (RemapTpowerFpayIdrBankCodes::TARGETS as $target) {
            $this->assertSame($this->oldCodes(), $this->thirdCodesOf($target['acf_id']));
        }

        $this->assertSame($this->sampleCodes('old'), $this->activeProviderCodes());
        // 攔截時連標記都不該寫進去
        $this->assertSame(0, $this->markedRowCount(1));
    }

    /** 指定狀態的列裡，已經有人工覆寫標記的筆數 */
    private function markedRowCount(int $status): int
    {
        return CashFlowBank::query()
            ->where('cash_flow_id', RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID)
            ->where('currency_id', $this->currencyId)
            ->where('status', $status)
            ->whereNotNull('manual_override_at')
            ->count();
    }

    private function resolveCurrencyId(): int
    {
        $existing = (int) Currency::query()->where('code', RemapTpowerFpayIdrBankCodes::CURRENCY_CODE)->value('id');

        if ($existing > 0) {
            return $existing;
        }

        $currency = new Currency;
        $currency->forceFill([
            'code' => RemapTpowerFpayIdrBankCodes::CURRENCY_CODE,
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ])->save();

        return (int) $currency->id;
    }

    private function seedCashFlow(string $thirdPartyCode): void
    {
        CashFlow::query()->where('id', RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID)->delete();

        $cashFlow = new CashFlow;
        $cashFlow->forceFill([
            'id' => RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID,
            'third_party_code' => $thirdPartyCode,
            'name' => 'Test ' . $thirdPartyCode,
            'type' => 3,
            'status' => 1,
            'admin' => 0,
        ])->save();
    }

    /**
     * 建測試用平台銀行，供 cash_flow_banks 關聯（不掛到真實 banks 列）。
     */
    private function seedBanks(): void
    {
        $codes = [];

        for ($i = 1; $i <= self::SAMPLE_SIZE + 1; $i++) {
            $codes[] = self::TEST_BANK_CODE_PREFIX . $i;
        }

        DB::table('banks')->whereIn('code', $codes)->delete();

        $this->bankIds = [];

        foreach ($codes as $code) {
            $this->bankIds[] = (int) DB::table('banks')->insertGetId([
                'agent_group_id' => 0,
                'type' => 1,
                'currency_id' => $this->currencyId,
                'code' => $code,
                'name' => '(IDR) - ' . $code,
                'status' => 1,
            ]);
        }
    }

    private function seedTargetAcfs(): void
    {
        foreach (RemapTpowerFpayIdrBankCodes::TARGETS as $target) {
            AgentCashFlow::query()->where('id', $target['acf_id'])->delete();

            $agentCashFlow = new AgentCashFlow;
            $agentCashFlow->forceFill([
                'id' => $target['acf_id'],
                'cash_flow_id' => RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID,
                'agent_group_id' => $target['agent_group_id'],
                'currency_id' => $this->currencyId,
                'name' => '(IDR) FPay 代付 ' . $target['acf_id'],
                'info' => [
                    'API_URL' => 'https://api.fpay.test',
                    'MERCHANT_NO' => 'TEST_MERCHANT',
                    'DisplayName' => '保留',
                    'available_banks' => $this->availableBanks($this->oldCodes()),
                ],
                'status' => 1,
                'admin' => 0,
            ])->save();
        }
    }

    /**
     * 建啟用中與停用中的 cash_flow_banks，代號都是舊碼。
     */
    private function seedCashFlowBanks(): void
    {
        CashFlowBank::query()
            ->where('cash_flow_id', RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID)
            ->where('currency_id', $this->currencyId)
            ->delete();

        $sampleOldCodes = $this->sampleCodes('old');

        foreach ($sampleOldCodes as $index => $code) {
            $this->createCashFlowBank($index, $code, 1);
        }

        // 停用中的列：代號同第一筆，用來驗證本命令不動停用列
        $this->createCashFlowBank(self::SAMPLE_SIZE, $sampleOldCodes[0], 0);
    }

    private function createCashFlowBank(int $bankIndex, string $providerBankCode, int $status, ?string $bankCode = null): void
    {
        CashFlowBank::query()->create([
            'cash_flow_id' => RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID,
            'currency_id' => $this->currencyId,
            'bank_id' => $this->bankIds[$bankIndex],
            // 與代付設定 available_banks 的 BankCode_ELG 用同一套命名（正式環境也是同一套），
            // 校正模式就是靠這個欄位把兩邊對起來；傳 $bankCode 可造出代付設定裡查無對應的列
            'bank_code' => $bankCode ?? sprintf('TPID%02d', $bankIndex + 1),
            'provider_bank_code' => $providerBankCode,
            'status' => $status,
        ]);
    }

    /**
     * @param  list<array{currency: string, bank_name: string, id: string}>|null  $rows
     */
    private function fakeBankList(?array $rows = null): void
    {
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([
            'status' => true,
            'data' => $rows ?? $this->bankListRows(),
        ])]);
    }

    /**
     * FPay 清單：對照表全部新代號，另加一個我方沒配置的代號（實際清單就是這樣）。
     *
     * @return list<array{currency: string, bank_name: string, id: string}>
     */
    private function bankListRows(): array
    {
        $rows = array_map(static fn (array $mapping): array => [
            'currency' => RemapTpowerFpayIdrBankCodes::CURRENCY_CODE,
            'bank_name' => $mapping['fpay_name'],
            'id' => $mapping['new'],
        ], RemapTpowerFpayIdrBankCodes::MAPPINGS);

        $rows[] = [
            'currency' => RemapTpowerFpayIdrBankCodes::CURRENCY_CODE,
            'bank_name' => 'QRIS',
            'id' => '999999',
        ];

        return $rows;
    }

    /**
     * @param  list<string>  $codes
     * @return list<array{BankCode_ELG: string, BankCode_Third: string}>
     */
    private function availableBanks(array $codes): array
    {
        $entries = [];

        foreach ($codes as $index => $code) {
            $entries[] = [
                'BankCode_ELG' => sprintf('TPID%02d', $index + 1),
                'BankCode_Third' => $code,
            ];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $codes
     */
    private function replaceAvailableBanks(int $acfId, array $codes): void
    {
        /** @var AgentCashFlow $agentCashFlow */
        $agentCashFlow = AgentCashFlow::query()->findOrFail($acfId);

        $info = $agentCashFlow->info;
        $info['available_banks'] = $this->availableBanks($codes);
        $agentCashFlow->info = $info;
        $agentCashFlow->save();
    }

    /**
     * @return list<string>
     */
    private function oldCodes(): array
    {
        return array_column(RemapTpowerFpayIdrBankCodes::MAPPINGS, 'old');
    }

    /**
     * @return list<string>
     */
    private function newCodes(): array
    {
        return array_column(RemapTpowerFpayIdrBankCodes::MAPPINGS, 'new');
    }

    /**
     * 新舊都用得到的代號（FPay 換給別家銀行的那批）。
     *
     * @return list<string>
     */
    private function reusedCodes(): array
    {
        return array_values(array_intersect($this->oldCodes(), $this->newCodes()));
    }

    /**
     * cash_flow_banks 樣本：取新代號不在舊代號集合裡的對照，避免撞上「判不出新舊」的攔截。
     *
     * @param  'old'|'new'  $key
     * @return list<string>
     */
    private function sampleCodes(string $key): array
    {
        $oldCodes = $this->oldCodes();
        $codes = [];

        foreach (RemapTpowerFpayIdrBankCodes::MAPPINGS as $mapping) {
            if (in_array($mapping['new'], $oldCodes, true)) {
                continue;
            }

            $codes[] = $mapping[$key];

            if (count($codes) === self::SAMPLE_SIZE) {
                break;
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    private function elgCodes(): array
    {
        return array_column($this->availableBanks($this->oldCodes()), 'BankCode_ELG');
    }

    /**
     * @return list<string>
     */
    private function thirdCodesOf(int $acfId): array
    {
        return $this->entryColumnOf($acfId, 'BankCode_Third');
    }

    /**
     * @return list<string>
     */
    private function elgCodesOf(int $acfId): array
    {
        return $this->entryColumnOf($acfId, 'BankCode_ELG');
    }

    /**
     * @return list<string>
     */
    private function entryColumnOf(int $acfId, string $key): array
    {
        $agentCashFlow = AgentCashFlow::query()->find($acfId);
        $entries = $agentCashFlow?->info['available_banks'] ?? [];

        return array_values(array_map(static fn (array $entry): string => (string) $entry[$key], $entries));
    }

    /**
     * @return list<string>
     */
    private function activeProviderCodes(): array
    {
        return $this->providerCodesByStatus(1);
    }

    /**
     * @return list<string>
     */
    private function disabledProviderCodes(): array
    {
        return $this->providerCodesByStatus(0);
    }

    /**
     * @return list<string>
     */
    private function providerCodesByStatus(int $status): array
    {
        return CashFlowBank::query()
            ->where('cash_flow_id', RemapTpowerFpayIdrBankCodes::CASH_FLOW_ID)
            ->where('currency_id', $this->currencyId)
            ->where('status', $status)
            ->orderBy('id')
            ->pluck('provider_bank_code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();
    }
}
