<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\OrderStatusEnum;
use App\Models\Member;
use App\Models\Order;
use App\Services\MemberService;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * MemberService 存款每日彙總測試（訂單口徑：未完成單佔額度，防「連續建單」繞過每日限制）。
 *
 * php artisan test tests/Unit/Services/MemberServiceDepositDailyTest.php
 */
class MemberServiceDepositDailyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->domain = 'msdd-' . uniqid('', true) . '.local';
    }

    private function makeOrder(Member $member, int $status, string $point): Order
    {
        // OrderFactory 未涵蓋的必填欄位（無 DB default）一併補齊
        return Order::factory()->create([
            'member_id' => $member->id,
            'status' => $status,
            'point' => $point,
            'amount' => $point,
            'order_type_id' => 0,
            'agent_cash_flow_id' => 0,
            'tax' => 0,
            'callback' => '',
            'ip' => '',
            'admin' => 0,
        ]);
    }

    #[TestDox('今日存款次數＝訂單口徑：未完成單與已上分皆計，僅排除 FAILED')]
    public function test_deposit_count_uses_order_scope(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->makeOrder($member, OrderStatusEnum::CREATED->value, '100');
        $this->makeOrder($member, OrderStatusEnum::SUCCESS->value, '100');
        $this->makeOrder($member, OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, '100');
        $this->makeOrder($member, OrderStatusEnum::DEPOSIT_FINISHED->value, '100');
        $this->makeOrder($member, OrderStatusEnum::FAILED->value, '100');

        $this->assertSame(4, app(MemberService::class)->getTodayDepositCount($member));
    }

    #[TestDox('今日存款金額含未完成單 point；FAILED 不佔額度、已上分改由 ledger 計不入未完成口徑')]
    public function test_deposit_amount_includes_pending_orders(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->makeOrder($member, OrderStatusEnum::CREATED->value, '100.5000');
        $this->makeOrder($member, OrderStatusEnum::SUCCESS->value, '200.0000');
        $this->makeOrder($member, OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, '50.0000');
        $this->makeOrder($member, OrderStatusEnum::FAILED->value, '999');
        // 已上分單不入未完成口徑（實際由 ledger 計；本測試無 ledger row，故不計入）
        $this->makeOrder($member, OrderStatusEnum::DEPOSIT_FINISHED->value, '888');

        $this->assertSame('350.5000', app(MemberService::class)->getTodayDepositAmount($member));
    }
}
