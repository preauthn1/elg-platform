<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GlobePay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\GlobePay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * GlobePay 代付回調測試
 *
 * GlobePay 回調資料用 AES-256-CBC（PBKDF2 key）加密，並附帶 RSA SHA-1 簽名。
 * 測試中使用動態生成的 RSA key pair 與固定 AES key。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GlobePay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    /** @var resource */
    private mixed $privateKey;

    private string $privateKeyPem;

    private string $publicKeyBase64;

    private string $aesKey = 'test_aes_key_for_globe_pay_unit';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        // 生成測試用 RSA key pair（512-bit 讓 signature base64 ~88 字元，符合 DB sign varchar(200)）
        $keyPair = openssl_pkey_new([
            'digest_alg' => 'sha1',
            'private_key_bits' => 512,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $privateKeyPem = '';
        openssl_pkey_export($keyPair, $privateKeyPem);
        $this->privateKeyPem = $privateKeyPem;

        $publicKeyDetails = openssl_pkey_get_details($keyPair);
        $publicKeyPem = $publicKeyDetails['key'];

        // 從 PEM 提取 base64 部分（去掉 header/footer 和換行）
        $publicKeyLines = explode("\n", trim($publicKeyPem));
        array_shift($publicKeyLines); // 移除 -----BEGIN PUBLIC KEY-----
        array_pop($publicKeyLines);   // 移除 -----END PUBLIC KEY-----
        $this->publicKeyBase64 = implode('', $publicKeyLines);

        $info = [
            'API_URL' => 'https://api.globepay.test',
            'MERCHANT_CODE' => 'TEST_MERCHANT',
            'AES_KEY' => $this->aesKey,
            'RSA_PRIVATE_KEY' => $this->extractPrivateKeyBase64(),
            'RSA_PUBLIC_KEY' => $this->publicKeyBase64,
            'CURRENCY' => 'MYR',
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
     * 從 PEM 提取 private key base64（供 info 存儲）
     */
    private function extractPrivateKeyBase64(): string
    {
        $lines = explode("\n", trim($this->privateKeyPem));
        array_shift($lines);
        array_pop($lines);

        return implode('', $lines);
    }

    /**
     * AES 加密（與 GlobePayTrait::aesEncrypt 相同邏輯）
     */
    private function aesEncrypt(string $plainText, string $aesKey): string
    {
        $key = hash_pbkdf2('sha1', $aesKey, $aesKey, 1000, 32, true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plainText, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        $combined = $iv . $encrypted;

        return base64_encode($combined);
    }

    /**
     * RSA 簽名（與 GlobePayTrait::generateRsaSignature 相同邏輯）
     */
    private function generateRsaSignature(string $plainText): string
    {
        $signature = '';
        openssl_sign($plainText, $signature, $this->privateKeyPem);

        return base64_encode($signature);
    }

    /**
     * 建立加密的回調請求資料
     */
    private function buildCallbackRequest(array $data): array
    {
        $jsonData = json_encode($data);
        $encryptedData = $this->aesEncrypt($jsonData, $this->aesKey);
        $signature = $this->generateRsaSignature($jsonData);

        return [
            'Data' => $encryptedData,
            'Signature' => $signature,
        ];
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
     * 測試成功代付回調（Status=6 → order status 24）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GlobePay/WithdrawalCallbackTest.php --filter test_success
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

        $transactionNo = 'GP123456789';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = $this->buildCallbackRequest([
            'MerchantTransactionId' => $transactionNo,
            'Status' => 6,
            'Amount' => '100.00',
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
    }

    /**
     * 測試失敗代付回調（Status=7 → order status 29）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GlobePay/WithdrawalCallbackTest.php --filter test_failure_callback
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

        $transactionNo = 'GP123456790';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = $this->buildCallbackRequest([
            'MerchantTransactionId' => $transactionNo,
            'Status' => 7,
            'Amount' => '100.00',
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * 測試未知狀態中間態（Status=9 → 保持 22，回成功響應，payout 未結算）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GlobePay/WithdrawalCallbackTest.php --filter test_indeterminate_keeps_22
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

        $transactionNo = 'GP123456791';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        // Status=9 為未知碼 → INDETERMINATE
        $callbackData = $this->buildCallbackRequest([
            'MerchantTransactionId' => $transactionNo,
            'Status' => 9,
            'Amount' => '100.00',
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);
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
