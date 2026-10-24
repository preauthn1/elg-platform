<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VtPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\VtPay\Withdrawal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * VtPay 代付請求測試
 *
 * VtPay 請求側以 VtPayCryptoService AES-128-CBC 加密（API_SECRET 為 base64 的 16-byte key，
 * 請求被 Http::fake 攔截）。回應側解析鏈：VtPayTrait::decrypt 先驗 base64+JSON，再交
 * VtPayCryptoService::decrypt 還原——當 payload 無 iv/value 結構時直接回傳，故回應以
 * base64(JSON) 明文構造即可驅動真實解析鏈，免造 AES 密文。
 *
 * 涵蓋 interpretResult error_code 判定的真實路徑：
 * - error_code=200 → 受理（PENDING）
 * - 解碼成功但無成功碼（error_code 缺漏/0）→ interpretResult 判失敗 → THIRD_PARTY_REJECTED(-2)
 * - error_code != 200 → CryptoService::decrypt 拋 ApiException → REQUEST_FAILED(-1)（真實訊息經 -1 帶出）
 * - 連線例外 → REQUEST_FAILED(-1)
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VtPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    // base64 的 16-byte AES-128 key（對齊 VtPayCryptoService 的 key 長度檢核）
    private const API_SECRET = [REDACTED]'; // base64('1234567890123456')

    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        $currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://api.vtpay.test',
            'API_SECRET' => self::API_SECRET,
            'available_banks' => [
                ['BankCode_ELG' => 'MBB', 'BankCode_Third' => 'KASITHBK'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => $currency->id,
        ]);
        $this->agentCashFlow->info = $info;

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    /**
     * 以 base64(JSON) 構造回應 body；payload 無 iv/value 結構，
     * VtPayCryptoService::decrypt 會在 base64+JSON 還原後直接返回 payload。
     */
    private function encodeResponse(array $payload): string
    {
        return base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    public function test_success_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response($this->encodeResponse([
            'error_code' => 200,
            'data' => ['order_id' => 'VT-TX-1'],
        ]), 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('VT-TX-1', $record->third_party_transaction_no);
    }

    /**
     * 解碼成功但無成功碼（error_code 缺漏/0，未觸發 CryptoService 的非 200 拋錯路徑）
     * → interpretResult 判失敗 → THIRD_PARTY_REJECTED(-2)
     */
    public function test_decoded_without_success_code_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response($this->encodeResponse([
            'message' => 'rejected',
            'data' => ['order_id' => ''],
        ]), 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame('rejected', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * 三方錯誤碼（error_code != 200）→ VtPayCryptoService::decrypt 拋 ApiException →
     * sendRequest 包裝後拋出 → REQUEST_FAILED(-1)，真實三方訊息經 -1 帶出
     */
    public function test_api_error_code_marks_request_failed_with_real_message(): void
    {
        Http::fake(['*' => Http::response($this->encodeResponse([
            'error_code' => 4001,
            'message' => 'Insufficient balance',
        ]), 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Insufficient balance', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    public function test_connection_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }
}
