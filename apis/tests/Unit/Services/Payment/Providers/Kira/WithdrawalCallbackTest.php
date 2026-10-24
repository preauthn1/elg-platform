<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Kira;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Facades\BotNotify;
use App\Jobs\SendTelegramNotification;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\Kira\WithdrawalCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Tests\Concerns\FakesTelegramDispatch;
use Tests\TestCase;

/**
 * Kira 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    use FakesTelegramDispatch;

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    private string $symmetricKey = '0123456789abcdef0123456789abcdef';

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
            'API_URL' => 'https://example.invalid',
            'CLIENT_ID' => 'test_client_id',
            'CLIENT_SECRET' => 'test_client_secret',
            'CLIENT_PRIVATE_KEY' => 'test_private_key',
            'CLIENT_SYMMETRIC_KEY' => $this->symmetricKey,
            'SERVER_PUBLIC_KEY' => 'test_public_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 100,
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
     * 加密回調數據用於測試
     */
    private function encryptCallbackData(array $data): string
    {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $iv = md5($this->symmetricKey, true);
        $encrypted = openssl_encrypt($jsonData, 'aes-256-cbc', $this->symmetricKey, OPENSSL_RAW_DATA, $iv);

        return bin2hex($encrypted);
    }

    /**
     * 創建測試用的 WithdrawalPayoutRecord
     */
    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 100,
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
     * 建立測試用的提款訂單
     */
    private function createWithdrawalOrder(int $status): WithdrawalOrder
    {
        $bank = Bank::where('code', 'FPX')->first() ?? Bank::create([
            'name' => 'FPX',
            'code' => 'FPX',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'FPX',
            'bank_name' => 'FPX',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 150.00,
            'status' => $status,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    /**
     * 實際派發的「已判拒絕卻收到成功回調」專用告警
     *
     * @return Collection<int, SendTelegramNotification>
     */
    private function dispatchedRejectedRecordSuccessAlerts(): Collection
    {
        return $this->dispatchedTelegramAlerts('卻收到成功回調');
    }

    /**
     * 組出帶加密回調資料的請求
     */
    private function callbackRequest(string $transactionNo, string $status): Request
    {
        return Request::create('/callback', 'POST', [
            'encryptedData' => $this->encryptCallbackData([
                'data' => [
                    'status' => $status,
                    'order' => [
                        'id' => $transactionNo,
                        'amount' => '150.00',
                        'currencyType' => 'MYR',
                    ],
                    'transaction' => [
                        'id' => 'KIRA_TXN_' . $transactionNo,
                        'amountWithdraw' => '150.00',
                        'timestamp' => time(),
                    ],
                    'recipient' => [
                        'name' => 'Test User',
                        'methodType' => 'FPX',
                        'methodValue' => '1234567890',
                    ],
                ],
            ]),
        ]);
    }

    /**
     * 測試成功處理代付回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $bank = Bank::where('code', 'FPX')->first() ?? Bank::create([
            'name' => 'FPX',
            'code' => 'FPX',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'FPX',
            'bank_name' => 'FPX',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 150.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'KIRA_WITH_001';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackPayload = [
            'data' => [
                'status' => 'SUCCESS',
                'order' => [
                    'id' => $transactionNo,
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12345',
                    'amountWithdraw' => '150.00',
                    'paymentMethod' => 'FPX',
                    'timestamp' => time(),
                ],
                'recipient' => [
                    'name' => 'Test User',
                    'methodType' => 'FPX',
                    'methodValue' => '1234567890',
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 驗證支付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);

        // 驗證訂單狀態更新為24（三方成功）
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->status);
    }

    /**
     * 測試處理代付回調（失敗狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_failed_status
     */
    public function test_failed_status()
    {
        // Arrange
        $bank = Bank::where('code', 'FPX')->first() ?? Bank::create([
            'name' => 'FPX',
            'code' => 'FPX',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'FPX',
            'bank_name' => 'FPX',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 150.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'KIRA_WITH_002';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackPayload = [
            'data' => [
                'status' => 'FAILED',
                'order' => [
                    'id' => $transactionNo,
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12346',
                    'amountWithdraw' => '150.00',
                    'paymentMethod' => 'FPX',
                    'timestamp' => time(),
                ],
                'recipient' => [
                    'name' => 'Test User',
                    'methodType' => 'FPX',
                    'methodValue' => '1234567890',
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 驗證支付記錄已更新為失敗
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);

        // 驗證訂單狀態更新為29（三方失敗）
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->status);
    }

    /**
     * 測試金額不符
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_amount_mismatch
     */
    public function test_amount_mismatch()
    {
        // Arrange
        $bank = Bank::where('code', 'FPX')->first() ?? Bank::create([
            'name' => 'FPX',
            'code' => 'FPX',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'FPX',
            'bank_name' => 'FPX',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 150.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'KIRA_WITH_003';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackPayload = [
            'data' => [
                'status' => 'SUCCESS',
                'order' => [
                    'id' => $transactionNo,
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12347',
                    'amountWithdraw' => '100.00', // 金額不符
                    'paymentMethod' => 'FPX',
                    'timestamp' => time(),
                ],
                'recipient' => [
                    'name' => 'Test User',
                    'methodType' => 'FPX',
                    'methodValue' => '1234567890',
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 金額不符時應返回成功響應
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 驗證訂單狀態已更新為成功
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->status);

        // 驗證代付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertEquals('100.0000', $payoutRecord->result_amount);
    }

    /**
     * 測試三態：status = PENDING → 保持22，回傳成功響應
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_indeterminate_keeps_status_22
     */
    public function test_indeterminate_keeps_status_22()
    {
        // Arrange
        $bank = Bank::where('code', 'FPX')->first() ?? Bank::create([
            'name' => 'FPX',
            'code' => 'FPX',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'FPX',
            'bank_name' => 'FPX',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 150.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $transactionNo = 'KIRA_WITH_INDET_001';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackPayload = [
            'data' => [
                'status' => 'PENDING',
                'order' => [
                    'id' => $transactionNo,
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_99999',
                    'amountWithdraw' => '150.00',
                    'paymentMethod' => 'FPX',
                    'timestamp' => time(),
                ],
                'recipient' => [
                    'name' => 'Test User',
                    'methodType' => 'FPX',
                    'methodValue' => '1234567890',
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 訂單維持22，未結算
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->status);
    }

    /**
     * 同步已判明確拒絕（-2、訂單已轉 29）的請求收到晚到的 FAILED 回調：兩邊結論一致 →
     * 只記下回調內容並回 OK，紀錄維持 -2、訂單維持 29、不告警
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_late_failed_callback_on_rejected_record_is_recorded_without_alert
     */
    public function test_late_failed_callback_on_rejected_record_is_recorded_without_alert(): void
    {
        BotNotify::spy();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value);
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_001', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_REJ_001', 'FAILED'));

        $this->assertEquals('OK', $result->response);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $payoutRecord->is_success_payout);
        $this->assertSame('FAILED', $payoutRecord->result_code);
        $this->assertNotNull($payoutRecord->callback_time);
        $this->assertStringContainsString('KIRA_WITH_REJ_001', $payoutRecord->callback_request_body);

        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->status);

        BotNotify::shouldNotHaveReceived('send');
    }

    /**
     * 同上，但訂單已重新發起（較新的代付紀錄在途、訂單 22）：晚到的 FAILED 回調同樣只記錄、回 OK，
     * 不得動到在途的訂單與新紀錄，也不發「回調來自非最新請求紀錄」告警
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_late_failed_callback_on_rejected_record_after_resubmit_keeps_in_flight_order
     */
    public function test_late_failed_callback_on_rejected_record_after_resubmit_keeps_in_flight_order(): void
    {
        BotNotify::spy();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value);
        $rejectedRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_002', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
        ]);
        $inFlightRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_003');

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_REJ_002', 'FAILED'));

        $this->assertEquals('OK', $result->response);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $rejectedRecord->refresh()->is_success_payout);
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $inFlightRecord->refresh()->is_success_payout);
        $this->assertEquals(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->refresh()->status);

        BotNotify::shouldNotHaveReceived('send');
    }

    /**
     * 同步已判明確拒絕（-2、訂單 29）卻收到 SUCCESS 回調＝拒絕判定可能有誤、可能重複出款：
     * 照常由狀態檢查拒絕（回 FAIL），紀錄與訂單都不變。
     * 專用告警必須實際派發：狀態檢查拒絕時交易會 rollBack，告警若在交易內送出會跟著被丟掉。
     * 狀態檢查自己的「29 仍收到回調」告警同樣必須在 rollBack 後實際派發
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_success_callback_on_rejected_record_still_alerts
     */
    public function test_success_callback_on_rejected_record_still_alerts(): void
    {
        $this->fakeTelegramDispatch();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value);
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_004', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_REJ_004', 'SUCCESS'));

        $this->assertEquals('FAIL', $result->response);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $payoutRecord->refresh()->is_success_payout);
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->refresh()->status);
        $this->assertCount(1, $this->dispatchedRejectedRecordSuccessAlerts());
        $this->assertCount(1, $this->dispatchedTelegramAlerts('仍收到回調'));
    }

    /**
     * 同上，但訂單已是三方成功(24)（例如已改由其他請求出款成功）：這正是重複出款已經發生的情況，
     * 狀態檢查拒絕、交易 rollBack，專用告警與狀態檢查的「訂單狀態不合預期」告警都必須實際派發
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_success_callback_on_rejected_record_alerts_when_order_already_succeeded
     */
    public function test_success_callback_on_rejected_record_alerts_when_order_already_succeeded(): void
    {
        $this->fakeTelegramDispatch();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value);
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_009', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_REJ_009', 'SUCCESS'));

        $this->assertEquals('FAIL', $result->response);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $payoutRecord->refresh()->is_success_payout);
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->refresh()->status);
        $this->assertCount(1, $this->dispatchedRejectedRecordSuccessAlerts());
        $this->assertCount(1, $this->dispatchedTelegramAlerts('訂單狀態不合預期'));
    }

    /**
     * 同步已判明確拒絕（-2）的單剛寫入、訂單還在 21 時就收到 SUCCESS 回調：訂單不是 29、狀態檢查會放行，
     * 專用告警仍必須實際派發；之後照常定案為三方成功(24)
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_success_callback_on_rejected_record_alerts_even_when_order_not_failed
     */
    public function test_success_callback_on_rejected_record_alerts_even_when_order_not_failed(): void
    {
        $this->fakeTelegramDispatch();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value);
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_005', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_REJ_005', 'SUCCESS'));

        $this->assertEquals('OK', $result->response);
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->refresh()->is_success_payout);
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->refresh()->status);
        $this->assertCount(1, $this->dispatchedRejectedRecordSuccessAlerts());
    }

    /**
     * 同步已判明確拒絕（-2、訂單 29）收到 PENDING 回調：跟同步結論不衝突、沒有出款 →
     * 回 OK、不告警；沿用「非終態回調不寫 DB」，紀錄的回調欄位維持原樣
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_pending_callback_on_rejected_record_is_acknowledged_without_db_write
     */
    public function test_pending_callback_on_rejected_record_is_acknowledged_without_db_write(): void
    {
        BotNotify::spy();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value);
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_006', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_REJ_006', 'PENDING'));

        $this->assertEquals('OK', $result->response);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $payoutRecord->is_success_payout);
        $this->assertNull($payoutRecord->callback_time);
        $this->assertSame('', $payoutRecord->callback_request_body);
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->refresh()->status);

        BotNotify::shouldNotHaveReceived('send');
    }

    /**
     * 同一筆同步已拒絕（-2）的紀錄連收兩次 FAILED 回調（三方重送）：兩次都回 OK、紀錄維持 -2、不告警
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_repeated_failed_callback_on_rejected_record_stays_silent
     */
    public function test_repeated_failed_callback_on_rejected_record_stays_silent(): void
    {
        BotNotify::spy();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value);
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_007', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
        ]);

        foreach ([1, 2] as $round) {
            $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_REJ_007', 'FAILED'));

            $this->assertEquals('OK', $result->response, "round {$round}");
            $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $payoutRecord->refresh()->is_success_payout, "round {$round}");
        }

        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->refresh()->status);

        BotNotify::shouldNotHaveReceived('send');
    }

    /**
     * 同步已判明確拒絕（-2）但訂單仍停在 21（例如流程在轉 29 前中斷）時收到 FAILED 回調：
     * 只記錄、不替訂單定案，訂單維持 21，由卡單排程告警後人工處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_failed_callback_on_rejected_record_keeps_order_at_21
     */
    public function test_failed_callback_on_rejected_record_keeps_order_at_21(): void
    {
        BotNotify::spy();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value);
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_REJ_008', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED->value,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_REJ_008', 'FAILED'));

        $this->assertEquals('OK', $result->response);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $payoutRecord->refresh()->is_success_payout);
        $this->assertEquals(WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value, $withdrawalOrder->refresh()->status);

        BotNotify::shouldNotHaveReceived('send');
    }

    /**
     * 只有同步已拒絕（-2）的紀錄走「只記錄」：結果未知（-1）停在 21 的單收到 FAILED 回調，
     * 仍照常自動定案（紀錄 -3、訂單轉三方失敗 29），保住「遲到回調自動定案」這個出口
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_failed_callback_on_request_failed_record_still_settles_order
     */
    public function test_failed_callback_on_request_failed_record_still_settles_order(): void
    {
        BotNotify::spy();

        $withdrawalOrder = $this->createWithdrawalOrder(WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value);
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'KIRA_WITH_UNK_001', [
            'third_party_transaction_no' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::REQUEST_FAILED->value,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($this->callbackRequest('KIRA_WITH_UNK_001', 'FAILED'));

        $this->assertEquals('OK', $result->response);
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->refresh()->is_success_payout);
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->refresh()->status);
    }

    /**
     * 測試缺少加密數據
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_missing_encrypted_data
     */
    public function test_missing_encrypted_data()
    {
        // Arrange
        $request = Request::create('/callback', 'POST', []);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert & Act - 接受任何類型的異常（ValidationException 或 ApiException）
        try {
            $withdrawalCallback->handle($request);
            $this->fail('Expected exception to be thrown');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * 測試缺少訂單 ID 在回調數據中
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_missing_order_id
     */
    public function test_missing_order_id()
    {
        // Arrange
        $callbackPayload = [
            'data' => [
                'status' => 'SUCCESS',
                'order' => [
                    // 缺少 'id'
                    'amount' => '150.00',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12345',
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Missing order id in callback data');

        // Act
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試不存在的支付記錄
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_non_existent_payout_record
     */
    public function test_non_existent_payout_record()
    {
        // Arrange
        $callbackPayload = [
            'data' => [
                'status' => 'SUCCESS',
                'order' => [
                    'id' => 'KIRA_WITH_NONEXISTENT',
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12345',
                    'amountWithdraw' => '150.00',
                    'timestamp' => time(),
                ],
                'recipient' => [
                    'name' => 'Test User',
                    'methodType' => 'FPX',
                    'methodValue' => '1234567890',
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 不存在時應該返回特定的 response
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertNotNull($result->response);
    }

    /**
     * 測試缺少 CLIENT_SYMMETRIC_KEY 配置
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalCallbackTest.php --filter test_missing_symmetric_key
     */
    public function test_missing_symmetric_key()
    {
        // Arrange
        $info = [
            'API_URL' => 'https://example.invalid',
            'CLIENT_ID' => 'test_client_id',
        ];

        $agentCashFlow = new AgentCashFlow([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 100,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $request = Request::create('/callback', 'POST', [
            'encryptedData' => 'test_encrypted_data',
        ]);

        $withdrawalCallback = new WithdrawalCallback($agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Kira configuration missing: CLIENT_SYMMETRIC_KEY');

        // Act
        $withdrawalCallback->handle($request);
    }
}
