<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Console\Commands\CashFlow\Maintenance\AuditFpayBankList;
use App\Enums\CashFlowTypeEnum;
use App\Enums\DriftSubjectEnum;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\CashFlowBank;
use App\Models\Currency;
use App\Models\DriftBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * cash-flow:audit-fpay-bank-list 的行為測試。
 *
 * 覆蓋工單 target/op_tickets#3229 的兩個驗收情形：「代號消失」與「代號還在但銀行被換掉」
 * 都要被抓到並告警。後者是重點——照舊送出不會被三方退回，錢會匯到別家銀行，
 * 只有比對基準看得出來。
 *
 * 另覆蓋 drift_baselines 通用化後新增的語意：**本命令只能看見自己那個監測對象的列**。
 * `test_other_subject_rows_are_not_mistaken_for_our_baseline` 的自驗方式是把
 * DriftBaseline::scopeForScope() 裡的 subject_key 條件整行刪掉，它必須轉紅——實跑確認過會紅。
 *
 * ! 隔離的另一半（寫入不得落到別人的區分鍵下）由 `test_written_baseline_carries_our_keys` 守，
 * ! 它驗的是寫入端而非查詢端，所以拔掉查詢條件不會讓它紅。至於「不得誤刪別人的列」，
 * ! 本命令沒有任何更新或刪除路徑（只有 create），寫成測試會是拔掉條件也不紅的假保護。
 *
 * 所有資料列都是本測試自己建的（id 由 DB 給、不寫死），清理交給 DatabaseTransactions。
 */
class AuditFpayBankListTest extends TestCase
{
    use DatabaseTransactions;

    /** 另一個監測對象的鍵，用來驗證隔離；刻意不進 DriftSubjectEnum（寫入路徑不受 Enum 限制） */
    private const FOREIGN_SUBJECT_KEY = 'some_other_watched_thing';

    private int $currencyId;

    private int $cashFlowId;

    private int $acfId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = $this->seedCurrency();
        $this->cashFlowId = $this->seedCashFlow(AuditFpayBankList::CASH_FLOW_THIRD_PARTY_CODE, CashFlowTypeEnum::WITHDRAW->value);
        $this->acfId = $this->seedAgentCashFlow($this->cashFlowId, ['11', '13']);

