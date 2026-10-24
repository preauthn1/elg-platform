<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TopPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\TopPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * TopPay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TopPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    protected Agent $agent;

    protected Currency $currency;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
        ]);

        $info = [
            'API_URL' => 'https://example.invalid/sandbox/id',
            'MERCHANT_CODE' => 'SD123456',
            'MERCHANT_PRIVATE_KEY' => 'MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDJq4sgb3F/WsmbuDKZw7xyJWwke9l/RFmPed+pgDLxnhdSHJ2b1zUgMCxqA/Pxs+owD2UvQheVCg1XSHXkBciaqv+w9daJZgf3BerqE3UDUIj4iFITbkREPM70zMKZMFEr4NI1p2VZk2HzXPoWSdU9EdIL5gC99Sifw3q9H9VflvDl0FXdht/QYJnKfW+9cRVLMLnZFDDRtgrhteMmpEv61W42fFagUpViLJfkVo4K7biqv34zmNtwV/njxy+HV36fFZH+ElpahYP+Q9fXn1PdO0QZLICij8xfYdv4VBnMjQIKlf7HwqUlU4/SFfpYp+imjts9r2oKGceXvX6t6+6nAgMBAAECggEALgSVXiTf7rIeQAjDjx/BgAB3EUorgqVrlFg4h7qHpWewJZ3+/uEYL8yeTeCyCQzHWwkgSyzXKbyd97VCpXIGaPitGMm4FykvlxgEFz6yXDvDzYZ5yBJIxJNlnCmfHAqQ8yS9gt28PDjryHcGYBCf9mv7FJj6wV3QU8hs2PwLGeD049qPJBBhJYOusm6U+aX6ZnDcjAYK4S1tZdB/nSGOsfc3zvsRs6Iwp3jP27lVCJLkyM6opzhOqDLP7G7tS0oz9cLY++HY4Xn0qKK30a34Nm5WK9qb0Tq9RyEbDqYMDfzsBlvdSxKzCRtsib28TAPu3zD6Iiu0ydqtGtxndE5jXQKBgQD4RDv67A4egE3aVPAZI5mBKSe/T6sZ4zuEckxNbV2yxnWza9yXkhLerSS4GtCIbzC+h5MHYsKu/Y0lBaElxMhbM/NfxwlF8YMNRU2juKl/OfoF+dZ7oOp4w6W1ZpySZiA27YpcjPxL8MJ5pzVzS+GsBlwkIlgAf3vanl36X0mGLQKBgQDP87uFzlxyKIGsOXg5XfQY7ovifuO8xdETUSyZ4Z8SAGUTE8YbH+IEynSLwFyf7x/FW3pLY1IzLznKyg3NiHec9guyuGdQJgcBpZpLbf3rxnMooqOvBcMJKW10Cym5O1IdJkY1/5QuOU8tcimqg+yk8cDwhSDoSVgkdqE0OzmAowKBgDEgD15PZ1vRt5YESJ4w1SchpaPNd+XfeN90XnTTtz6w1olyRmwXk3RMfn+PjsPwfIy29vj3Kd7GfS9eNczra1ngpb/F+qfEalIt7HcBC0kJh9e24CIGbV8IF7oVW9X8L1OvHTnDDAla0qCP3Zu64CTR/VmJEjDHCRLxU1c/k6ThAoGACD8rNaA8bE6M7e6NhQDj2EVFiOLtVyQlKXXzpdqbu1QGrh3/oj2+ceAxhQVI5xLNmL8K94hlzx/VU+R1baw2yiO7zQehGT2Y04jkuI8RHdON55OjmCJLXMamz45NxknC9OL6r9OF5dth93RqkJeQv+3Sb/dnma6m7zNBK1gZ7K8CgYBausfxv0LkKHs9wZJ3OoDHAM8Eibs55kVq4bDBeWIQXuRPJgglzUYSZ55JWH/bG22PFlrk4VWtPPS7ti3rrkyYqEGxdlAKydx/g2j0K4yal/zDJHE9ibVDmcvtItvTW4Ju47qvkqbIkZ6DeQo4GggoCET01Y+wO1VVPl/2uDfmbg==',
            'PLATFORM_PUBLIC_KEY' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAyauLIG9xf1rJm7gymcO8ciVsJHvZf0RZj3nfqYAy8Z4XUhydm9c1IDAsagPz8bPqMA9lL0IXlQoNV0h15AXImqr/sPXWiWYH9wXq6hN1A1CI+IhSE25ERDzO9MzCmTBRK+DSNadlWZNh81z6FknVPRHSC+YAvfUon8N6vR/VX5bw5dBV3Ybf0GCZyn1vvXEVSzC52RQw0bYK4bXjJqRL+tVuNnxWoFKVYiyX5FaOCu24qr9+M5jbcFf548cvh1d+nxWR/hJaWoWD/kPX159T3TtEGSyAoo/MX2Hb+FQZzI0CCpX+x8KlJVOP0hX6WKfopo7bPa9qChnHl71+revupwIDAQAB',
            'available_banks' => [],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'admin' => 0,
            'info' => json_encode($info),
        ]);

        $this->agentCashFlow->info = $info;
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => 'DEFAULT_TPO_' . $transactionNo,
            'result_amount' => 0,
            'result_code' => '',
            'is_success_payout' => 0,
            'transaction_time' => '',
            'sign' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'callback_request_body' => '',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ], $overrides));
    }

    private function createWithdrawalOrder(): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    private function makeCallback(string $transactionNo, int $status, string $amount = '100000'): WithdrawalCallbackResponseData
    {
        $callbackData = [
            'platOrderNum' => 'TRANS_TEST',
            'version' => 'v1',
            'orderNum' => $transactionNo,
            'amount' => $amount,
            'fee' => '0',
            'status' => $status,
            'sign' => 'test_signature',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new class($this->agentCashFlow) extends WithdrawalCallback
        {
            protected function verifySign(array $params): bool
            {
                return true;
            }
        };

        return $withdrawalCallback->handle($request);
    }

    /**
     * 測試成功處理代付回調（status = 2）
     */
    public function test_success(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'platOrderNum' => 'TRANS2008811496544403456',
            'version' => 'v1',
            'orderNum' => $transactionNo,
            'amount' => '100000',
            'fee' => '2000',
            'feeType' => 1,
            'status' => 2,           // 撥款成功
            'statusMsg' => 'supplement success!',
            'bankCode' => '014',
            'bankCard' => '1234567890',
            'accountName' => 'Test User',
            'sign' => 'test_signature',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new class($this->agentCashFlow) extends WithdrawalCallback
        {
            protected function verifySign(array $params): bool
            {
                return true;
            }
        };

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);
        $this->assertNotNull($result->order);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status); // 三方成功

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertEquals('2', $payoutRecord->result_code);
    }

    /**
     * 測試簽名驗證失敗
     */
    public function test_signature_verification_failed(): void
    {
        $callbackData = [
            'platOrderNum' => 'TRANS2008811496544403456',
            'version' => 'v1',
            'orderNum' => 'TRN12345',
            'amount' => '100000',
            'status' => 2,
            'sign' => 'invalid_signature',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new class($this->agentCashFlow) extends WithdrawalCallback
        {
            protected function verifySign(array $params): bool
            {
                return false;
            }
        };

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試缺少必要欄位
     */
    public function test_missing_required_fields(): void
    {
        // 缺少 amount, status, sign
        $callbackData = [
            'platOrderNum' => 'TRANS2008811496544403456',
            'orderNum' => '12345',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new class($this->agentCashFlow) extends WithdrawalCallback
        {
            protected function verifySign(array $params): bool
            {
                return true;
            }
        };

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試代付記錄不存在
     */
    public function test_withdrawal_order_not_found(): void
    {
        $callbackData = [
            'platOrderNum' => 'TRANS999',
            'version' => 'v1',
            'orderNum' => 'NONEXISTENT999',
            'amount' => '100000',
            'status' => 2,
            'sign' => 'test_signature',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new class($this->agentCashFlow) extends WithdrawalCallback
        {
            protected function verifySign(array $params): bool
            {
                return true;
            }
        };

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
    }

    /**
     * 測試代付失敗回調（status = 3）
     */
    public function test_failure_callback(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'platOrderNum' => 'TRANS2008811496544403457',
            'version' => 'v1',
            'orderNum' => $transactionNo,
            'amount' => '100000',
            'fee' => '0',
            'status' => 3,           // 失敗
            'statusMsg' => 'Failed',
            'bankCode' => '014',
            'bankCard' => '1234567890',
            'accountName' => 'Test User',
            'sign' => 'test_signature',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new class($this->agentCashFlow) extends WithdrawalCallback
        {
            protected function verifySign(array $params): bool
            {
                return true;
            }
        };

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status); // 三方失敗

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertEquals('3', $payoutRecord->result_code);
    }

    /**
     * 測試金額不匹配
     */
    public function test_amount_mismatch(): void
    {
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'platOrderNum' => 'TRANS2008811496544403458',
            'version' => 'v1',
            'orderNum' => $transactionNo,
            'amount' => '50000',     // 金額不匹配
            'status' => 2,           // 成功但金額不符
            'sign' => 'test_signature',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new class($this->agentCashFlow) extends WithdrawalCallback
        {
            protected function verifySign(array $params): bool
            {
                return true;
            }
        };

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status); // 三方成功
    }

    /**
     * 測試三態：status=0/1/4（處理中）→ 保持22，回傳成功響應
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pendingStatusProvider')]
    public function test_indeterminate_keeps_status_22(int $pendingStatus): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN_INDET_' . $pendingStatus . '_' . $withdrawalOrder->id;
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $result = $this->makeCallback($transactionNo, $pendingStatus);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status); // 維持22，未結算
    }

    public static function pendingStatusProvider(): array
    {
        return [
            'status=0 處理中' => [0],
            'status=1 處理中' => [1],
            'status=4 退款處理中（非終態）' => [4],
        ];
    }

    /**
     * 測試三態：status=5（退款已完成，終態失敗）→ 29
     */
    public function test_refund_completed_status5_maps_to_failure(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN_STATUS5_' . $withdrawalOrder->id;
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $result = $this->makeCallback($transactionNo, 5);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status); // 三方失敗
    }
}
