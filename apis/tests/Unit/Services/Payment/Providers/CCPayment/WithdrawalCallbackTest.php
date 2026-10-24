<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\CCPayment;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Providers\CCPayment\WithdrawalCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CCPayment 代付回調：驗簽（與代收回調同一套）與回查後的三態定案。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/CCPayment/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private const API_URL = 'https://example.invalid/ccpayment/v2';

    private const APP_ID = 'test_app_id';

    private const APP_SECRET = [REDACTED]';

    private const TRANSACTION_NO = 'CCP20260915000001';

    private const RECORD_ID = '2024031311310811';

    private Agent $agent;

    private Member $member;

    private AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        BotNotify::shouldReceive('channel')->andReturnSelf()->byDefault();
        BotNotify::shouldReceive('send')->byDefault();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'ccp_wcb_' . uniqid()]);
        $this->agentCashFlow = $this->createAgentCashFlow($this->agent, [
            'cash_flow_id' => 130,
            'info' => [
                'API_URL' => self::API_URL,
                'APP_ID' => self::APP_ID,
                'APP_SECRET' => self::APP_SECRET,
            ],
        ]);
    }

    private function createProcessingOrderWithPayoutRecord(): WithdrawalOrder
    {
        $order = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'USDT0001',
            'bank_name' => 'USDT(TRC20)',
            'account_no' => 'TDestinationAddress',
            'account_name' => 'Test User',
            'amount' => '100.0000',
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => self::RECORD_ID,
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        WithdrawalPayoutRecord::create([
            'cash_flow_id' => 130,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => self::TRANSACTION_NO,
            'third_party_transaction_no' => self::RECORD_ID,
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
        ]);

        return $order;
    }

    /** @param array<string, mixed> $payload */
    private function signedRequest(array $payload, ?string $sign = null): Request
    {
        $rawBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $sign ??= hash_hmac('sha256', self::APP_ID . $timestamp . $rawBody, self::APP_SECRET);

        return Request::create(
            '/external/api/withdrawal/notify/' . $this->agentCashFlow->id,
            'POST',
            [],
            [],
            [],
            [
                'HTTP_APPID' => self::APP_ID,
                'HTTP_TIMESTAMP' => $timestamp,
                'HTTP_SIGN' => $sign,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawBody
        );
    }

    /** @return array<string, mixed> */
    private function payload(string $status = 'Success'): array
    {
        return [
            'type' => 'ApiWithdrawal',
            'msg' => [
                'recordId' => self::RECORD_ID,
                'orderId' => self::TRANSACTION_NO,
                'coinId' => 1280,
                'coinSymbol' => 'USDT',
                'status' => $status,
            ],
        ];
    }

    /** @param array<string, mixed> $fee */
    private function fakeRecord(string $status = 'Success', array $fee = ['coinId' => 1280, 'coinSymbol' => 'USDT', 'amount' => '1']): void
    {
        Http::fake([
            self::API_URL . '/getAppWithdrawRecord' => Http::response([
                'code' => 10000,
                'msg' => 'success',
                'data' => [
                    'record' => [
                        'recordId' => self::RECORD_ID,
                        'withdrawType' => 'Network',
                        'coinId' => 1280,
                        'coinSymbol' => 'USDT',
                        'chain' => 'TRX',
                        'toAddress' => 'TDestinationAddress',
                        'txId' => 'tx1',
                        'status' => $status,
                        'orderId' => self::TRANSACTION_NO,
                        'amount' => '100',
                        'fee' => $fee,
                    ],
                ],
            ]),
        ]);
    }

    public function test_rejects_callback_with_invalid_signature_before_touching_orders(): void
    {
        $order = $this->createProcessingOrderWithPayoutRecord();
        $this->fakeRecord();

        try {
            (new WithdrawalCallback($this->agentCashFlow))->handle($this->signedRequest($this->payload(), sign: 'bad-signature'));
            $this->fail('expected ApiException');
        } catch (ApiException) {
            Http::assertNothingSent();
            $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, (int) $order->fresh()->status);
        }
    }

    public function test_signed_success_callback_settles_order_after_query(): void
    {
        $order = $this->createProcessingOrderWithPayoutRecord();
        $this->fakeRecord('Success');

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->signedRequest($this->payload('Success')));

        $this->assertSame('Success', $result->response);
        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, (int) $order->fresh()->status);
    }

    public function test_success_callback_writes_network_fee_as_payment_cost(): void
    {
        // 成本費率設非 0，證明寫的是網路費，不是「提款金額 × 匯率 × 成本費率」
        $this->agentCashFlow->update(['cost' => 0.02]);
        $order = $this->createProcessingOrderWithPayoutRecord();
        $order->update(['rate' => '4.176']);
        $this->fakeRecord('Success');

        (new WithdrawalCallback($this->agentCashFlow))->handle($this->signedRequest($this->payload('Success')));

        $fresh = $order->fresh();
        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, (int) $fresh->status);
        $this->assertSame('4.1760', (string) $fresh->payment_cost); // 網路費 1 USDT × 4.176
    }

    public function test_network_fee_in_other_coin_alerts_and_falls_back_to_cost_rate(): void
    {
        $this->agentCashFlow->update(['cost' => 0.02]);
        $order = $this->createProcessingOrderWithPayoutRecord();
        $order->update(['rate' => '4.176']);
        $this->fakeRecord('Success', fee: ['coinId' => 1482, 'coinSymbol' => 'TRX', 'amount' => '1']);
        BotNotify::shouldReceive('send')->once();

        (new WithdrawalCallback($this->agentCashFlow))->handle($this->signedRequest($this->payload('Success')));

        $fresh = $order->fresh();
        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, (int) $fresh->status);
        $this->assertSame('8.3520', (string) $fresh->payment_cost); // 100 × 4.176 × 0.02
    }

    public function test_signed_processing_callback_keeps_order_processing(): void
    {
        $order = $this->createProcessingOrderWithPayoutRecord();
        $this->fakeRecord('Processing');

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->signedRequest($this->payload('Processing')));

        $this->assertSame('Success', $result->response);
        $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, (int) $order->fresh()->status);
    }
}
