<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\DgPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\DgPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * DgPay 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/DgPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    protected Agent $agent;

    protected Currency $currency;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    /** SGD V2 info（VERSION=V2，代付走 /v2/withdraw + recipientInfo） */
    private array $infoSgdV2 = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'SGD',
        'VERSION' => 'V2',
        'available_banks' => [
            ['BankCode_ELG' => 'DBS', 'BankCode_Third' => 'DBS', 'BankName' => 'DBS Bank'],
        ],
    ];

    /** MYR Auto info（providerId=5, providerType=20 → 自動代付，data 帶 toBankCode） */
    private array $infoMyrAuto = [
        'APIUrl' => 'https://dgpayapi.test',
        'Merchant' => 'dgpay_test',
        'MerchantKey' => 'test-merchant-key-abc',
        'Currency' => 'MYR',
        'PROVIDER_ID' => '5',
        'PROVIDER_TYPE' => '20',
        'available_banks' => [
            ['BankCode_ELG' => 'MAYB', 'BankCode_Third' => 'MAYB', 'BankName' => 'Maybank'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
        ]);

        $this->agentCashFlow = $this->makeAgentCashFlow($this->infoMyrAuto);
    }

    private function makeAgentCashFlow(array $info): AgentCashFlow
    {
        $agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'admin' => 0,
            'info' => json_encode($info),
        ]);

        $agentCashFlow->info = $info;

        return $agentCashFlow;
    }

    private function makeWithdrawalOrder(string $bankCode = 'MAYB'): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => $bankCode], [
            'name' => 'Maybank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => $bankCode,
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    /**
     * 正常提交代付，建立 PayoutRecord 並回成功
     */
    public function test_success_creates_payout_record(): void
    {
        $withdrawalOrder = $this->makeWithdrawalOrder();

        Http::fake([
            '*' => Http::response([
                'code' => '0',
                'refId' => 'W0013',
                'amount' => 100,
                'amountToWithdraw' => 98.00,
            ], 200),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))
            ->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertSame('W0013', $result->providerOrderNo);

        // PayoutRecord 已建立，transaction_no 即送往三方的 orderId；
        // 同步請求成功僅代表三方受理，維持 PENDING 等回調定終態
        $payoutRecord = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->first();
        $this->assertNotNull($payoutRecord);
        $this->assertSame('W0013', $payoutRecord->third_party_transaction_no);
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);

        // orderId === 我方 transaction_no；MYR Auto → data 帶 toBankCode
        Http::assertSent(function ($request) use ($payoutRecord) {
            $body = $request->data();
            $data = json_decode($body['data'], true);

            return $body['orderId'] === $payoutRecord->transaction_no
                && $body['currency'] === 'MYR'
                && isset($body['securityToken'])
                && ($data['toBankCode'] ?? null) === 'MAYB'
                && ($data['toAccNo'] ?? null) === '1234567890';
        });
    }

    /**
     * MYR Manual（providerId=10, providerType=10）→ data 帶 bankName
     */
    public function test_myr_manual_channel_uses_bank_name(): void
    {
        $info = $this->infoMyrAuto;
        $info['PROVIDER_ID'] = '10';
        $info['PROVIDER_TYPE'] = '10';
        $agentCashFlow = $this->makeAgentCashFlow($info);

        $withdrawalOrder = $this->makeWithdrawalOrder();

        Http::fake([
            '*' => Http::response(['code' => '0', 'refId' => 'W0099'], 200),
        ]);

        (new Withdrawal($agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        Http::assertSent(function ($request) {
            $data = json_decode($request->data()['data'], true);

            return isset($data['bankName'], $data['accNo'], $data['accName'])
                && !isset($data['toBankCode']);
        });
    }

    /**
     * 第三方回傳錯誤代碼，回 FailureResponse
     */
    public function test_api_error_response(): void
    {
        $withdrawalOrder = $this->makeWithdrawalOrder();

        Http::fake([
            '*' => Http::response(['code' => '-140', 'description' => 'Duplicate order id'], 200),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))
            ->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Duplicate order id', $result->errorMessage);

        // 三方同步拒絕 → 記錄標 THIRD_PARTY_REJECTED(-2)
        $payoutRecord = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $payoutRecord->is_success_payout);
    }

    /**
     * 訂單不存在 → FailureResponse
     */
    public function test_order_not_found(): void
    {
        $result = (new Withdrawal($this->agentCashFlow))
            ->handle(new WithdrawalRequestData);

        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('not found', $result->errorMessage);
    }

    /**
     * HTTP 連線錯誤 → FailureResponse
     */
    public function test_http_connection_error(): void
    {
        $withdrawalOrder = $this->makeWithdrawalOrder();

        Http::fake([
            '*' => Http::response([], 500),
        ]);

        $result = (new Withdrawal($this->agentCashFlow))
            ->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($result->isSuccess);

        // 請求層失敗（未取得三方回應）→ 記錄標 REQUEST_FAILED(-1)，pending 提前建立故仍留痕
        $payoutRecord = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * SGD V2：走 /v2/withdraw、帶 recipientInfo（無 data）、V2 簽名
     */
    public function test_sgd_v2_uses_v2_endpoint_recipient_info_and_signature(): void
    {
        $agentCashFlow = $this->makeAgentCashFlow($this->infoSgdV2);
        $withdrawalOrder = $this->makeWithdrawalOrder('DBS');

        Http::fake([
            '*' => Http::response(['code' => '0', 'refId' => 'W0500'], 200),
        ]);

        $result = (new Withdrawal($agentCashFlow))
            ->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertTrue($result->isSuccess);

        Http::assertSent(function ($request) {
            $body = $request->data();

            if (!str_ends_with($request->url(), '/v2/withdraw')) {
                return false;
            }
            if (!isset($body['recipientInfo']) || isset($body['data'])) {
                return false;
            }
            $recipient = json_decode($body['recipientInfo'], true);
            $expectedSign = strtolower(md5(
                $body['opCode'] . $body['orderId'] . $body['currency'] . $body['amount']
                . $body['callbackUrl'] . $body['reqDateTime'] . $body['recipientInfo']
                . 'test-merchant-key-abc'
            ));

            return ($recipient['receiverFirstName'] ?? null) === 'Test User'
                && ($recipient['receiverNo'] ?? null) === '1234567890'
                && ($recipient['bankCode'] ?? null) === 'DBS'
                && $body['securityToken'] === $expectedSign;
        });
    }
}
