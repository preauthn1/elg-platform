<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\CashFlowTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Helpers\Common;
use App\Models\Order;
use App\Models\OrderImage;
use App\Services\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OrderService 測試
 *
 * php artisan test tests/Unit/Services/OrderServiceTest.php
 */
class OrderServiceTest extends TestCase
{
    private OrderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'osvc-' . uniqid('', true) . '.local';
        $this->service = new OrderService;
    }

    public function test_get_order_no_matches_expected_pattern(): void
    {
        $no = $this->service->getOrderNo();

        $this->assertMatchesRegularExpression('/^\d{8}\d{6}\d{4}$/', $no);
        $this->assertSame(18, strlen($no));
    }

    public function test_get_latest_order_returns_null_when_no_matching_agent_cash_flow(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'ord_' . uniqid()]);

        $result = $this->service->getLatestOrder($member, CashFlowTypeEnum::DEPOSIT);

        $this->assertNull($result);
    }

    public function test_get_latest_order_returns_null_when_order_has_no_images(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $member = $this->createMember($agent, ['username' => 'ord_img_' . uniqid()]);

        Order::factory()->create([
            'member_id' => $member->id,
            'agent_id' => 0,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $orderType->currency_id,
            'order_no' => 'ONO' . Str::random(20),
            'third_party_order_no' => '',
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);

        $member->refresh();
        $result = $this->service->getLatestOrder($member, CashFlowTypeEnum::DEPOSIT);

        $this->assertNull($result);
    }

    public function test_get_latest_order_returns_latest_matching_order(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $member = $this->createMember($agent, ['username' => 'ord_ok_' . uniqid()]);

        $cacheKey = "agent_cash_flow_ids:{$agent->cash_flow_group_id}:" . CashFlowTypeEnum::DEPOSIT->value;
        Cache::forget($cacheKey);

        $older = Order::factory()->create([
            'member_id' => $member->id,
            'agent_id' => 0,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $orderType->currency_id,
            'order_no' => 'ONO' . Str::random(20),
            'third_party_order_no' => '',
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);
        OrderImage::create([
            'order_id' => $older->id,
            'bank_id' => 0,
            'image' => 'https://example.test/a.png',
            'admin' => 0,
        ]);

        $newer = Order::factory()->create([
            'member_id' => $member->id,
            'agent_id' => 0,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $orderType->currency_id,
            'order_no' => 'ONO' . Str::random(20),
            'third_party_order_no' => '',
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);
        OrderImage::create([
            'order_id' => $newer->id,
            'bank_id' => 0,
            'image' => 'https://example.test/b.png',
            'admin' => 0,
        ]);

        $member->refresh();
        $found = $this->service->getLatestOrder($member, CashFlowTypeEnum::DEPOSIT);

        $this->assertNotNull($found);
        $this->assertSame($newer->id, $found->id);
    }

    public function test_get_order_returns_deposit_order_with_relations(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $member = $this->createMember($agent, ['username' => 'ord_get_' . uniqid()]);
        $orderNo = 'GET' . Str::random(22);

        Order::factory()->create([
            'order_no' => $orderNo,
            'member_id' => $member->id,
            'agent_id' => 0,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $orderType->currency_id,
            'third_party_order_no' => '',
            'status' => OrderStatusEnum::CREATED->value,
            'admin' => 0,
        ]);

        $result = $this->service->getOrder('deposit', $orderNo);

        $this->assertInstanceOf(Order::class, $result);
        $this->assertSame($orderNo, $result->order_no);
        $this->assertTrue($result->relationLoaded('agentCashFlow'));
        $this->assertTrue($result->relationLoaded('orderType'));
    }

    public function test_get_order_returns_null_for_unknown_type(): void
    {
        $this->assertNull($this->service->getOrder('other', 'ANY123'));
    }

    public function test_create_order_for_member_sets_expected_fields(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $member = $this->createMember($agent, ['username' => 'ord_cr_' . uniqid()]);
        $amount = '100.5000';

        $order = $this->service->createOrder(
            $orderType,
            $agentCashFlow,
            $member,
            $amount,
            extraContent: ['k' => 'v'],
        );

        $this->assertNotNull($order);
        $this->assertSame($member->id, $order->member_id);
        $this->assertSame(0, $order->agent_id);
        $this->assertSame($orderType->id, $order->order_type_id);
        $this->assertSame($amount, (string) $order->amount);
        $this->assertSame(0, $order->status);
        // Factory 預設 tax=0、tax_percentage=0，故 point = amount × rate（scale=4 對齊 orders.point decimal(18,4)）
        $expectedPoint = Common::numSub(
            Common::numMul($amount, (string) ($agentCashFlow->rate ?? 1), 4),
            '0',
            4,
        );
        $this->assertSame($expectedPoint, (string) $order->point);
        $this->assertSame('0.0000', (string) $order->tax);
        // payment_cost 只在回調成功（PaymentService::updateMemberBalance）時寫入，建單為 0
        $this->assertSame('0.0000', (string) $order->fresh()->payment_cost);
        $this->assertSame('{"k":"v"}', $order->extra_content);
        $this->assertNotEmpty($order->order_no);
        $this->assertStringContainsString((string) $agentCashFlow->id, (string) $order->callback);
    }

    public function test_create_order_for_agent_sets_agent_id(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);

        $order = $this->service->createOrder(
            $orderType,
            $agentCashFlow,
            $agent,
            '50.0000',
        );

        $this->assertNotNull($order);
        $this->assertSame($agent->id, $order->agent_id);
        $this->assertSame(0, $order->member_id);
    }

    public function test_create_order_calculates_tax_with_fixed_and_percentage_fee(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);

        // rate 預設 1；固定手續費 2 + 百分比手續費 1%
        $agentCashFlow->update([
            'rate' => '1',
            'tax' => '2',
            'tax_percentage' => '0.01',
        ]);

        $member = $this->createMember($agent, ['username' => 'ord_tax_' . uniqid()]);

        $order = $this->service->createOrder(
            $orderType,
            $agentCashFlow,
            $member,
            '1000.0000',
        );

        // point_pre   = 1000 × 1 = 1000
        // percentage  = 1000 × 0.01 = 10
        // tax         = 2 + 10 = 12
        // point_final = 1000 - 12 = 988
        $this->assertNotNull($order);
        $this->assertSame('12.0000', (string) $order->tax);
        $this->assertSame('988.0000', (string) $order->point);
    }

    public function test_create_order_with_zero_tax_columns_keeps_full_point(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);

        $agentCashFlow->update([
            'rate' => '1',
            'tax' => '0',
            'tax_percentage' => '0',
        ]);

        $member = $this->createMember($agent, ['username' => 'ord_notax_' . uniqid()]);

        $order = $this->service->createOrder(
            $orderType,
            $agentCashFlow,
            $member,
            '500.0000',
        );

        $this->assertNotNull($order);
        $this->assertSame('0.0000', (string) $order->tax);
        $this->assertSame('500.0000', (string) $order->point);
    }

    public function test_create_order_with_only_percentage_fee(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);

        $agentCashFlow->update([
            'rate' => '1',
            'tax' => '0',
            'tax_percentage' => '0.02',
        ]);

        $member = $this->createMember($agent, ['username' => 'ord_pct_' . uniqid()]);

        $order = $this->service->createOrder(
            $orderType,
            $agentCashFlow,
            $member,
            '250.0000',
        );

        // tax         = 0 + 250 × 0.02 = 5
        // point_final = 250 - 5 = 245
        $this->assertNotNull($order);
        $this->assertSame('5.0000', (string) $order->tax);
        $this->assertSame('245.0000', (string) $order->point);
    }

    public function test_create_order_with_only_fixed_fee(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);

        $agentCashFlow->update([
            'rate' => '1',
            'tax' => '3.5',
            'tax_percentage' => '0',
        ]);

        $member = $this->createMember($agent, ['username' => 'ord_fix_' . uniqid()]);

        $order = $this->service->createOrder(
            $orderType,
            $agentCashFlow,
            $member,
            '100.0000',
        );

        $this->assertNotNull($order);
        $this->assertSame('3.5000', (string) $order->tax);
        $this->assertSame('96.5000', (string) $order->point);
    }
}
