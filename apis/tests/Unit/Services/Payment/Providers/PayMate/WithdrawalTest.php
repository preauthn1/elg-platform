<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayMate;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\PayMate\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayMate 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayMate/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    use PayMateSignatureHelper;

    protected Agent $agent;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    private const MERCHANT_NO = 'PM134126';

    private const MD5_KEY = 'c3f839790505a40973613d51bb8e2b8e';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);

        $info = [
            'API_URL' => 'https://api.paymate.test',
            'MERCHANT_NO' => self::MERCHANT_NO,
            'MD5_KEY' => self::MD5_KEY,
            'available_banks' => [
                ['BankCode_ELG' => 'MAYB', 'BankCode_Third' => 'MAYB', 'BankName' => 'Maybank'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 122,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($info),
        ]);
        $this->agentCashFlow->info = $info;
    }

    private function createWithdrawalOrder(): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'MAYB'], [
            'name' => '(MYR) - Maybank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => 0,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYB',
            'bank_name' => '(MYR) - Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    /**
     * 測試正常提交代付，建立成功響應
     */
    public function test_withdrawal_returns_success(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $innerResponse = [
            'code' => '0000',
            'msg' => '訂單建立成功',
            'amount' => '100.00',
            'orderId' => 'W' . $withdrawalOrder->id,
            'gbpOrderNo' => '190101185511102',
            'createTime' => '20260101120000',
        ];

        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope($innerResponse, self::MERCHANT_NO, self::MD5_KEY),
                200
            ),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $result = $withdrawal->handle($withdrawalRequestData);

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertSame('190101185511102', $result->providerOrderNo);

        // 同步受理：記錄維持 PENDING(0)、回填三方交易號，待回調確認才寫 SUCCESS
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('190101185511102', $record->third_party_transaction_no);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['merchantNo'] === self::MERCHANT_NO
                && isset($body['signMsg'], $body['request']);
        });
    }

    /**
     * 測試第三方回傳失敗，正確處理並回傳 FailureResponse
     */
    public function test_withdrawal_returns_failure_on_error_code(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $innerResponse = [
            'code' => '2001',
            'msg' => '快捷支付錯誤',
        ];

        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope($innerResponse, self::MERCHANT_NO, self::MD5_KEY),
                200
            ),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $result = $withdrawal->handle($withdrawalRequestData);

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('快捷支付錯誤', $result->errorMessage);

        // code 非 0000：三方同步拒絕 → THIRD_PARTY_REJECTED(-2)
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * 信封層 resCode 明確拒絕白名單（api.md §5.1 商戶/參數/密文檢驗，確定未進單；例 203 商戶停用中）
     * 且驗簽通過 → THIRD_PARTY_REJECTED(-2)、isIndeterminate=false（訂單轉 29 可重新發起，不得誤判為結果未知）
     */
    public function test_envelope_rejection_marks_third_party_rejected_not_indeterminate(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();

        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope([], self::MERCHANT_NO, self::MD5_KEY, resCode: '203', resMsg: '商戶停用中'),
                200
            ),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);
        $this->assertFalse($result->isIndeterminate);
        $this->assertStringContainsString('商戶停用中', $result->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * 信封 resCode=999（api.md §5.1 系統內部錯誤）＝發生在請求抵達之後，
     * 不能排除已建單 → REQUEST_FAILED(-1)、isIndeterminate=true（結果未知，訂單停留 21 待查證）
     */
    public function test_envelope_system_error_marks_request_failed_indeterminate(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();

        Http::fake([
            '*' => Http::response(
                $this->buildResponseEnvelope([], self::MERCHANT_NO, self::MD5_KEY, resCode: '999', resMsg: '系統內部錯誤'),
                200
            ),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);
        $this->assertTrue($result->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 信封拒絕但驗簽失敗＝來源不可信（WAF/中間層偽造可能），不得據此還原重送
     * → REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_envelope_rejection_with_invalid_signature_marks_request_failed_indeterminate(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();

        $envelope = $this->buildResponseEnvelope([], self::MERCHANT_NO, self::MD5_KEY, resCode: '203', resMsg: '商戶停用中');
        $envelope['signMsg'] = 'INVALID_SIGN';

        Http::fake(['*' => Http::response($envelope, 200)]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);
        $this->assertTrue($result->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 200 但 body 非 JSON／缺 resCode 鍵（WAF / CDN 挑戰頁）＝結構缺失
     * → REQUEST_FAILED(-1)、isIndeterminate=true（不得靜默落入內層分支判 -2）
     */
    public function test_unparseable_2xx_response_marks_request_failed_indeterminate(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();

        Http::fake(['*' => Http::response('<html>challenge</html>', 200)]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);
        $this->assertTrue($result->isIndeterminate);
        $this->assertStringContainsString('unparseable 2xx response', $result->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 信封 resCode=000（三方已受理層級成功）但內層 response 解不出（base64/JSON 壞）
     * ＝結果未知 → REQUEST_FAILED(-1)、isIndeterminate=true（不得判 -2 還原重送）
     */
    public function test_accepted_envelope_with_undecodable_payload_marks_request_failed_indeterminate(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();

        $fields = [
            'merchantNo' => self::MERCHANT_NO,
            'resCode' => '000',
            'resMsg' => 'success',
            'response' => 'not-valid-base64-json!!!',
            'version' => '1.0',
        ];
        $fields['signMsg'] = $this->paymateSign($fields, self::MD5_KEY);

        Http::fake(['*' => Http::response($fields, 200)]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);
        $this->assertTrue($result->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 回應驗簽失敗＝完整性存疑（結果未知）→ REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_signature_failure_marks_request_failed_indeterminate(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();

        $envelope = $this->buildResponseEnvelope(['code' => '0000'], self::MERCHANT_NO, self::MD5_KEY);
        $envelope['signMsg'] = 'INVALID_SIGN';

        Http::fake(['*' => Http::response($envelope, 200)]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);
        $this->assertTrue($result->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 非 2xx HTTP 錯誤（結果未知）→ REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_http_error_marks_request_failed_indeterminate(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();

        Http::fake(['*' => Http::response('', 502)]);

        $result = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);
        $this->assertTrue($result->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }
}
