<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Kira;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\Kira\Deposit;
use Tests\TestCase;

/**
 * Kira 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositTest.php
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

        // 建立 Currency
        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://example.invalid',
            'CLIENT_ID' => 'test_client_id',
            'CLIENT_SECRET' => 'test_client_secret',
            'CLIENT_PRIVATE_KEY' => 'test_private_key',
            'CLIENT_SYMMETRIC_KEY' => '0123456789abcdef0123456789abcdef',
            'SERVER_PUBLIC_KEY' => 'test_public_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 100,
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
     * 測試缺少必要配置參數（CLIENT_ID）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositTest.php --filter test_missing_client_id
     */
    public function test_missing_client_id()
    {
        // Arrange
        $info = [
            'API_URL' => 'https://example.invalid',
            'CLIENT_SECRET' => 'test_client_secret',
            'CLIENT_PRIVATE_KEY' => 'test_key',
            'CLIENT_SYMMETRIC_KEY' => 'test_symmetric',
            'SERVER_PUBLIC_KEY' => 'test_public_key',
        ];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 100,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $orderType = OrderType::create([
            'cash_flow_id' => 100,
            'currency_id' => 1,
            'name' => 'Kira',
            'type' => 1,
            'status' => 1,
            'key' => 'KIRA.FPX',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'KIRA_20240102_001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $deposit = new Deposit($agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Kira CLIENT_ID not configured');

        // Act
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試缺少必要配置參數（API_URL）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositTest.php --filter test_missing_api_url
     */
    public function test_missing_api_url()
    {
        // Arrange
        $info = [
            'CLIENT_ID' => 'test_client_id',
            'CLIENT_SECRET' => 'test_client_secret',
            'CLIENT_PRIVATE_KEY' => 'test_key',
            'CLIENT_SYMMETRIC_KEY' => 'test_symmetric',
            'SERVER_PUBLIC_KEY' => 'test_public_key',
        ];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 100,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $orderType = OrderType::create([
            'cash_flow_id' => 100,
            'currency_id' => 1,
            'name' => 'Kira',
            'type' => 1,
            'status' => 1,
            'key' => 'KIRA.FPX',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'KIRA_20240103_001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $deposit = new Deposit($agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Kira API_URL not configured');

        // Act
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試缺少必要配置參數（SERVER_PUBLIC_KEY）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositTest.php --filter test_missing_payment_url
     */
    public function test_missing_payment_url()
    {
        // Arrange - 移除，改成驗證缺少 SERVER_PUBLIC_KEY 的處理
        $info = [
            'API_URL' => 'https://example.invalid',
            'CLIENT_ID' => 'test_client_id',
            'CLIENT_SECRET' => 'test_client_secret',
            'CLIENT_PRIVATE_KEY' => 'test_key',
            'CLIENT_SYMMETRIC_KEY' => 'test_symmetric',
            // 缺少 SERVER_PUBLIC_KEY
        ];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 100,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $orderType = OrderType::create([
            'cash_flow_id' => 100,
            'currency_id' => 1,
            'name' => 'Kira FPX',
            'type' => 1,
            'status' => 1,
            'key' => 'KIRA.FPX',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'KIRA_20240104_001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '150.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $deposit = new Deposit($agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Kira SERVER_PUBLIC_KEY not configured');

        // Act
        $deposit->handle($depositRequestData);
    }
}
