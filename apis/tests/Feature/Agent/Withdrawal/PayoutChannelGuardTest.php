<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Withdrawal;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\PaymentService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 代付發起渠道覆核（缺陷 C）：payout() 送出前必須複用 channels 過濾鏈驗證
 * 「提交的 agent_cash_flow_id ∈ available 集合」，一次涵蓋四缺口：
 * group 歸屬（IDOR）／渠道可用性／金額限額／幣別匹配。
 * 不合格 fail-fast 拒絕，訂單維持原狀態、不得觸發三方請求。
 *
 * php artisan test tests/Feature/Agent/Withdrawal/PayoutChannelGuardTest.php
 */
class PayoutChannelGuardTest extends TestCase
{
    private string $apiPath = 'agent/api/withdrawal/payout-process';

    private Agent $agent;

    private Bank $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();

        $this->bank = Bank::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'type' => 1,
            'name' => '測試銀行',
            'code' => 'TEST001',
            'currency_id' => 1,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 0,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_rejects_acf_from_other_group(): void
    {
        $order = $this->makeApprovedOrder();
        $acf = $this->makePayoutChannel(['agent_group_id' => 999999]);
        $this->mockProviderNeverCalled();

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $acf->id,
            'order_id' => $order->id,
        ]);

        // 他 group 的 ACF 不得驅動本 group 訂單（IDOR 面），且不得觸發三方請求
        $this->assertSame(3, (int) $order->refresh()->status);
    }

    public function test_rejects_channel_not_supporting_bank(): void
    {
        $order = $this->makeApprovedOrder();
        $acf = $this->makePayoutChannel([
            'info' => [
                'available_banks' => [['BankCode_ELG' => 'OTHER_BANK']],
            ],
        ]);
        $this->mockProviderNeverCalled();

        $response = $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $acf->id,
            'order_id' => $order->id,
        ]);

        $this->assertSame(3, (int) $order->refresh()->status);
        // 拒絕訊息必須帶具體原因供客服判讀
        $this->assertStringContainsString('不支援銀行', (string) $response->json('message'));
    }

    public function test_rejects_amount_over_max_limit(): void
    {
        $order = $this->makeApprovedOrder(['amount' => 99999]);
        $acf = $this->makePayoutChannel([
            'info' => [
                'available_banks' => [['BankCode_ELG' => 'TEST001']],
                'max_amount' => 500,
            ],
        ]);
        $this->mockProviderNeverCalled();

        $response = $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $acf->id,
            'order_id' => $order->id,
        ]);

        $this->assertSame(3, (int) $order->refresh()->status);
        $this->assertStringContainsString('超過最大限額', (string) $response->json('message'));
    }

    public function test_rejects_disabled_channel(): void
    {
        $order = $this->makeApprovedOrder();
        $acf = $this->makePayoutChannel(['status' => 0]);
        $this->mockProviderNeverCalled();

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $acf->id,
            'order_id' => $order->id,
        ]);

        $this->assertSame(3, (int) $order->refresh()->status);
    }

    public function test_rejects_currency_mismatch_channel(): void
    {
        $order = $this->makeApprovedOrder();
        $acf = $this->makePayoutChannel(['currency_id' => 2]);
        $this->mockProviderNeverCalled();

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $acf->id,
            'order_id' => $order->id,
        ]);

        $this->assertSame(3, (int) $order->refresh()->status);
    }

    public function test_allows_valid_channel_and_proceeds(): void
    {
        $order = $this->makeApprovedOrder();
        $acf = $this->makePayoutChannel();
        $this->mockProviderResponse(WithdrawalResponseData::from([
            'isSuccess' => true,
            'providerOrderNo' => 'TP-OK-1',
            'providerResponse' => ['code' => 'ok'],
        ]));

        $this->requestApi($this->apiPath, [
            'agent_cash_flow_id' => $acf->id,
            'order_id' => $order->id,
        ]);

        // 合法渠道不得被過度攔截：照常送出並轉 22
        $this->assertSame(22, (int) $order->refresh()->status);
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private function makeApprovedOrder(array $overrides = []): WithdrawalOrder
    {
        return WithdrawalOrder::factory()->create(array_merge([
            'agent_id' => $this->agent->id,
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'member_id' => 0,
            'bank_id' => $this->bank->id,
            'bank_code' => 'TEST001',
            'bank_name' => '測試銀行',
            'amount' => 500,
            'status' => 3,
        ], $overrides));
    }

    private function makePayoutChannel(array $overrides = []): AgentCashFlow
    {
        $cashFlow = CashFlow::create([
            // 必須指定 id，不可沿用自增值：fetchPayoutChannels() 以
            // whereNotIn('cash_flow_id', [5, 6]) 排除自助開分／自助洗分，自增值一旦落在
            // 5 或 6，本渠道會被誤排除而使覆核不通過。AUTO_INCREMENT 不隨
            // DatabaseTransactions 回滾，counter 起點取決於同一 process 先前跑過哪些測試，
            // 並行測試下即表現為間歇性失敗。
            'id' => 9001,
            'name' => 'Payout Channel',
            'type' => 3,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        return AgentCashFlow::create(array_merge([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_id' => $cashFlow->id,
            'cash_flow_info_id' => 0,
            'currency_id' => 1,
            'rate' => 1,
            'info' => [
                'available_banks' => [['BankCode_ELG' => 'TEST001']],
                'min_amount' => 100,
                'max_amount' => 10000,
            ],
            'sorts' => 0,
            'cost' => 0,
            'status' => 1,
            'admin' => 1,
        ], $overrides));
    }

    private function mockProviderNeverCalled(): void
    {
        $this->mock(PaymentService::class, function ($mock): void {
            $mock->shouldReceive('getProvider')->never();
        });
    }

    private function mockProviderResponse(WithdrawalResponseData $response): void
    {
        $provider = new class($response)
        {
            public function __construct(private readonly WithdrawalResponseData $response) {}

            public function withdrawal(WithdrawalRequestData $request): WithdrawalResponseData
            {
                return $this->response;
            }
        };

        $this->mock(PaymentService::class, function ($mock) use ($provider): void {
            $mock->shouldReceive('getProvider')->andReturn($provider);
        });
    }
}
