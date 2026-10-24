<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GlobePay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\GlobePay\Withdrawal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GlobePay 代付請求測試
 *
 * GlobePay 請求側需 AES-256-CBC 加密 + RSA 簽名（setUp 動態生成 RSA key pair 讓簽章成功），
 * 但代付回應走明文 JSON（sendRequest 直接 ->json()），故回應以 Http::fake 明文構造即可。
 * 覆蓋 interpretResult 兩條失敗分支（isSuccess !== true / data 為空）+ 受理 + 請求例外。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GlobePay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        // 生成測試用 RSA key pair，僅供 generateRsaSignature 簽章成功（請求被 Http::fake 攔截）
        $keyPair = openssl_pkey_new([
            'digest_alg' => 'sha1',
            'private_key_bits' => 512,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $privateKeyPem = '';
        openssl_pkey_export($keyPair, $privateKeyPem);
        $publicKeyPem = openssl_pkey_get_details($keyPair)['key'];

        $info = [
            'API_URL' => 'https://api.globepay.test',
            'MERCHANT_CODE' => 'TEST_MERCHANT',
            'AES_KEY' => 'test_aes_key_for_globe_pay_unit',
            'RSA_PRIVATE_KEY' => $this->stripPem($privateKeyPem),
            'RSA_PUBLIC_KEY' => $this->stripPem($publicKeyPem),
            'CURRENCY' => 'MYR',
            'available_banks' => [
                ['BankCode_ELG' => 'MBB', 'BankCode_Third' => 'MBB001'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 1,
        ]);
        $this->agentCashFlow->info = $info;

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        // owner = agent_id > 0 ? agent : member；此處 owner=agent，buildPayoutParams 取 owner->username
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
     * 從 PEM 提取 base64 主體（去 header/footer 與換行），對齊 info 儲存格式
     */
    private function stripPem(string $pem): string
    {
        $lines = explode("\n", trim($pem));
        array_shift($lines);
        array_pop($lines);

        return implode('', $lines);
    }

    public function test_success_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true,
            'data' => ['transactionId' => 'GP-TX-1'],
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('GP-TX-1', $record->third_party_transaction_no);
    }

    /**
     * 失敗分支①：isSuccess !== true → THIRD_PARTY_REJECTED，並回填三方 errorMessage
     */
    public function test_not_success_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => false,
            'errorMessage' => 'Insufficient balance',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame('Insufficient balance', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * 失敗分支②（2-branch 獨有）：isSuccess === true 但 data 為空 → 'No data returned' → THIRD_PARTY_REJECTED
     */
    public function test_success_true_but_empty_data_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true,
            'data' => [],
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame('No data returned', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    public function test_request_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }
}
