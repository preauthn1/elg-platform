<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TopPay;

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
use App\Services\Payment\Providers\TopPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TopPay 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TopPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    protected Agent $agent;

    protected Currency $currency;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        // 初始化 Agent
        $this->agent = Agent::factory()->create();

        // 初始化 Currency
        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        // 初始化 Member
        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
        ]);

        // 初始化 AgentCashFlow
        $info = [
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'test_merchant',
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

    /**
     * 測試成功創建代付請求
     */
    public function test_success()
    {
        // Arrange
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
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'success' => true,
            'code' => '9999',
            'msg' => null,
            'timeStamp' => now()->getTimestampMs(),
            'data' => [
                'orderNum' => $withdrawalOrder->id,
                'platOrderNum' => 'TOPPAY20240101001',
                'amount' => 100000,
                'fee' => 2000,
                'status' => 1,
                'statusMsg' => 'ORDER_RECEIVED_PROCESSING',
            ],
        ];

        Http::fake([
            '*' => Http::response($mockResponse, 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertEquals('TOPPAY20240101001', $result->providerOrderNo);

        // 同步受理：記錄維持 PENDING，等回調確認，並已回填三方單號
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('TOPPAY20240101001', $record->third_party_transaction_no);

        // 驗證送往 TopPay 的 amount 為整數印尼盾且與訂單金額相等
        Http::assertSent(function ($request) use ($withdrawalOrder) {
            $body = $request->data();

            return $body['amount'] === (int) $withdrawalOrder->amount
                && is_int($body['amount']);
        });
    }

    /**
     * 測試 API 返回錯誤
     */
    public function test_api_error_response()
    {
        // Arrange
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
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'success' => false,
            'code' => '0001',
            'msg' => 'Invalid merchant code',
            'data' => null,
        ];

        Http::fake([
            '*' => Http::response($mockResponse, 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Invalid merchant code', $result->errorMessage);

        // 三方同步拒絕：記錄標記 THIRD_PARTY_REJECTED(-2)
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * 測試訂單不存在
     */
    public function test_order_not_found()
    {
        // Arrange
        $withdrawalRequestData = new WithdrawalRequestData; // 不存在的訂單

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('not found', $result->errorMessage);
    }

    /**
     * 測試 HTTP 連線錯誤
     */
    public function test_http_connection_error()
    {
        // Arrange
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
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
    }

    /**
     * 測試請求連線例外：記錄標記 REQUEST_FAILED(-1)
     */
    public function test_request_exception_marks_request_failed()
    {
        // Arrange
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
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }
}
