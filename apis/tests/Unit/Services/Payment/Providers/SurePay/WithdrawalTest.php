<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\SurePay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\SurePay\Withdrawal;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SurePay 代付請求測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/SurePay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.surepay.test',
            'MERCHANT_ID' => 'SPREE009Tm99s',
            'CONTRACT_NAME' => 'test_contract',
            'API_KEY' => 'test_api_key',
            'CALLBACK_API_KEY' => 'test_callback_api_key',
            'CURRENCY' => 'MYR',
            'WITHDRAWAL_IBAN' => 'IBAN001',
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'TBANK'],
            ],
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

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    public function test_success_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response(['status' => 1, 'refid' => 'SP-1'], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('SP-1', $record->third_party_transaction_no);
    }

    public function test_sync_reject_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response(['status' => -104, 'statdesc' => 'Request Rejected'], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame('Request Rejected', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * 錯誤表沒有的碼：客服畫面（errorMessage）與代付紀錄 info_1 都要帶出金流商回的原因（target/op_tickets#3175）
     */
    public function test_unknown_code_shows_third_party_reason(): void
    {
        Http::fake(['*' => Http::response(['status' => -110, 'statdesc' => 'Invalid Credential'], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame('Unknown error code: -110 (Invalid Credential)', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
        $this->assertStringContainsString('Unknown error code: -110 (Invalid Credential)', $record->info_1);
    }

    /**
     * 金流商沒給可用的原因時，訊息仍須保留錯誤碼、不可為空：
     * WithdrawalPayoutService 以 errorMessage 是否為空判定金流商已受理，空字串會把拒絕誤判成受理
     */
    #[DataProvider('unusableReasonProvider')]
    public function test_unknown_code_without_usable_reason_keeps_code_message(array $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertSame('Unknown error code: -110', $response->errorMessage);
    }

    public static function unusableReasonProvider(): array
    {
        return [
            '沒有 statdesc' => [['status' => -110]],
            'statdesc 為 null' => [['status' => -110, 'statdesc' => null]],
            'statdesc 為空字串' => [['status' => -110, 'statdesc' => '']],
            'statdesc 只有空白' => [['status' => -110, 'statdesc' => '   ']],
            'statdesc 不是字串' => [['status' => -110, 'statdesc' => ['Invalid Credential']]],
        ];
    }

    public function test_request_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }
}
