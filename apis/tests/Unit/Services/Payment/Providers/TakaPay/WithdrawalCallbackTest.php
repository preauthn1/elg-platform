<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TakaPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Exceptions\WithdrawalOrderException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\TakaPay\TakaPayStatusCodeEnum;
use App\Services\Payment\Providers\TakaPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * TakaPay 代付回調集成測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'test_merchant_code',
            'SECRET_KEY' => 'test_api_secret',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 90, // TakaPay 代付
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 19,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 生成有效的 TakaPay 簽名（使用真實簽名邏輯）
     */
    private function generateValidSign(array $params, string $secret, string $merchantCode = 'test_merchant_code'): string
    {
        $signFields = [
            $merchantCode,
            $params['status'] ?? '',
            $params['currencyCode'] ?? '',
            $params['amount'] ?? '',
            $params['reference'] ?? '',
            $params['transactionTime'] ?? '',
            $params['systemTransactionId'] ?? '',
        ];

        $signString = implode('', $signFields) . $secret;

        return md5($signString);
    }

    /**
     * 建立提款訂單
     */
    private function createWithdrawalOrder(string $amount, int $status, string $thirdPartyOrderNo = ''): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'bank_id' => 1,
            'bank_name' => 'bKash',
            'bank_code' => 'BDT0001',
            'account_name' => 'John Doe',
            'account_no' => '01771234567',
            'agent_group_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => $amount,
            'rate' => 1,
            'point' => 0,
            'tax' => 0,
            'third_party_order_no' => $thirdPartyOrderNo,
            'status' => $status,
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '',
        ]);
    }

    /**
     * 建立代付請求紀錄
     */
    private function createPayoutRecord(WithdrawalOrder $order, string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => 90,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
            'result_amount' => '0.00',
            'result_code' => '',
            'is_success_payout' => 0,
            'transaction_time' => '',
            'sign' => '',
            'payout_order_request_body' => '{}',
            'payout_order_response' => '{}',
            'callback_request_body' => '{}',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ], $overrides));
    }

    /**
     * 測試成功處理代付回調
     */
    public function test_success(): void
    {
        $transactionNo = 'WD20240123001';

        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '5000.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '5000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'WD20240123001',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('00000', $result->response['status']);
        $this->assertNotNull($result->order);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status); // 三方成功
        $this->assertEquals('WD20240123001', $withdrawalOrder->third_party_order_no);
    }

    /**
     * 測試簽名驗證失敗
     */
    public function test_invalid_signature(): void
    {
        $transactionNo = 'WD20240123002';

        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);

        $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '5000.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '5000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'WD20240123002',
            'securityToken' => 'INVALID_SIGNATURE',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試記錄不存在
     */
    public function test_record_not_found(): void
    {
        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '5000.00',
            'reference' => 'NON_EXISTENT_WD',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'WD99999999999',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $this->expectException(WithdrawalOrderException::class);
        $this->expectExceptionMessage('Payout record not found');
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試金額不匹配
     */
    public function test_amount_mismatch(): void
    {
        $transactionNo = 'WD20240123003';

        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '5000.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '10000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'WD20240123003',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $result = $withdrawalCallback->handle($request);

        $payoutRecord->refresh();
        $withdrawalOrder->refresh();

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('00000', $result->response['status']);
        $this->assertEquals(24, $withdrawalOrder->status); // 三方成功
        $this->assertEquals(TakaPayStatusCodeEnum::SUCCESS->value, $payoutRecord->result_code);
    }

    /**
     * 測試記錄已處理
     */
    public function test_record_already_processed(): void
    {
        $transactionNo = 'WD20240123004';

        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 24, 'WD20240123004');

        $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '5000.00',
            'is_success_payout' => 1,
            'third_party_transaction_no' => 'WD20240123004',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '5000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'WD20240123004_DUPLICATE',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('00000', $result->response['status']);
    }

    /**
     * 測試失敗狀態碼
     */
    public function test_failed_transaction(): void
    {
        $transactionNo = 'WD20240123005';

        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);

        $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '5000.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::FAILED_TRANSACTION->value,
            'currencyCode' => 'BDT',
            'amount' => '5000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'WD20240123005_FAILED',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $result = $withdrawalCallback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('00000', $result->response['status']);

        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status); // 三方失敗
    }

    /**
     * 測試成功回調所有數據庫字段更新 (WithdrawalPayoutRecord 和 WithdrawalOrder)
     */
    public function test_success_all_payout_fields_updated(): void
    {
        $transactionNo = 'WD20240123006';

        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '5000.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '5000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'WD20240123006',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $withdrawalCallback->handle($request);

        $payoutRecord->refresh();
        $withdrawalOrder->refresh();

        $this->assertNotEmpty($payoutRecord->payout_order_request_body);
        $this->assertNotNull($payoutRecord->callback_time);
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertEquals(TakaPayStatusCodeEnum::SUCCESS->value, $payoutRecord->result_code);
        $this->assertEquals('5000.0000', (string) $payoutRecord->result_amount);
        $this->assertNotNull($payoutRecord->transaction_time);
        $this->assertNotEmpty($payoutRecord->sign);
        $this->assertEquals('WD20240123006', $payoutRecord->third_party_transaction_no);

        $callbackBody = json_decode($payoutRecord->callback_request_body, true);
        $this->assertIsArray($callbackBody);
        $this->assertEquals('BDT', $callbackBody['currencyCode'] ?? null);
        $this->assertEquals('5000.00', $callbackBody['amount'] ?? null);

        $this->assertEquals(24, $withdrawalOrder->status); // 三方成功
    }

    /**
     * 測試失敗回調所有數據庫字段更新
     */
    public function test_failed_all_payout_fields_updated(): void
    {
        $transactionNo = 'WD20240123007';

        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '5000.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::FAILED_TRANSACTION->value,
            'currencyCode' => 'BDT',
            'amount' => '5000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'WD20240123007_FAILED',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $withdrawalCallback->handle($request);

        $payoutRecord->refresh();
        $withdrawalOrder->refresh();

        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertEquals(TakaPayStatusCodeEnum::FAILED_TRANSACTION->value, $payoutRecord->result_code);
        $this->assertNotEmpty($payoutRecord->payout_order_request_body);
        $this->assertNotNull($payoutRecord->callback_time);

        $this->assertEquals(29, $withdrawalOrder->status); // 三方失敗
    }

    /**
     * 測試回調更新 withdrawal_orders.third_party_order_no
     */
    public function test_callback_updates_withdrawal_order_third_party_no(): void
    {
        $transactionNo = 'WD20240123008';

        $withdrawalOrder = $this->createWithdrawalOrder('3000.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '3000.00',
        ]);

        $systemTxnId = 'TAKAPAY_SYS_TX_123456';

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '3000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => $systemTxnId,
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $withdrawalCallback->handle($request);

        $withdrawalOrder->refresh();
        $payoutRecord->refresh();

        $this->assertEquals($systemTxnId, $withdrawalOrder->third_party_order_no);
        $this->assertEquals($systemTxnId, $payoutRecord->third_party_transaction_no);
    }

    /**
     * 測試失敗回調時仍保存 payout_order_request_body
     */
    public function test_failed_callback_saves_request_body(): void
    {
        $transactionNo = 'WD20240123009';

        $withdrawalOrder = $this->createWithdrawalOrder('2000.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '2000.00',
            'payout_order_request_body' => '',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::FAILED_TRANSACTION->value,
            'currencyCode' => 'BDT',
            'amount' => '2000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'FAILED_TX_999',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $withdrawalCallback->handle($request);

        $payoutRecord->refresh();

        $this->assertNotNull($payoutRecord->callback_request_body);
        $this->assertStringContainsString('FAILED_TX_999', $payoutRecord->callback_request_body);
        $this->assertStringContainsString(TakaPayStatusCodeEnum::FAILED_TRANSACTION->value, $payoutRecord->callback_request_body);
    }

    /**
     * 測試回調保存支付商回傳的 transactionTime 到 transaction_time 字段
     *
     * @see commit 7a66de04 - 儲存支付商回傳的 transaction time
     */
    public function test_callback_saves_transaction_time_from_provider(): void
    {
        $transactionNo = 'WD20240123010';
        $providerTransactionTime = '2024-01-23 15:30:45';

        $withdrawalOrder = $this->createWithdrawalOrder('8000.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '8000.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '8000.00',
            'reference' => $transactionNo,
            'transactionTime' => $providerTransactionTime,
            'systemTransactionId' => 'TAKAPAY_TX_20240123001',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $withdrawalCallback->handle($request);

        $payoutRecord->refresh();

        // 驗證 transaction_time 字段保存了支付商回傳的時間
        $this->assertEquals($providerTransactionTime, $payoutRecord->transaction_time);
        $this->assertNotEquals(now()->toDateTimeString(), $payoutRecord->transaction_time);
    }

    /**
     * 測試失敗回調也會保存 transactionTime
     */
    public function test_failed_callback_also_saves_transaction_time(): void
    {
        $transactionNo = 'WD20240123011';
        $providerTransactionTime = '2024-01-23 16:45:20';

        $withdrawalOrder = $this->createWithdrawalOrder('3500.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '3500.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::FAILED_TRANSACTION->value,
            'currencyCode' => 'BDT',
            'amount' => '3500.00',
            'reference' => $transactionNo,
            'transactionTime' => $providerTransactionTime,
            'systemTransactionId' => 'TAKAPAY_FAILED_20240123001',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $withdrawalCallback->handle($request);

        $payoutRecord->refresh();

        // 驗證失敗的回調也會保存支付商的交易時間
        $this->assertEquals($providerTransactionTime, $payoutRecord->transaction_time);
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertEquals(29, $withdrawalOrder->fresh()->status);
    }

    /**
     * 測試中間態（PENDING_TRANSACTION=10014）保持 22 並回成功響應
     */
    public function test_indeterminate_keeps_22_and_acks(): void
    {
        $transactionNo = 'WD20240123012';

        $withdrawalOrder = $this->createWithdrawalOrder('4000.00', 22);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder, $transactionNo, [
            'result_amount' => '4000.00',
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::PENDING_TRANSACTION->value, // '10014' → INDETERMINATE
            'currencyCode' => 'BDT',
            'amount' => '4000.00',
            'reference' => $transactionNo,
            'transactionTime' => '2024-01-23 18:00:00',
            'systemTransactionId' => 'WD20240123012_PENDING',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);
        $result = $withdrawalCallback->handle($request);

        // 應回成功響應 ack 三方
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('00000', $result->response['status']);

        // 訂單 status 仍為 22
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        // payout record 不應被結算
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertEquals('', $payoutRecord->result_code);
    }
}
