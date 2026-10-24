<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\A3Pay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\A3Pay\Withdrawal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A3Pay 代付請求測試：例外分類以 HTTP 狀態碼分流——
 * 4xx＋isSuccess=false 才是明確拒絕（-2、轉 29）；5xx／非 JSON／2xx 卻 isSuccess=false／受理無單號一律結果未知（-1、停 21）。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/A3Pay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    use A3PayCryptoHelpers;

    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();
        $this->generateRsaKeyPair();

        $info = $this->buildInfo([
            'available_banks' => [
                ['BankCode_ELG' => 'DBSSG', 'BankCode_Third' => 'SDBS', 'BankName' => '(SGD) - DBS Bank Limited'],
            ],
        ]);

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 132,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 1,
        ]);
        $this->agentCashFlow->info = $info;

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_code' => 'DBSSG',
            'bank_name' => 'DBS Bank Limited',
            'account_no' => '0123456789',
            'account_name' => 'Tan Ah Kow',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    private function record(): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
    }

    public function test_accepted_keeps_record_pending_and_sends_payout_fields(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true, 'successCode' => 200, 'errorMessage' => '', 'errorList' => null,
            'data' => ['paymentOrderId' => 'W2026092200000001'],
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = $this->record();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('W2026092200000001', $record->third_party_transaction_no);
        $this->assertStringStartsWith('A3PAY', $record->transaction_no);

        Http::assertSent(function (Request $request) use ($record): bool {
            $data = json_decode($this->aesDecrypt($request->data()['data']), true);
            $this->assertSame('SGD00001', $data['MID']);
            $this->assertSame($record->transaction_no, $data['MTransactionId']);
            $this->assertSame('100.00', $data['PayoutAmount']);
            $this->assertSame('SGD', $data['CurrCode']);
            $this->assertSame('SDBS', $data['ToClientBankCode']);
            $this->assertSame('0123456789', $data['ToClientAccountNumber']);
            $this->assertSame('Tan Ah Kow', $data['ToClientAccountHolderName']);
            $this->assertSame('WD', $data['PaymentMethodId']);
            $this->assertArrayHasKey('CallBackURL', $data);
            $this->assertArrayHasKey('ReturnClientURL', $data);

            return str_ends_with($request->url(), '/api/Payout/SubmitPayout');
        });
    }

    /** HTTP 400＋白名單碼（2026-09-24 實測形狀）：三方受理前拒絕，可證未建單 → -2 */
    public function test_http_400_with_whitelisted_code_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => false, 'successCode' => 0, 'errorCode' => 'PMT10013', 'errorMessage' => 'Insufficient Balance.', 'errorList' => null, 'data' => ['transactionId' => null],
        ], 400)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame('Insufficient Balance.', $response->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $this->record()->is_success_payout);
    }

    /** HTTP 400 但 errorCode 不在白名單：文件沒列、不能證明未建單 → 結果未知 -1 */
    public function test_http_400_with_unknown_code_is_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => false, 'successCode' => 0, 'errorCode' => 'PMT99999', 'errorMessage' => 'Something else', 'errorList' => null, 'data' => ['transactionId' => null],
        ], 400)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $this->record()->is_success_payout);
    }

    /** HTTP 200 卻 isSuccess=false：文件未定義此形狀，不能證明未建單 → 結果未知 -1 */
    public function test_http_200_with_is_success_false_is_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => false, 'successCode' => 500, 'errorMessage' => 'System busy',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $this->record()->is_success_payout);
    }

    public function test_http_500_is_indeterminate(): void
    {
        Http::fake(['*' => Http::response('<html>502 Bad Gateway</html>', 502)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $this->record()->is_success_payout);
    }

    /** isSuccess=true 卻沒有 paymentOrderId：三方說已受理，判拒絕會轉 29 重送，是資損向 */
    public function test_accepted_without_payment_order_id_is_indeterminate(): void
    {
        Http::fake(['*' => Http::response(['isSuccess' => true, 'data' => []], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $this->record()->is_success_payout);
    }

    public function test_request_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $this->record()->is_success_payout);
    }

    /** 銀行碼對不到：請求不發出、不建 payout 記錄 */
    public function test_unmapped_bank_code_fails_before_request(): void
    {
        Http::fake();
        $this->order->update(['bank_code' => 'NOT_MAPPED']);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('bank code not mapped', $response->errorMessage);
        $this->assertSame(0, WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->count());
        Http::assertNothingSent();
    }

    /** 2xx 但 body 不是 JSON（WAF／CDN 挑戰頁）：結構缺失不能證明未建單 → 結果未知 -1 */
    public function test_http_200_non_json_body_is_indeterminate(): void
    {
        Http::fake(['*' => Http::response('<html>Just a moment...</html>', 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $this->record()->is_success_payout);
    }

    /** 三方回應可能用舊鍵 transactionId 而非文件的 paymentOrderId，兩者都要認成受理 */
    public function test_accepted_with_legacy_transaction_id_key(): void
    {
        Http::fake(['*' => Http::response(['isSuccess' => true, 'data' => ['transactionId' => 'W2026092400000009']], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = $this->record();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('W2026092400000009', $record->third_party_transaction_no);
    }

    /** 真實受理回應（dev 提款單 705） */
    public function test_real_accepted_response_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response(A3PayRealPayloads::PAYOUT_ACCEPTED, 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = $this->record();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('W2026092400000014', $record->third_party_transaction_no);
    }

    /** 真實拒絕回應（dev 提款單 700，HTTP 400 PMT10013）：白名單碼 → -2 */
    public function test_real_pmt10013_rejection_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response(A3PayRealPayloads::PAYOUT_REJECTED_PMT10013, 400)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame('Insufficient Balance.', $response->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $this->record()->is_success_payout);
    }
}
