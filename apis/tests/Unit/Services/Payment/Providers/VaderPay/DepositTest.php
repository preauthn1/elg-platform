<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VaderPay;

use App\Enums\DepositResponseType;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\VaderPay\Deposit;
use App\Services\Payment\Providers\VaderPay\VaderPaySignature;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * VaderPay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VaderPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR', 'name' => 'Malaysian Ringgit', 'rate' => 1, 'status' => 1, 'admin' => 0]));

        $info = [
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'merchant01',
            'API_KEY' => 'test_api_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 201,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
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
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
    }

    public function test_deposit_success(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 201,
            'currency_id' => 1,
            'name' => 'VaderPay Deposit',
            'type' => 2,
            'status' => 1,
            'key' => 'CIMB',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'VDP20240206001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake([
            '*/Payin/DepositV2' => Http::response([
                'error_code' => 0,
                'transaction' => '000-MY01-126-20-140',
                'status' => 0,
                'redirect_to' => 'https://example.invalid?TOKEN=[REDACTED]',
                'Amount' => 100.00,
                'Currency' => 'MYR',
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);
        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://merchant.test/callback',
            'returnUrl' => 'https://merchant.test/return',
            'ip' => '0.0.0.0',
        ]);

        $response = $deposit->handle($request);

        $this->assertSame(DepositResponseType::URL_REDIRECT, $response->type);
        $this->assertEquals('https://example.invalid?TOKEN=[REDACTED]', $response->url);
        $this->assertEquals($order->order_no, $response->orderNo);
        $this->assertEquals('000-MY01-126-20-140', $response->providerOrderNo);

        $order->refresh();
        $this->assertEquals('000-MY01-126-20-140', $order->third_party_order_no);
    }

    public function test_qr_deposit_hash_and_amount_format(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 201,
            'currency_id' => 1,
            'name' => 'VaderPay QR',
            'type' => 3,
            'status' => 1,
            'key' => 'QRIS',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'VDP20240206006',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake([
            '*/BankBot/QRScanDeposit/*' => Http::response([
                'error_code' => 0,
                'error_message' => 'Operation Success',
                'invoice_number' => 'INV-QR-0001',
                'currency_code' => 'MYR',
                'amount' => 100.00,
                'redirect_to' => 'https://example.invalid/QR?TOKEN=[REDACTED]',
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);
        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://merchant.test/callback',
            'returnUrl' => 'https://merchant.test/return',
            'ip' => '0.0.0.0',
        ]);

        $expectedHash = (new VaderPaySignature)->generatePayinQrEwalletHash(
            'merchant01',
            $order->order_no,
            $this->member->username,
            '0.0.0.0',
            'MYR',
            '100.00',
            'https://merchant.test/return',
            'test_api_key'
        );

        $deposit->handle($request);

        Http::assertSent(function ($request) use ($expectedHash) {
            return str_contains($request->url(), '/BankBot/QRScanDeposit/')
                && ($request->data()['amount'] ?? null) === '100.00'
                && ($request->data()['hash'] ?? null) === $expectedHash;
        });
    }

    public function test_ewallet_amount_must_be_whole_number(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 201,
            'currency_id' => 1,
            'name' => 'VaderPay Ewallet',
            'type' => 1,
            'status' => 1,
            'key' => 'DANA',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'VDP20240206007',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.50',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake();

        $deposit = new Deposit($this->agentCashFlow);
        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://merchant.test/callback',
            'returnUrl' => 'https://merchant.test/return',
            'ip' => '0.0.0.0',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('VaderPay ewallet amount must be a whole number');

        $deposit->handle($request);

        Http::assertNothingSent();
    }
}
