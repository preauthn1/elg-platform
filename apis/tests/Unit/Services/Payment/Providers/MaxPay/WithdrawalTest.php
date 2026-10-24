<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\MaxPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\MaxPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MaxPay 代付請求測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        $currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://example.invalid',
            'API_KEY' => 'test_api_key',
            'SECRET_KEY' => 'test_secret_key',
            'CURRENCY' => 'SGD',
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'TBANK'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 180,
            'cash_flow_info_id' => 1,
            'currency_id' => $currency->id,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    public function test_success_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => ['transaction_reference' => 'MX-1']], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('MX-1', $record->third_party_transaction_no);
    }

    /**
     * PDF 記載的回應形狀：單號位於 result.transaction_reference（非 data.*），
     * 雙路徑容錯下同樣視為受理（見 api.md 差異說明，實際路徑待連線測試定案）
     */
    public function test_success_with_pdf_result_shape_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'result' => ['transaction_reference' => 'MX-2']], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('MX-2', $record->third_party_transaction_no);
    }

    /**
     * status=true（三方已受理）但回應缺 transaction_reference＝結構異常，
     * 不能排除已進單 → REQUEST_FAILED(-1)、isIndeterminate=true（結果未知，訂單停留 21 待查證）
     */
    public function test_missing_transaction_reference_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => []], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 200 但 body 非 JSON（WAF / CDN 挑戰頁）＝結構缺失，
     * 請求已送達、無依據斷定未受理 → REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_unparseable_2xx_response_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response('<html>challenge</html>', 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $this->assertStringContainsString('unparseable 2xx response', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 200 但 status 非布林（null）＝結構異常：文檔僅定義布林 true/false，
     * 無依據斷定未建單 → REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_non_boolean_status_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response(['status' => null, 'message' => 'malformed'], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $this->assertStringContainsString('unparseable 2xx response', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 三方業務拒絕（HTTP 200、status != true）＝同步明確失敗 →
     * THIRD_PARTY_REJECTED(-2)、isIndeterminate=false（訂單轉 29 可重新發起，不得誤判為結果未知）
     */
    public function test_business_rejection_marks_third_party_rejected_not_indeterminate(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'Insufficient balance'], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertFalse($response->isIndeterminate);
        $this->assertStringContainsString('Insufficient balance', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * 非 2xx HTTP 錯誤（結果未知）→ REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_http_error_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response('', 502)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    public function test_request_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }
}
