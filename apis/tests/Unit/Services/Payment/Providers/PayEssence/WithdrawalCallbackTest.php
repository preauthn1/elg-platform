<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayEssence;

use App\Enums\WithdrawalOrderStatusEnum;
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
use App\Services\Payment\Providers\PayEssence\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PayEssence 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'MERCHANT_CODE' => 'TEST_MERCHANT',
            'SECRET_KEY' => 'test_secret_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->save();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 生成有效的 CheckString2
     * CheckString2 = SHA256(MerchantCode + SecretKey + TransNum + PayoutId + ToBankId + ToBankAccountNum + CurrencyTypeId + Amount)
     */
    private function generateCheckString2(
        string $merchantCode,
        string $secretKey,
        string $transNum,
        string $payoutId,
        string $toBankId,
        string $toBankAccountNum,
        string $currencyTypeId,
        string $amount
    ): string {
        $concatString = $merchantCode . $secretKey . $transNum . $payoutId . $toBankId . $toBankAccountNum . $currencyTypeId . $amount;

        return hash('sha256', $concatString);
    }

    /**
     * 創建測試用的 WithdrawalPayoutRecord
     */
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

    /**
     * 測試成功處理代付回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PE123456789';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $payoutId = 'PO2024012300001';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $amount = '100.00';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transactionNo,
            'PayoutId' => $payoutId,
            'Amount' => $amount,
            'Result' => '35002', // Success
            'CheckString2' => $this->generateCheckString2(
                $merchantCode,
                $secretKey,
                $transactionNo,
                $payoutId,
                $toBankId,
                $toBankAccountNum,
                $currencyTypeId,
                $amount
            ),
            'CurrencyTypeId' => $currencyTypeId,
            'ToBankId' => $toBankId,
            'ToBankAccountNum' => $toBankAccountNum,
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('success', $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->status);

        // 驗證代付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertEquals(100.00, (float) $payoutRecord->result_amount);
    }

    /**
     * 測試代付失敗回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_failure_callback
     */
    public function test_failure_callback()
    {
        // Arrange
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PE123456790';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $payoutId = 'PO2024012300002';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $amount = '100.00';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transactionNo,
            'PayoutId' => $payoutId,
            'Amount' => $amount,
            'Result' => '35003', // Rejected
            'CheckString2' => $this->generateCheckString2(
                $merchantCode,
                $secretKey,
                $transactionNo,
                $payoutId,
                $toBankId,
                $toBankAccountNum,
                $currencyTypeId,
                $amount
            ),
            'CurrencyTypeId' => $currencyTypeId,
            'ToBankId' => $toBankId,
            'ToBankAccountNum' => $toBankAccountNum,
            'Remarks' => 'Account invalid',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新為失敗狀態
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->status);

        // 驗證代付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * 測試無效的商戶代碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_invalid_merchant_code
     */
    public function test_invalid_merchant_code()
    {
        // Arrange
        $callbackData = [
            'MerchantCode' => 'WRONG_MERCHANT',
            'TransNum' => 'PE123456791',
            'PayoutId' => 'PO2024012300003',
            'Amount' => '100.00',
            'Result' => '35002',
            'CheckString2' => 'some_hash',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid merchant code in callback');

        // Act
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試 CheckString2 驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_invalid_checkstring2
     */
    public function test_invalid_checkstring2()
    {
        // Arrange
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PE123456792';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'MerchantCode' => 'TEST_MERCHANT',
            'TransNum' => $transactionNo,
            'PayoutId' => 'PO2024012300004',
            'Amount' => '100.00',
            'Result' => '35002',
            'CheckString2' => 'invalid_checkstring2',
            'CurrencyTypeId' => '1',
            'ToBankId' => 'MBB',
            'ToBankAccountNum' => '1234567890',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('CheckString2 verification failed');

        // Act
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試金額不匹配
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_amount_mismatch
     */
    public function test_amount_mismatch()
    {
        // Arrange
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PE123456793';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $payoutId = 'PO2024012300005';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $wrongAmount = '150.00'; // 金額不匹配

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transactionNo,
            'PayoutId' => $payoutId,
            'Amount' => $wrongAmount,
            'Result' => '35002',
            'CheckString2' => $this->generateCheckString2(
                $merchantCode,
                $secretKey,
                $transactionNo,
                $payoutId,
                $toBankId,
                $toBankAccountNum,
                $currencyTypeId,
                $wrongAmount
            ),
            'CurrencyTypeId' => $currencyTypeId,
            'ToBankId' => $toBankId,
            'ToBankAccountNum' => $toBankAccountNum,
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 金額不符時應返回成功響應
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('success', $result->response);

        // 驗證訂單狀態已更新為成功
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->status);

        // 驗證代付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertEquals('150.0000', $payoutRecord->result_amount);
    }

    /**
     * 測試代付記錄不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_payout_record_not_found
     */
    public function test_payout_record_not_found()
    {
        // Arrange
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transactionNo = 'PE999999999'; // 不存在的交易號
        $payoutId = 'PO2024012300006';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $amount = '100.00';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transactionNo,
            'PayoutId' => $payoutId,
            'Amount' => $amount,
            'Result' => '35002',
            'CheckString2' => $this->generateCheckString2(
                $merchantCode,
                $secretKey,
                $transactionNo,
                $payoutId,
                $toBankId,
                $toBankAccountNum,
                $currencyTypeId,
                $amount
            ),
            'CurrencyTypeId' => $currencyTypeId,
            'ToBankId' => $toBankId,
            'ToBankAccountNum' => $toBankAccountNum,
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('fail', $result->response);
    }

    /**
     * 測試代付記錄已處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_payout_record_already_processed
     */
    public function test_payout_record_already_processed()
    {
        // Arrange
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, // 已成功
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PE123456794';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo, [
            'is_success_payout' => 1, // 已處理
            'result_code' => '35002',
        ]);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $payoutId = 'PO2024012300007';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $amount = '100.00';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transactionNo,
            'PayoutId' => $payoutId,
            'Amount' => $amount,
            'Result' => '35002',
            'CheckString2' => $this->generateCheckString2(
                $merchantCode,
                $secretKey,
                $transactionNo,
                $payoutId,
                $toBankId,
                $toBankAccountNum,
                $currencyTypeId,
                $amount
            ),
            'CurrencyTypeId' => $currencyTypeId,
            'ToBankId' => $toBankId,
            'ToBankAccountNum' => $toBankAccountNum,
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('success', $result->response);
    }

    /**
     * 測試缺少配置
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_missing_configuration
     */
    public function test_missing_configuration()
    {
        // Arrange
        $info = [];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $callbackData = [
            'MerchantCode' => 'TEST_MERCHANT',
            'TransNum' => 'PE123456795',
            'PayoutId' => 'PO2024012300008',
            'Amount' => '100.00',
            'Result' => '35002',
            'CheckString2' => 'some_hash',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('PayEssence configuration missing');

        // Act
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試中間態（35001 Pending）保持 22 並回成功響應
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalCallbackTest.php --filter test_indeterminate_keeps_22_and_acks
     */
    public function test_indeterminate_keeps_22_and_acks(): void
    {
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PE123456800';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $merchantCode = 'TEST_MERCHANT';
        $payoutId = 'PO2024012300020';
        $toBankId = 'MBB';
        $toBankAccountNum = '1234567890';
        $currencyTypeId = '1';
        $amount = '100.00';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transactionNo,
            'PayoutId' => $payoutId,
            'Amount' => $amount,
            'Result' => '35001', // Pending → INDETERMINATE
            'CheckString2' => 'dummy_not_verified_for_non_success',
            'CurrencyTypeId' => $currencyTypeId,
            'ToBankId' => $toBankId,
            'ToBankAccountNum' => $toBankAccountNum,
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);
        $result = $withdrawalCallback->handle($request);

        // 應回成功響應 ack 三方（CheckString2 只在 35002 成功時才驗證）
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('success', $result->response);

        // 訂單 status 仍為 22
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->status);

        // payout record 不應被結算
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertEquals('', $payoutRecord->result_code);
    }
}
