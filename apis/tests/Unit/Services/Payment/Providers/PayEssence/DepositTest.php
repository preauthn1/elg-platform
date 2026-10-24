<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayEssence;

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
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\PayEssence\Deposit;
use Tests\TestCase;

/**
 * PayEssence 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositTest.php
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

        // 建立 Currency
        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'MERCHANT_CODE' => 'TEST_MERCHANT',
            'SECRET_KEY' => 'test_secret_key',
            'API_URL' => 'https://example.invalid',
            'COUNTRY' => 'MY',
        ];

        $this->agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
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

    /**
     * 測試成功創建代收請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE.BANKTRANSFER',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'PE123456789',
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

        $deposit = new Deposit($this->agentCashFlow);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals(DepositResponseType::HTML_FORM, $result->type);
        $this->assertNotEmpty($result->html);
        $this->assertEquals('PE123456789', $result->orderNo);
        $this->assertNull($result->url);

        // 驗證 HTML 包含必要元素
        $this->assertStringContainsString('form', $result->html);
        $this->assertStringContainsString('action="https://example.invalid/WebForm/Deposit.aspx"', $result->html);
        $this->assertStringContainsString('name="MerchantCode"', $result->html);
        $this->assertStringContainsString('value="TEST_MERCHANT"', $result->html);
        $this->assertStringContainsString('name="TransNum"', $result->html);
        $this->assertStringContainsString('value="PE123456789"', $result->html);
        $this->assertStringContainsString('name="Amount"', $result->html);
        $this->assertStringContainsString('value="100.00"', $result->html);
    }

    /**
     * 測試缺少必要配置參數（MERCHANT_CODE）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositTest.php --filter test_missing_merchant_code
     */
    public function test_missing_merchant_code()
    {
        // Arrange
        $info = [
            'SECRET_KEY' => 'test_secret_key',
            'API_URL' => 'https://example.invalid',
        ];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'PE123456792',
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
        $this->expectExceptionMessage('PayEssence merchant code, secret key or API URL not configured');

        // Act
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試缺少必要配置參數（SECRET_KEY）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositTest.php --filter test_missing_secret_key
     */
    public function test_missing_secret_key()
    {
        // Arrange
        $info = [
            'MERCHANT_CODE' => 'TEST_MERCHANT',
            'API_URL' => 'https://example.invalid',
        ];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'PE123456793',
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
        $this->expectExceptionMessage('PayEssence merchant code, secret key or API URL not configured');

        // Act
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試缺少必要配置參數（API_URL）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositTest.php --filter test_missing_api_url
     */
    public function test_missing_api_url()
    {
        // Arrange
        $info = [
            'MERCHANT_CODE' => 'TEST_MERCHANT',
            'SECRET_KEY' => 'test_secret_key',
        ];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'PE123456794',
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
        $this->expectExceptionMessage('PayEssence merchant code, secret key or API URL not configured');

        // Act
        $deposit->handle($depositRequestData);
    }
}
