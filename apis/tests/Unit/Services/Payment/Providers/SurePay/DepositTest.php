<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\SurePay;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\SurePay\Deposit;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SurePay 代收通道參數測試
 *
 * 三方規格 v2.22 §4／§5：bankcode 與 contractname 二選一，
 * contractname 於未指定 bankcode 時必填。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/SurePay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://api.surepay.test',
            'MERCHANT_ID' => 'Surepay88',
            'CONTRACT_NAME' => 'Surepay88-OB',
            'API_KEY' => 'test_api_key',
            'CALLBACK_API_KEY' => 'test_callback_api_key',
            'CURRENCY' => 'MYR',
            'WITHDRAWAL_IBAN' => 'IBAN001',
        ];

        $this->agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 97,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createOrder(string $orderNo, string $orderTypeKey): Order
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 97,
            'currency_id' => 1,
            'name' => 'SurePay ' . $orderTypeKey,
            'type' => 1,
            'status' => 1,
            'key' => $orderTypeKey,
            'info' => '{}',
            'admin' => 0,
        ]);

        return Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => 0,
            'balance' => 0,
            'amount' => 100.00,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    private function createRequestData(Order $order): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.test/callback',
            'returnUrl' => 'https://example.test/return',
            'ip' => '0.0.0.0',
        ]);
    }

    /**
     * @param  callable(array<string, mixed>): void  $assert
     */
    private function fakeAndAssertPayload(callable $assert): void
    {
        Http::fake(function (HttpRequest $request) use ($assert) {
            $assert($request->data());

            return Http::response([
                'status' => 1,
                'message' => 'success',
                'trxid' => 2514,
                'redirecturl' => 'https://api.surepay.test/api/splink/token',
            ], 200);
        });
    }

    /**
     * 純數字 key 視為三方後台通道 ID，送 bankcode 且不送 contractname
     *
     * php artisan test tests/Unit/Services/Payment/Providers/SurePay/DepositTest.php --filter test_numeric_order_type_key_sends_bankcode
     */
    public function test_numeric_order_type_key_sends_bankcode(): void
    {
        $order = $this->createOrder('SUREPAY_DEP_001', '10022198');

        $this->fakeAndAssertPayload(function (array $data): void {
            $this->assertSame('10022198', $data['bankcode'] ?? null);
            $this->assertArrayNotHasKey('contractname', $data);
        });

        $result = (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));

        $this->assertSame('https://api.surepay.test/api/splink/token', $result->url);
    }

    /**
     * 非數字 key 不是合法 bankcode，改送 contractname 且不送 bankcode
     *
     * php artisan test tests/Unit/Services/Payment/Providers/SurePay/DepositTest.php --filter test_generic_order_type_key_sends_contractname
     */
    public function test_generic_order_type_key_sends_contractname(): void
    {
        $order = $this->createOrder('SUREPAY_DEP_002', 'OB');

        $this->fakeAndAssertPayload(function (array $data): void {
            $this->assertSame('Surepay88-OB', $data['contractname'] ?? null);
            $this->assertArrayNotHasKey('bankcode', $data);
        });

        (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));
    }

    /**
     * QR 與 Ewallet 同樣走 contractname，不因通道名稱而異
     *
     * php artisan test tests/Unit/Services/Payment/Providers/SurePay/DepositTest.php --filter test_qr_and_ewallet_keys_send_contractname
     */
    public function test_qr_and_ewallet_keys_send_contractname(): void
    {
        foreach (['QR', 'Ewallet'] as $index => $key) {
            $order = $this->createOrder('SUREPAY_DEP_01' . $index, $key);

            $this->fakeAndAssertPayload(function (array $data): void {
                $this->assertSame('Surepay88-OB', $data['contractname'] ?? null);
                $this->assertArrayNotHasKey('bankcode', $data);
            });

            (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));
        }
    }

    /**
     * 簽名欄位不含 bankcode／contractname，兩條路徑的 token 算法一致
     *
     * php artisan test tests/Unit/Services/Payment/Providers/SurePay/DepositTest.php --filter test_token_excludes_channel_params
     */
    public function test_token_excludes_channel_params(): void
    {
        $order = $this->createOrder('SUREPAY_DEP_020', '10022198');
        $orderType = $order->orderType;
        $tokens = [];

        // 同一筆訂單、只切換通道 key，token 不應改變
        foreach (['10022198', 'OB'] as $key) {
            $orderType->update(['key' => $key]);
            $order->refresh();

            $this->fakeAndAssertPayload(function (array $data) use (&$tokens): void {
                $tokens[] = $data['token'] ?? null;
            });

            (new Deposit($this->agentCashFlow))->handle($this->createRequestData($order));
        }

        $this->assertNotNull($tokens[0]);
        $this->assertSame($tokens[0], $tokens[1], '通道參數不進簽名，token 應不受影響');
    }
}