        // 只清本測試這一列會用到的失敗計數器；Cache::flush() 會清掉整個共用快取，
        // 併行跑測試時會波及別人
        Cache::forget('cash-flow:audit-fpay-bank-list:failures:' . $this->acfId);
    }

    #[Group('rules/payout-bank-code-mapping#R-21')]
    public function test_first_run_creates_baseline_without_alerting(): void
    {
        BotNotify::spy();
        $this->fakeBankList(['11' => 'Bank Rakyat Indonesia (BRI)', '13' => 'Bank Central Asia (BCA)']);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        $this->assertSame(1, $this->ourBaselines()->count(), '第一次跑應該建立一筆基準');
        BotNotify::shouldNotHaveReceived('send');
    }

    #[Group('rules/payout-bank-code-mapping#R-25')]
    #[Group('rules/payout-bank-code-mapping#R-29')]
    public function test_no_change_does_not_alert(): void
    {
        $list = ['11' => 'Bank Rakyat Indonesia (BRI)', '13' => 'Bank Central Asia (BCA)'];
        $this->seedBaseline($list);
        BotNotify::spy();
        $this->fakeBankList($list);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::shouldNotHaveReceived('send');
        $this->assertSame(1, $this->ourBaselines()->count(), '內容沒變就不該新增列，那一天的執行等同唯讀');
    }

    /**
     * FPay 只是多了幾家銀行：不是風險、不告警，但基準要跟上。
     *
     * 不跟上的話那幾家永遠不在基準裡，日後被改名也偵測不到。
     */
    #[Group('rules/payout-bank-code-mapping#R-25')]
    public function test_added_banks_update_baseline_without_alerting(): void
    {
        $this->seedBaseline(['11' => 'Bank Rakyat Indonesia (BRI)', '13' => 'Bank Central Asia (BCA)']);
        BotNotify::spy();
        $this->fakeBankList([
            '11' => 'Bank Rakyat Indonesia (BRI)',
            '13' => 'Bank Central Asia (BCA)',
            '999' => 'Bank Baru',
        ]);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::shouldNotHaveReceived('send');
        $this->assertSame(2, $this->ourBaselines()->count());
        $this->assertArrayHasKey(999, $this->latestBaseline(), '新增的銀行要進基準，否則日後改名偵測不到');
    }

    /**
     * 工單驗收情形一：代號還在，但已經換成別家銀行。
     *
     * 取材自 target/op_tickets#3174 的實際情形——代號 13 從 Citibank 變成 BCA。
     */
    #[Group('rules/payout-bank-code-mapping#R-22')]
    #[Group('rules/payout-bank-code-mapping#R-28')]
    public function test_detects_bank_renamed_under_same_code(): void
    {
        $this->seedBaseline(['11' => 'UOB Bank Indonesia', '13' => 'Citibank']);
        BotNotify::spy();
        $this->fakeBankList(['11' => 'UOB Bank Indonesia', '13' => 'Bank Central Asia (BCA)']);

        $this->artisan('cash-flow:audit-fpay-bank-list')->assertFailed();

        BotNotify::shouldNotHaveReceived('send');
    }

    #[Group('rules/payout-bank-code-mapping#R-27')]
    #[Group('rules/payout-bank-code-mapping#R-28')]
    public function test_renamed_code_is_reported_in_notify_message(): void
    {
        $this->seedBaseline(['11' => 'UOB Bank Indonesia', '13' => 'Citibank']);
        BotNotify::spy();
        $this->fakeBankList(['11' => 'UOB Bank Indonesia', '13' => 'Bank Central Asia (BCA)']);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::shouldHaveReceived('send')
            ->withArgs(fn (string $message): bool => str_contains($message, 'Citibank')
                && str_contains($message, 'Bank Central Asia (BCA)'));
    }

    /**
     * 工單驗收情形二：代號從 FPay 清單消失。
     */
    #[Group('rules/payout-bank-code-mapping#R-22')]
    #[Group('rules/payout-bank-code-mapping#R-27')]
    public function test_detects_removed_code(): void
    {
        $this->seedBaseline(['11' => 'UOB Bank Indonesia', '13' => 'Citibank']);
        BotNotify::spy();
        $this->fakeBankList(['11' => 'UOB Bank Indonesia']);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::shouldHaveReceived('send')
            ->withArgs(fn (string $message): bool => str_contains($message, '13'));
    }

    /**
     * 差異未處理前不換基準——否則變動只會在當天報一次，那天沒人看到就再也抓不到。
     */
    #[Group('rules/payout-bank-code-mapping#R-24')]
    public function test_baseline_is_not_replaced_while_difference_stands(): void
    {
        $this->seedBaseline(['11' => 'UOB Bank Indonesia', '13' => 'Citibank']);
        $this->fakeBankList(['11' => 'UOB Bank Indonesia', '13' => 'Bank Central Asia (BCA)']);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        $this->assertSame(1, $this->ourBaselines()->count(), '有差異時不應新增列');
        $this->assertSame('Citibank', $this->latestBaseline()[13], '基準應維持原樣，明天才會再報一次');
    }

    #[Group('rules/payout-bank-code-mapping#R-24')]
    public function test_accept_replaces_baseline(): void
    {
        $this->seedBaseline(['11' => 'UOB Bank Indonesia', '13' => 'Citibank']);
        $this->fakeBankList(['11' => 'UOB Bank Indonesia', '13' => 'Bank Central Asia (BCA)']);

        $this->artisan('cash-flow:audit-fpay-bank-list --accept')->assertFailed();

        $this->assertSame(2, $this->ourBaselines()->count());
        $this->assertSame('Bank Central Asia (BCA)', $this->latestBaseline()[13], '--accept 之後應以新內容為基準');
    }

    /**
     * 代付送出時讀的是代付設定的 available_banks，這一側有代號不在清單上就是會被退回的單。
     */
    #[Group('rules/payout-bank-code-mapping#R-23')]
    public function test_detects_payout_config_code_missing_from_list(): void
    {
        $this->seedBaseline(['11' => 'Bank Rakyat Indonesia (BRI)']);
        BotNotify::spy();
        $this->fakeBankList(['11' => 'Bank Rakyat Indonesia (BRI)']);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        // 代付設定配了 11 與 13，FPay 只回 11 ⇒ 13 會被退回
        BotNotify::shouldHaveReceived('send')
            ->withArgs(fn (string $message): bool => str_contains($message, '代付設定在用的代號'));
    }

    /**
     * cash_flow_banks 是查表路徑讀的、也是重建 available_banks 的來源，同樣要查。
     */
    #[Group('rules/payout-bank-code-mapping#R-23')]
    public function test_detects_cash_flow_bank_code_missing_from_list(): void
    {
        $this->seedCashFlowBank('7');
        $this->seedBaseline(['11' => 'Bank Rakyat Indonesia (BRI)', '13' => 'Bank Central Asia (BCA)']);
        BotNotify::spy();
        $this->fakeBankList(['11' => 'Bank Rakyat Indonesia (BRI)', '13' => 'Bank Central Asia (BCA)']);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::shouldHaveReceived('send')
            ->withArgs(fn (string $message): bool => str_contains($message, 'cash_flow_banks 在用的代號'));
    }

    /**
     * 每天打一次三方 API，偶發失敗不值得吵醒任何人；連續失敗才告警。
     */
    #[Group('rules/payout-bank-code-mapping#R-26')]
    public function test_single_query_failure_does_not_alert(): void
    {
        $this->seedBaseline(['11' => 'Bank Rakyat Indonesia (BRI)']);
        BotNotify::spy();
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([], 500)]);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::shouldNotHaveReceived('send');
    }

    #[Group('rules/payout-bank-code-mapping#R-26')]
    public function test_consecutive_query_failures_alert(): void
    {
        $this->seedBaseline(['11' => 'Bank Rakyat Indonesia (BRI)']);
        Http::fake(['*/wallet/withdraw_bank_list' => Http::response([], 500)]);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::spy();
        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::shouldHaveReceived('send')
            ->withArgs(fn (string $message): bool => str_contains($message, '連續'));
    }

    #[Group('rules/payout-bank-code-mapping#R-26')]
    public function test_successful_query_resets_failure_counter(): void
    {
        $list = ['11' => 'Bank Rakyat Indonesia (BRI)', '13' => 'Bank Central Asia (BCA)'];
        $this->seedBaseline($list);

        // 三次執行依序拿到「失敗、成功、失敗」。不可改用三次 Http::fake()——同一個 URL 樣式
        // 重複註冊時先註冊的優先，後面兩次根本不會生效，三次都會拿到第一次註冊的失敗回應。
        Http::fakeSequence('*/wallet/withdraw_bank_list')
            ->pushStatus(500)
            ->push(['status' => true, 'data' => $this->bankListRows($list)])
            ->pushStatus(500);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();
        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        BotNotify::spy();
        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        // 中間那次成功已把計數歸零，第三次只是「連續第 1 次」失敗，未達告警門檻
        BotNotify::shouldNotHaveReceived('send');
    }

    /**
     * 只掃啟用中的 FPay 代付設定：代收設定與停用中的設定都不該被查。
     */
    #[Group('rules/payout-bank-code-mapping#R-20')]
    public function test_skips_deposit_and_disabled_configs(): void
    {
        AgentCashFlow::query()->whereKey($this->acfId)->update(['status' => 0]);

        $depositCashFlowId = $this->seedCashFlow('D_FPAY', CashFlowTypeEnum::DEPOSIT->value);
        $this->seedAgentCashFlow($depositCashFlowId, ['11']);

        Http::fake(['*/wallet/withdraw_bank_list' => Http::response(['status' => false], 200)]);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        $this->assertSame(0, $this->ourBaselines()->count(), '停用的代付設定與代收設定都不該產生基準');
        Http::assertNothingSent();
    }

    // ── 共用表隔離（drift_baselines 通用化後新增的語意）────────────────────────
    // 自驗方式：把 DriftBaseline::scopeForScope() 裡的 subject_key 條件整行刪掉，
    // 以下兩個測試必須轉紅。沒紅就代表隔離沒被測到。

    /**
     * 別的監測對象在同一個範圍鍵下也有列時，不得被誤認成我們的基準。
     *
     * 誤認的後果是雙向的：把別人的內容當基準會憑空報出一堆差異，
     * 也會讓「我方其實還沒有基準」這件事被掩蓋掉。
     */
    public function test_other_subject_rows_are_not_mistaken_for_our_baseline(): void
    {
        $this->seedForeignBaseline(['999' => 'Totally Different Thing']);
        BotNotify::spy();
        $this->fakeBankList(['11' => 'Bank Rakyat Indonesia (BRI)', '13' => 'Bank Central Asia (BCA)']);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        // 我方沒有基準 ⇒ 應判定為第一次、只建立基準、不告警
        BotNotify::shouldNotHaveReceived('send');
        $this->assertSame(1, $this->ourBaselines()->count(), '應該建立我方第一筆基準');
    }

    /**
     * 寫入的基準必須帶上我方的區分鍵，而且不動到別人既有的列。
     *
     * 這條往另一個方向守：`storeSnapshot()` 忘了填 subject_key、或填成別人的值，
     * 不會有任何錯誤訊息，但那一列會變成別的監測對象的基準——兩邊一起壞掉，
     * 而且是下一次執行才發作。
     */
    public function test_written_baseline_carries_our_keys(): void
    {
        $foreignPayload = ['999' => 'Totally Different Thing'];
        $foreignId = $this->seedForeignBaseline($foreignPayload);
        $this->fakeBankList(['11' => 'Bank Rakyat Indonesia (BRI)', '13' => 'Bank Central Asia (BCA)']);

        $this->artisan('cash-flow:audit-fpay-bank-list --notify')->assertSuccessful();

        /** @var DriftBaseline $written */
        $written = $this->ourBaselines()->orderByDesc('id')->firstOrFail();
        $this->assertSame(DriftSubjectEnum::FPAY_WITHDRAW_BANK_LIST->value, $written->subject_key);
        $this->assertSame((string) $this->acfId, $written->scope_key, 'scope_key 必須是字串，整數綁定會讓 varchar 欄位退化成數值比較');

        $foreign = DriftBaseline::query()->findOrFail($foreignId);
        $this->assertSame($foreignPayload, $foreign->payload, '別的監測對象的內容不得被動到');
    }

    // ── 建置輔助 ──────────────────────────────────────────────────────────────

    private function seedCurrency(): int
    {
        $existing = (int) Currency::query()->where('code', 'IDR')->value('id');

        if ($existing > 0) {
            return $existing;
        }

        $currency = new Currency;
        $currency->forceFill([
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ])->save();

        return (int) $currency->id;
    }

    private function seedCashFlow(string $thirdPartyCode, int $type): int
    {
        $cashFlow = new CashFlow;
        $cashFlow->forceFill([
            'third_party_code' => $thirdPartyCode,
            'name' => 'Test ' . $thirdPartyCode,
            'type' => $type,
            'status' => 1,
            'admin' => 0,
        ])->save();

        return (int) $cashFlow->id;
    }

    /**
     * @param  list<string>  $thirdCodes  代付設定 available_banks 配的三方代號
     */
    private function seedAgentCashFlow(int $cashFlowId, array $thirdCodes): int
    {
        $entries = [];

        foreach ($thirdCodes as $index => $code) {
            $entries[] = [
                'BankCode_ELG' => sprintf('TPID%02d', $index + 1),
                'BankCode_Third' => $code,
            ];
        }

        $agentCashFlow = new AgentCashFlow;
        $agentCashFlow->forceFill([
            'cash_flow_id' => $cashFlowId,
            'agent_group_id' => 674,
            'currency_id' => $this->currencyId,
            'name' => '(IDR) FPay 代付',
            'info' => [
                'API_URL' => 'https://api.fpay.test',
                'MERCHANT_NO' => 'TEST_MERCHANT',
                'available_banks' => $entries,
            ],
            'status' => 1,
            'admin' => 0,
        ])->save();

        return (int) $agentCashFlow->id;
    }

    private function seedCashFlowBank(string $providerBankCode): void
    {
        $bankId = (int) DB::table('banks')->insertGetId([
            'agent_group_id' => 0,
            'type' => 1,
            'currency_id' => $this->currencyId,
            'code' => 'TPIDTEST01',
            'name' => '(IDR) - Test Bank',
            'status' => 1,
        ]);

        CashFlowBank::query()->create([
            'cash_flow_id' => $this->cashFlowId,
            'currency_id' => $this->currencyId,
            'bank_id' => $bankId,
            'bank_code' => 'TPIDTEST01',
            'provider_bank_code' => $providerBankCode,
            'status' => 1,
        ]);
    }

    /**
     * ! 鍵標 array-key 不標 string：PHP 會把 '11' 這種純數字字串鍵存成整數鍵，
     * ! 本檔所有「代號 => 名稱」的對照都是這個形狀。
     *
     * @param  array<array-key, string>  $banks
     */
    private function seedBaseline(array $banks): void
    {
        DriftBaseline::query()->create([
            'subject_key' => DriftSubjectEnum::FPAY_WITHDRAW_BANK_LIST->value,
            'scope_key' => (string) $this->acfId,
            'scope_label' => 'IDR',
            'item_count' => count($banks),
            'payload' => $banks,
        ]);
    }

    /**
     * 建一列「別的監測對象」的基準，範圍鍵與我方相同——共用表上這是完全合法的鄰居。
     *
     * @param  array<array-key, string>  $payload
     * @return int 該列 id
     */
    private function seedForeignBaseline(array $payload): int
    {
        $row = DriftBaseline::query()->create([
            'subject_key' => self::FOREIGN_SUBJECT_KEY,
            'scope_key' => (string) $this->acfId,
            'scope_label' => 'IDR',
            'item_count' => count($payload),
            'payload' => $payload,
        ]);

        return (int) $row->id;
    }

    /**
     * 本測試只看自己那個監測對象的列。
     *
     * 收攏成 helper 而不是各處自己寫條件：共用表上「全表 count()」的精確性是靠
     * 「碰巧只有一個監測對象」撐著的，第二個接進來就會變成不穩定測試。
     */
    private function ourBaselines(): Builder
    {
        return DriftBaseline::query()
            ->where('subject_key', DriftSubjectEnum::FPAY_WITHDRAW_BANK_LIST->value)
            ->where('scope_key', (string) $this->acfId);
    }

    /**
     * @param  array<array-key, string>  $banks  代號 => 銀行名稱
     */
    private function fakeBankList(array $banks): void
    {
        Http::fake([
            '*/wallet/withdraw_bank_list' => Http::response(['status' => true, 'data' => $this->bankListRows($banks)]),
        ]);
    }

    /**
     * 把「代號 => 銀行名稱」轉成 FPay 回應的形狀。
     *
     * @param  array<array-key, string>  $banks
     * @return list<array{currency: string, bank_name: string, id: string}>
     */
    private function bankListRows(array $banks): array
    {
        $rows = [];

        foreach ($banks as $code => $name) {
            $rows[] = ['currency' => 'IDR', 'bank_name' => $name, 'id' => (string) $code];
        }

        return $rows;
    }

    /**
     * @return array<array-key, string>
     */
    private function latestBaseline(): array
    {
        /** @var DriftBaseline $baseline */
        $baseline = $this->ourBaselines()->orderByDesc('id')->firstOrFail();

        return $baseline->payload;
    }
}
