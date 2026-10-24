<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\A3Pay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Facades\BotNotify;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Providers\A3Pay\WithdrawalQuery;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A3Pay Payout Verification（三方出款前反查）測試：驗簽對象是解密後的 JSON 明文（api.md），
 * 對密文簽的必須被拒，否則會放行簽錯對象的請求。
 *
 * 回 success 等同授權出款，所以狀態閘門每一條都要有「被拒」案例：記錄非 PENDING、有較新記錄、
 * 訂單 29（走 checkWithdrawalOrderStatus 統一入口並送告警）；21 與 22 都要放行。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/A3Pay/WithdrawalQueryTest.php
 */
class WithdrawalQueryTest extends TestCase
{
    use A3PayCryptoHelpers;

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private WithdrawalOrder $order;

    private WithdrawalPayoutRecord $record;

    private string $transactionNo = 'A3PAY20260922000009';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->generateRsaKeyPair();

        $info = $this->buildInfo();

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 132,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create(['parent' => $this->agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'DBSSG',
            'bank_name' => 'DBS Bank Limited',
            'account_no' => '0123456789',
            'account_name' => 'Tan Ah Kow',
            'amount' => 100.00,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $this->record = $this->createRecord($this->transactionNo);
    }

    /** createPayoutRecord 建檔時 result_amount 即為訂單金額，這裡照做 */
    private function createRecord(string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => 132,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $this->order->id,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
            'result_amount' => 100.00,
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

    private function verificationData(string $amount = '100.00', string $currency = 'SGD', ?string $transactionNo = null): array
    {
        return ['MerchantTransactionId' => $transactionNo ?? $this->transactionNo, 'Amount' => $amount, 'CurrencyCode' => $currency];
    }

    /** 合規的反查請求（Data 密文＋對明文簽） */
    private function verify(array $data): array
    {
        $json = json_encode($data);
        $payload = ['MerchantCode' => 'SGD00001', 'Data' => $this->aesEncrypt($json), 'Signature' => $this->rsaSign($json)];

        return (new WithdrawalQuery($this->agentCashFlow))->handle(Request::create('/query', 'POST', $payload));
    }

    private function assertRejected(array $result, int $httpStatusCode = 400): void
    {
        $this->assertSame('failed', $result['response']);
        $this->assertSame($httpStatusCode, $result['httpStatusCode']);
    }

    public function test_signature_over_plaintext_passes_and_returns_success(): void
    {
        $json = json_encode($this->verificationData());
        $payload = [
            'MerchantCode' => 'SGD00001',
            'Data' => $this->aesEncrypt($json),
            'AdditionalInformationData' => $this->aesEncrypt('{}'),
            'Signature' => $this->rsaSign($json),
        ];

        $result = (new WithdrawalQuery($this->agentCashFlow))->handle(Request::create('/query', 'POST', $payload));

        $this->assertSame('success', $result['response']);
        $this->assertSame(200, $result['httpStatusCode']);
    }

    public function test_signature_over_ciphertext_is_rejected(): void
    {
        $encrypted = $this->aesEncrypt(json_encode($this->verificationData()));
        $payload = ['Data' => $encrypted, 'Signature' => $this->rsaSign($encrypted)];

        $result = (new WithdrawalQuery($this->agentCashFlow))->handle(Request::create('/query', 'POST', $payload));

        $this->assertSame('failed', $result['response']);
        $this->assertSame(400, $result['httpStatusCode']);
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        $json = json_encode($this->verificationData('999.00'));
        $payload = ['Data' => $this->aesEncrypt($json), 'Signature' => $this->rsaSign($json)];

        $result = (new WithdrawalQuery($this->agentCashFlow))->handle(Request::create('/query', 'POST', $payload));

        $this->assertSame('failed', $result['response']);
        $this->assertSame(400, $result['httpStatusCode']);
    }

    public function test_order_21_is_allowed(): void
    {
        // Verification 可能在 SubmitPayout 同步回應之前就到，此時訂單仍在 21
        $this->order->update(['status' => 21]);

        $result = $this->verify($this->verificationData());

        $this->assertSame('success', $result['response']);
    }

    public function test_unknown_transaction_is_rejected_with_404(): void
    {
        $this->assertRejected($this->verify($this->verificationData(transactionNo: 'A3PAY_NOT_EXISTS')), 404);
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $this->assertRejected($this->verify($this->verificationData(currency: 'MYR')));
    }

    /** -2＝我方已宣告未建單並允許重送，三方拿這個交易號來問一律不放 */
    public function test_third_party_rejected_record_is_rejected(): void
    {
        $this->record->update(['is_success_payout' => WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED]);

        $this->assertRejected($this->verify($this->verificationData()));
    }

    /** -1＝我方逾時結果未知：回 failed 讓三方不出款，把未知變成確定不付 */
    public function test_request_failed_record_is_rejected(): void
    {
        $this->record->update(['is_success_payout' => WithdrawalPayoutStatusEnum::REQUEST_FAILED]);

        $this->assertRejected($this->verify($this->verificationData()));
    }

    /** 21 卡單人工轉 29 再重新發起：舊記錄仍在、訂單又回到 22，舊交易號必須被擋 */
    public function test_superseded_record_is_rejected_while_newest_is_allowed(): void
    {
        $newest = 'A3PAY20260922000010';
        $this->createRecord($newest);

        $this->assertRejected($this->verify($this->verificationData()));
        $this->assertSame('success', $this->verify($this->verificationData(transactionNo: $newest))['response']);
    }

    /** 訂單 29 走 checkWithdrawalOrderStatus 統一入口：拒絕並送防重複出款告警 */
    public function test_order_29_is_rejected_and_alerts(): void
    {
        BotNotify::spy();
        $this->order->update(['status' => 29]);

        $this->assertRejected($this->verify($this->verificationData()));
        BotNotify::shouldHaveReceived('send')->once();
    }

    /** 記錄與訂單都沒被反查改動（唯讀） */
    public function test_verification_does_not_mutate_record_or_order(): void
    {
        $this->verify($this->verificationData());

        $this->assertSame(22, $this->order->refresh()->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $this->record->refresh()->is_success_payout);
    }
}
