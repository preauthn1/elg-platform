<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\ZPay;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\ZPay\Deposit;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ZPay Deposit 單元測試
 *
 * 測試 ZPay 代收回傳 Domain 的處理
 *
 * php artisan test tests/Unit/Services/Payment/Providers/ZPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private OrderType $orderType;

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
            'API_URL' => 'https://s.zpay.mom',
            'TOKEN' => 'test_token',
            'SECRET_KEY' => 'test_secret_key',
        ];

        $this->agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 110,
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

        $this->orderType = OrderType::create([
            'cash_flow_id' => 110,
            'currency_id' => 1,
            'name' => 'ZPay Deposit MYR',
            'type' => 1,
            'status' => 1,
            'key' => 'PBE',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    public function test_invalid_domain_url_throws_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'Code' => 1,
                'Message' => 'Success',
                'Data' => [
                    'Status' => 1,
                    'Domain' => base64_encode('not-a-url'),
                    'TransactionId' => 0,
                ],
            ], 200),
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'ZPAY_TEST_001',
            'status' => 0,
            'balance' => 0,
            'amount' => 10.0,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $requestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.test/callback',
            'returnUrl' => 'https://example.test/return',
            'ip' => '0.0.0.0',
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('invalid domain url');

        $deposit->handle($requestData);
    }

    public function test_empty_bank_code_not_sent_in_request(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 110,
            'currency_id' => 1,
            'name' => 'ZPay Deposit Empty BankCode',
            'type' => 1,
            'status' => 1,
            'key' => '',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'ZPAY_TEST_EMPTY_BANK',
            'status' => 0,
            'balance' => 0,
            'amount' => 10.0,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertIsArray($data);
            $this->assertArrayNotHasKey('BankCode', $data);

            return Http::response([
                'Code' => 1,
                'Message' => 'Success',
                'Data' => [
                    'Status' => 1,
                    'Domain' => base64_encode('https://example.test/redirect'),
                    'TransactionId' => 0,
                ],
            ], 200);
        });

        $requestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.test/callback',
            'returnUrl' => 'https://example.test/return',
            'ip' => '0.0.0.0',
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $deposit->handle($requestData);
    }

    public function test_tng_bank_code_sent_in_request(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 110,
            'currency_id' => 1,
            'name' => 'ZPay Deposit TNG',
            'type' => 1,
            'status' => 1,
            'key' => 'TNG',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'ZPAY_TEST_TNG_BANK',
            'status' => 0,
            'balance' => 0,
            'amount' => 10.0,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake(function (HttpRequest $request) {
            $data = $request->data();
            $this->assertIsArray($data);
            $this->assertSame('TNG', $data['BankCode'] ?? null);

            return Http::response([
                'Code' => 1,
                'Message' => 'Success',
                'Data' => [
                    'Status' => 1,
                    'Domain' => base64_encode('https://example.test/redirect'),
                    'TransactionId' => 0,
                ],
            ], 200);
        });

        $requestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.test/callback',
            'returnUrl' => 'https://example.test/return',
            'ip' => '0.0.0.0',
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $deposit->handle($requestData);
    }

    public function test_link_back_url_uses_request_return_url(): void
    {
        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'ZPAY_TEST_RETURN_URL',
            'status' => 0,
            'balance' => 0,
            'amount' => 10.0,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $returnUrl = 'https://example.invalid/Deposit#result';
        // ZPay appends ? before # for HTTP GET return
        $expectedLinkBackUrl = 'https://example.invalid/Deposit?#result';

        Http::fake(function (HttpRequest $request) use ($expectedLinkBackUrl) {
            $data = $request->data();
            $this->assertIsArray($data);
            $this->assertSame($expectedLinkBackUrl, $data['LinkBackUrl'] ?? null);

            return Http::response([
                'Code' => 1,
                'Message' => 'Success',
                'Data' => [
                    'Status' => 1,
                    'Domain' => base64_encode('https://example.invalid/pay'),
                    'TransactionId' => 12345,
                ],
            ], 200);
        });

        $requestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.test/callback',
            'returnUrl' => $returnUrl,
            'ip' => '0.0.0.0',
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $deposit->handle($requestData);
    }
}
