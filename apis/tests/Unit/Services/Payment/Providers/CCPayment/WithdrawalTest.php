<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\CCPayment\Withdrawal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CCPayment 代付發起：幣與鏈的來源，以及網路費由誰負擔的旗標（MERCHANT_PAY_NETWORK_FEE）。
 *
 * 幣與鏈都取自會員所選銀行的三方代碼 {幣別代號}_{三方鏈代號}，coinId 執行期向 getCoinList 查；
 * 商戶 info 不放幣別。格式不符或三方後台沒啟用，一律在送出代付請求之前明確失敗。
 *
 * 預設會員負擔（金流商預設 false，到帳數量＝提現數量－網路費）；PM 定案後改金流商戶設定，不改代碼。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private Agent $agent;

    private Member $member;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'ccp_wd_' . uniqid()]);
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent, [
            'cash_flow_id' => 130,
            'info' => $this->info(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function info(array $extra = []): array
    {
        return array_merge([
            'API_URL' => self::API_URL,
            'APP_ID' => 'test_app_id',
            'APP_SECRET' => 'test_app_secret',
            'available_banks' => [
                ['BankCode_ELG' => 'USDT0001', 'BankCode_Third' => 'USDT_TRX'],
            ],
        ], $extra);
    }

    private function createWithdrawalOrder(): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'USDT0001',
            'bank_name' => 'USDT(TRC20)',
            'account_no' => 'TDestinationAddress',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    /**
     * 假的 getCoinList 回應（商戶後台啟用的代幣）：USDT（coinId 1280，TRX 鏈）與測試幣 TETH（coinId 1891，ETH_SEPOLIA 鏈）。
     *
     * @return array<string, mixed>
     */
    private function coinListBody(): array
    {
        return [
            'code' => 10000,
            'msg' => 'success',
            'data' => ['coins' => [
                ['coinId' => 1280, 'symbol' => 'USDT', 'status' => 'Normal', 'networks' => ['TRX' => ['chain' => 'TRX']]],
                ['coinId' => 1891, 'symbol' => 'TETH', 'status' => 'Normal', 'networks' => ['ETH_SEPOLIA' => ['chain' => 'ETH_SEPOLIA']]],
            ]],
        ];
    }

    private function payoutRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === self::API_URL . '/applyAppWithdrawToNetwork')->count();
    }

    private function coinListRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === self::API_URL . '/getCoinList')->count();
    }

    /**
     * 「請求沒送出、訂單可重送」的完整契約：明確失敗（不是結果未知）、訊息非空
     * （WithdrawalPayoutService::settleSyncResult() 以訊息是否為空判定已受理）、沒建 payout 記錄、沒打代付 API。
     */
    private function assertNotSentAndRetryable(WithdrawalResponseData $result): void
    {
        $this->assertFalse($result->isSuccess);
        $this->assertFalse($result->isIndeterminate, '請求沒送出不可判成結果未知，否則訂單會停留 21 等人工查證');
        $this->assertNotSame('', (string) $result->errorMessage);
        $this->assertSame(0, $this->payoutRequests());
        $this->assertSame(
            0,
            WithdrawalPayoutRecord::query()
                ->whereIn('withdrawal_order_id', WithdrawalOrder::query()->where('member_id', $this->member->id)->select('id'))
                ->count(),
            '送出前失敗不可建立 payout 記錄',
        );
    }

    private function fakeAccepted(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response($this->coinListBody()),
            self::API_URL . '/applyAppWithdrawToNetwork' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => ['recordId' => '2024031311310811'],
            ]),
        ]);
    }

    public function test_payout_request_defaults_to_member_paying_network_fee(): void
    {
        $this->fakeAccepted();

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertSame('', (string) $result->errorMessage);
        $this->assertTrue($result->isSuccess);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . '/applyAppWithdrawToNetwork'
            && str_contains($request->body(), '"merchantPayNetworkFee":false')
            && str_contains($request->body(), '"coinId":1280')
            && str_contains($request->body(), '"chain":"TRX"')
            && str_contains($request->body(), '"address":"TDestinationAddress"'));
    }

    public function test_coin_and_chain_come_from_the_provider_bank_code_not_merchant_info(): void
    {
        // Dev 測試網的配置：同一個提款銀行，三方代碼換成測試幣與測試鏈；商戶 info 不放幣別
        $this->agentCashFlow->info = $this->info([
            'available_banks' => [['BankCode_ELG' => 'USDT0001', 'BankCode_Third' => 'TETH_ETH_SEPOLIA']],
        ]);
        $this->agentCashFlow->save();
        $this->fakeAccepted();

        $result = (new Withdrawal($this->agentCashFlow->fresh()))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertTrue($result->isSuccess);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"coinId":1891')
            && str_contains($request->body(), '"chain":"ETH_SEPOLIA"'));
    }

    public function test_provider_bank_code_without_symbol_fails_before_any_payout_request(): void
    {
        // 舊格式只有鏈代號（TRX）：拆不出幣別，必須在送出前明確失敗，不可送出代付請求
        $this->agentCashFlow->info = $this->info([
            'available_banks' => [['BankCode_ELG' => 'USDT0001', 'BankCode_Third' => 'TRX']],
        ]);
        $this->agentCashFlow->save();
        $this->fakeAccepted();

        $result = (new Withdrawal($this->agentCashFlow->fresh()))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('is not in the form {symbol}_{chain}', (string) $result->errorMessage);
        $this->assertNotSentAndRetryable($result);
    }

    public function test_coin_not_enabled_on_chain_fails_before_any_payout_request(): void
    {
        // 三方後台沒替 USDT 啟用 ETH 鏈：不猜幣也不猜鏈，送出前明確失敗
        $this->agentCashFlow->info = $this->info([
            'available_banks' => [['BankCode_ELG' => 'USDT0001', 'BankCode_Third' => 'USDT_ETH']],
        ]);
        $this->agentCashFlow->save();
        $this->fakeAccepted();

        $result = (new Withdrawal($this->agentCashFlow->fresh()))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('coin USDT on chain ETH is not enabled', (string) $result->errorMessage);
        $this->assertNotSentAndRetryable($result);
    }

    public function test_coin_lookup_is_refetched_once_when_the_chain_is_missing(): void
    {
        // 快取裡找不到就清掉重拉一次再判定：一次失敗的出款固定打兩次 getCoinList，不會更多
        $this->agentCashFlow->info = $this->info([
            'available_banks' => [['BankCode_ELG' => 'USDT0001', 'BankCode_Third' => 'USDT_ETH']],
        ]);
        $this->agentCashFlow->save();
        $this->fakeAccepted();

        (new Withdrawal($this->agentCashFlow->fresh()))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertSame(2, $this->coinListRequests());
    }

    public function test_coin_list_failure_fails_before_any_payout_request(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response(['code' => 10000, 'msg' => 'success', 'data' => ['coins' => 'not-a-list']]),
            self::API_URL . '/applyAppWithdrawToNetwork' => Http::response(['code' => 10000, 'msg' => 'success', 'data' => ['recordId' => 'X']]),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertNotSentAndRetryable($result);
    }

    public function test_coin_list_connection_error_fails_before_any_payout_request(): void
    {
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response('gateway timeout', 504),
            self::API_URL . '/applyAppWithdrawToNetwork' => Http::response(['code' => 10000, 'msg' => 'success', 'data' => ['recordId' => 'X']]),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertNotSentAndRetryable($result);
    }

    public function test_symbol_with_two_coin_ids_is_refused_instead_of_picking_one(): void
    {
        // 同一個幣別代號回了兩個 coinId：挑錯就是出錯幣，整個代號拒用
        Http::fake([
            self::API_URL . '/getCoinList' => Http::response(['code' => 10000, 'msg' => 'success', 'data' => ['coins' => [
                ['coinId' => 1280, 'symbol' => 'USDT', 'status' => 'Normal', 'networks' => ['TRX' => ['chain' => 'TRX']]],
                ['coinId' => 9999, 'symbol' => 'USDT', 'status' => 'Normal', 'networks' => ['TRX' => ['chain' => 'TRX']]],
            ]]]),
            self::API_URL . '/applyAppWithdrawToNetwork' => Http::response(['code' => 10000, 'msg' => 'success', 'data' => ['recordId' => 'X']]),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertStringContainsString('maps to more than one coinId', (string) $result->errorMessage);
        $this->assertNotSentAndRetryable($result);
    }

    public function test_missing_credentials_fail_before_any_payout_request_even_with_warm_coin_cache(): void
    {
        // 先成功出一筆把幣鏈快取暖起來，再清掉 APP_SECRET：快取命中時 payoutSpec() 不發 HTTP，
        // 憑證缺漏若沒在前置檢核擋下，會拖到送出階段被當成結果未知
        $this->fakeAccepted();
        (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));
        $sentBefore = $this->payoutRequests();

        $this->agentCashFlow->info = $this->info(['APP_SECRET' => '']);
        $this->agentCashFlow->save();

        $result = (new Withdrawal($this->agentCashFlow->fresh()))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertFalse($result->isSuccess);
        $this->assertFalse($result->isIndeterminate);
        $this->assertStringContainsString('credentials not configured: APP_SECRET', (string) $result->errorMessage);
        $this->assertSame($sentBefore, $this->payoutRequests());
    }

    public function test_payout_request_lets_merchant_pay_network_fee_when_flag_enabled(): void
    {
        $this->agentCashFlow->info = $this->info(['MERCHANT_PAY_NETWORK_FEE' => true]);
        $this->agentCashFlow->save();
        $this->fakeAccepted();

        $result = (new Withdrawal($this->agentCashFlow->fresh()))->handle(new WithdrawalRequestData(order: $this->createWithdrawalOrder()));

        $this->assertTrue($result->isSuccess);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"merchantPayNetworkFee":true'));
    }
}
