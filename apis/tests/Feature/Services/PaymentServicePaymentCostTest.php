<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * payment_cost（支付商手續費）只在代收回調成功、PaymentService::updateMemberBalance 寫 status 3 時
 * 依渠道 cost × 最終 point 寫入；建單為 0 target/tickets#4361。
 *
 * php artisan test tests/Feature/Services/PaymentServicePaymentCostTest.php
 */
class PaymentServicePaymentCostTest extends TestCase
{
    #[Group('rules/deposit-order-fees#R-4')]
    #[Group('rules/deposit-order-fees#R-5')]
    public function test_payment_cost_is_written_when_deposit_succeeds(): void
    {
        // updateMemberBalance 會讀代理設定（多錢包／流水倍數／VIP），測試庫只有 schema，定義要自己 seed
        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $agentCashFlow->update(['cost' => 0.02]); // 2%
        $member = $this->createMember($agent, ['username' => 'pc_ok_' . uniqid()]);

        $order = app(OrderService::class)->createOrder($orderType, $agentCashFlow, $member, '100.5000');
        $this->assertNotNull($order);
        $this->assertSame('0.0000', (string) $order->fresh()->payment_cost, '建單不算成本');

        // 回調端把訂單標為 SUCCESS（成功未上分）後交給 updateMemberBalance 上分
        $order->update(['status' => OrderStatusEnum::SUCCESS->value]);
        app(PaymentService::class)->updateMemberBalance($order);

        $fresh = Order::query()->find($order->id);
        $this->assertSame(OrderStatusEnum::DEPOSIT_FINISHED->value, (int) $fresh->status);
        $this->assertSame('2.0100', (string) $fresh->payment_cost); // 100.5 × 0.02
    }
}
