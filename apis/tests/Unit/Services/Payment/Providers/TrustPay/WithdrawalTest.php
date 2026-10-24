<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TrustPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\TrustPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TrustPay 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TrustPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    private string $secretKey = 'test_secret_key';

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
            'API_URL' => 'https://api.trustpay.test',
            'API_KEY' => 'test_api_key',
            'SECRET_KEY' => $this->secretKey,
            'available_banks' => [
                ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'currency_id' => $this->currency->id,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->setRelation('currency', $this->currency);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 建一張「代付提交中」的提款單；$bankCurrencyId 為 null 時模擬 banks.currency_id 未設定
     */
    private function createWithdrawalOrder(?int $bankCurrencyId): WithdrawalOrder
    {
        $bank = Bank::create([
            'currency_id' => $bankCurrencyId,
            'code' => 'MAYBANK',
            'name' => 'Maybank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_group_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => '100.00',
            'rate' => 1,
            'point' => 0,
            'tax' => 0,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    /**
     * 銀行幣別對得到主檔：送出的 currency 是幣別代碼、參與簽名，受理後記錄維持 PENDING
     */
    public function test_withdrawal_sends_currency_code_and_signature(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder($this->currency->id);

        Http::fake([
            '*/api/create_payout' => Http::response([
                'status' => true,
                'result' => ['status_id' => 0, 'transaction_reference' => 'TPO_TXN_001'],
            ], 200),
        ]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertTrue($response->isSuccess);
        $this->assertSame('TPO_TXN_001', $response->providerOrderNo);
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);

        Http::assertSent(function ($request) {
            $expectedHash = hash_hmac('sha256', $request['ref_no'] . '100.00' . 'MYR', $this->secretKey);

            return $request['currency'] === 'MYR' && $request['hash'] === $expectedHash && $request['bank_name'] === 'MBB';
        });
    }

    /**
     * 銀行的幣別 id 是 NULL：在送出前擋下，錯誤訊息要講清楚是幣別設定缺失，而不是型別錯誤原文
     */
    public function test_withdrawal_fails_before_request_when_bank_currency_is_null(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(null);
        Http::fake();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('TrustPay invalid currency id: null', (string) $response->errorMessage);
        $this->assertStringNotContainsString('TypeError', (string) $response->errorMessage);
        $this->assertSame(0, WithdrawalPayoutRecord::count());
        Http::assertNothingSent();
    }

    /**
     * 銀行的幣別 id 不在主檔內：同樣在送出前擋下
     */
    public function test_withdrawal_fails_before_request_when_bank_currency_is_unknown(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(999);
        Http::fake();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('TrustPay invalid currency id: 999', (string) $response->errorMessage);
        $this->assertSame(0, WithdrawalPayoutRecord::count());
        Http::assertNothingSent();
    }
}
