<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VtPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\VtPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * VtPay 代付回調測試
 *
 * VtPay 回調資料用 AES-128-CBC 加密（base64 key），payload 結構：{"iv":"...","value":"..."}
 * 再整體 base64 encode 後以 response 參數傳入。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VtPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    /**
     * 16-byte key 的 base64 編碼（AES-128 需要恰好 16 bytes）
     */
    private string $apiSecretBase64;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        // 16-byte 原始 key → base64（VtPayCryptoService 要求 decode 後恰好 16 bytes）
        $rawKey = 'TestKey16ByteKey'; // 恰好 16 bytes
        $this->apiSecretBase64 = base64_encode($rawKey);

        $info = [
            'API_URL' => 'https://api.vtpay.test/SH',
            'API_SECRET' => $this->apiSecretBase64,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
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
     * AES-128-CBC 加密，與 VtPayCryptoService::encrypt 相同邏輯
     * 輸出：base64(json({"iv":"...","value":"..."}))
     */
    private function encryptCallbackData(array $data): string
    {
        $key = base64_decode($this->apiSecretBase64, true);
        $iv = random_bytes(16);

        $value = openssl_encrypt(
            json_encode($data, JSON_UNESCAPED_SLASHES),
            'AES-128-CBC',
            $key,
            0,
            $iv
        );

        return base64_encode(json_encode([
            'iv' => base64_encode($iv),
            'value' => $value,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * 創建測試用 WithdrawalPayoutRecord
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
     * 測試成功代付回調（order_status=3 → order status 24）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/WithdrawalCallbackTest.php --filter test_success
     */
    public function test_success(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $transactionNo = 'VT123456789';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackPayload = [
            'sh_order_no' => $transactionNo,
            'order_status' => 3, // 成功
            'order_amount' => 100.0,
            'paid_amount' => 100.0,
        ];

        $request = Request::create('/callback', 'POST', [
            'response' => $this->encryptCallbackData($callbackPayload),
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
    }

    /**
     * 測試失敗代付回調（order_status=5 → order status 29）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/WithdrawalCallbackTest.php --filter test_failure_callback
     */
    public function test_failure_callback(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $transactionNo = 'VT123456790';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackPayload = [
            'sh_order_no' => $transactionNo,
            'order_status' => 5, // 失敗
            'order_amount' => 100.0,
            'paid_amount' => 0.0,
        ];

        $request = Request::create('/callback', 'POST', [
            'response' => $this->encryptCallbackData($callbackPayload),
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * 測試未知狀態中間態（order_status=0 → 保持 22，回成功響應，payout 未結算）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/WithdrawalCallbackTest.php --filter test_indeterminate_keeps_22
     */
    public function test_indeterminate_keeps_22(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $transactionNo = 'VT123456791';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        // order_status=0 為未知碼 → INDETERMINATE
        $callbackPayload = [
            'sh_order_no' => $transactionNo,
            'order_status' => 0,
            'order_amount' => 100.0,
            'paid_amount' => 0.0,
        ];

        $request = Request::create('/callback', 'POST', [
            'response' => $this->encryptCallbackData($callbackPayload),
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        // INDETERMINATE → 回成功響應 ack 三方
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        // 訂單狀態保持 22
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        // payout record 不應被結算
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertEquals('', $payoutRecord->result_code);
    }
}
