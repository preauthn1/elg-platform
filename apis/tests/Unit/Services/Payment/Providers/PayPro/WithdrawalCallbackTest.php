<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayPro;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\PayPro\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PayPro 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php
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
            'API_URL' => 'https://sandbox-api.paypro.tech/indonesia-pay',
            'MERCHANT_NO' => '0000000001',
            'MERCHANT_KEY' => 'test_merchant_key',
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
     * 生成有效的簽名
     */
    private function generateValidSign(array $params, string $merchantKey): string
    {
        // 排除 sign 欄位
        unset($params['sign']);

        // 過濾空值
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // 按首字母 a-z 排序
        ksort($filtered);

        // 拼接成 key1=value1key2=value2...
        $signString = '';
        foreach ($filtered as $key => $value) {
            $signString .= $key . '=' . $value;
        }

        // 使用 merchant key 作為 salt
        $signString .= $merchantKey;

        // MD5 加密並轉小寫
        return strtolower(md5($signString));
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
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_success
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
            'status' => 22, // 代付處理中
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PayPro2026020308342212734';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0000', // 成功
            'tradeNo' => $transactionNo,
            'message' => 'Mock success status',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_da',
            'amount' => 10000,
            'successTime' => '2026-02-03 16:40:32',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新為成功狀態
        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status); // 三方成功

        // 驗證代付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        // result_amount 可能被格式化為 '10000.0000'，檢查數值是否相等
        $this->assertEquals(10000, (float) $payoutRecord->result_amount);
        $this->assertEquals('0000', $payoutRecord->result_code);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_signature_verification_failed
     */
    public function test_signature_verification_failed()
    {
        // Arrange
        $callbackData = [
            'sign' => 'invalid_signature',
            'timestamp' => '1770108032294',
            'status' => '0000',
            'tradeNo' => 'PayPro2026020308342212735',
            'message' => 'Mock success status',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_db',
            'amount' => 10000,
            'successTime' => '2026-02-03 16:40:32',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid signature');
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試缺少必要欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_missing_required_fields
     */
    public function test_missing_required_fields()
    {
        // Arrange
        $callbackData = [
            'tradeNo' => 'PayPro2026020308342212736',
            // 缺少 platFormTradeNo, amount, status, sign 等必要欄位
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act & Assert - 應該拋出驗證異常
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試代付記錄不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_payout_record_not_found
     */
    public function test_payout_record_not_found()
    {
        // Arrange
        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0000',
            'tradeNo' => 'NONEXISTENT',
            'message' => 'Mock success status',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_dc',
            'amount' => 10000,
            'successTime' => '2026-02-03 16:40:32',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 記錄不存在時返回 FAIL
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
    }

    /**
     * 測試代付失敗回調（doc-literal：非 0000/0015 一律 → 29）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_failure_callback
     */
    public function test_failure_callback()
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
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PayPro2026020308342212737';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0002', // 失敗（doc-literal：非 0000/0015 → FAILURE）
            'tradeNo' => $transactionNo,
            'message' => 'Payment failed',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_dd',
            'amount' => 10000,
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert — doc-literal：非 0000/0015 → FAILURE，訂單標 29
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 訂單 status 應更新為 29（三方失敗終態）
        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        // payout record 應被結算（is_success_payout=0，result_code 寫入）
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertEquals('0002', $payoutRecord->result_code);
    }

    /**
     * 測試處理中狀態（0015）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_processing_status
     */
    public function test_processing_status()
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
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PayPro2026020308342212738';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0015', // 處理中
            'tradeNo' => $transactionNo,
            'message' => 'Processing',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_de',
            'amount' => 10000,
            'successTime' => '2026-02-03 16:40:32',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert — 0015 不在成功集也不在失敗集，落 INDETERMINATE 早退不寫 DB
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 訂單 status 仍為 22
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        // 早退不寫 payout record，result_code 保持為空
        $payoutRecord->refresh();
        $this->assertEquals('', $payoutRecord->result_code);
    }

    /**
     * 測試金額不匹配
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_amount_mismatch
     */
    public function test_amount_mismatch()
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
            'amount' => 100000, // 訂單金額
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PayPro2026020308342212739';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo, [
            'result_amount' => '100000.0000',
        ]);

        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0000', // 成功（但金額不符）
            'tradeNo' => $transactionNo,
            'message' => 'Success',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_df',
            'amount' => 50000, // 金額不匹配
            'successTime' => '2026-02-03 16:40:32',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 金額不匹配時不會拋出異常，只會記錄警告，但會正常處理回調
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 驗證代付記錄已更新（金額不匹配但狀態仍會更新）
        $payoutRecord->refresh();
        $this->assertEquals('0000', $payoutRecord->result_code);
    }

    /**
     * 測試代付記錄已處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_payout_record_already_processed
     */
    public function test_payout_record_already_processed()
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
            'status' => 24, // 已成功
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'PayPro2026020308342212740';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo, [
            'is_success_payout' => 1, // 已處理
        ]);

        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0000',
            'tradeNo' => $transactionNo,
            'message' => 'Mock success status',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_dg',
            'amount' => 10000,
            'successTime' => '2026-02-03 16:40:32',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 已處理的記錄應該返回成功響應
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_withdrawal_order_not_found
     */
    public function test_withdrawal_order_not_found()
    {
        // Arrange
        $transactionNo = 'PayPro2026020308342212741';
        // 創建一個不關聯任何訂單的記錄
        $payoutRecord = WithdrawalPayoutRecord::create([
            'cash_flow_id' => 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => 999999, // 不存在的訂單 ID
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
        ]);

        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0000',
            'tradeNo' => $transactionNo,
            'message' => 'Mock success status',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_dh',
            'amount' => 10000,
            'successTime' => '2026-02-03 16:40:32',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 訂單不存在時返回 FAIL
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
    }

    /**
     * 測試中間態（0015 處理中）保持 22 並回成功響應
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalCallbackTest.php --filter test_indeterminate_keeps_22_and_acks
     */
    public function test_indeterminate_keeps_22_and_acks(): void
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

        $transactionNo = 'PayPro2026020308342219999';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0015', // 處理中 → INDETERMINATE
            'tradeNo' => $transactionNo,
            'message' => 'Processing',
            'platFormTradeNo' => 'Y000ewzvrwsyivl7rgjx_zz',
            'amount' => 10000,
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);
        $result = $withdrawalCallback->handle($request);

        // 應回成功響應 ack 三方
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 訂單 status 仍為 22
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        // payout record 不應被結算（早退不寫 DB）
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertEquals('', $payoutRecord->result_code);
    }

    /**
     * 空憑證守衛：MERCHANT_KEY 為空時 generateSign() 的 salt 為空、簽名可離線計算，
     * 攻擊者能偽造回調洗訂單。守衛須在驗簽前先擋下（拋 Invalid signature），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
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
        $transactionNo = 'PAYPRO_WD_EMPTY';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $emptyInfo = [
            'API_URL' => 'https://sandbox-api.paypro.tech/indonesia-pay',
            'MERCHANT_NO' => '0000000001',
            'MERCHANT_KEY' => '',
        ];
        $emptyAcf = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'admin' => 0,
            'info' => json_encode($emptyInfo),
        ]);
        $emptyAcf->info = $emptyInfo;

        $callbackData = [
            'sign' => '',
            'timestamp' => '1770108032294',
            'status' => '0000',
            'tradeNo' => $transactionNo,
            'message' => 'Mock success status',
            'platFormTradeNo' => 'Y000forged_wd',
            'amount' => 10000,
            'successTime' => '2026-02-03 16:40:32',
        ];
        // 攻擊者以空 merchant key 自算簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, '');
        $request = Request::create('/callback', 'POST', $callbackData);

        $threw = false;
        try {
            (new WithdrawalCallback($emptyAcf))->handle($request);
        } catch (\Exception $e) {
            $threw = true;
            $this->assertSame('Invalid signature', $e->getMessage());
        }
        $this->assertTrue($threw, '空憑證回調應被守衛拒絕');

        $withdrawalOrder->refresh();
        $this->assertSame(22, $withdrawalOrder->status);
    }
}
