<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Enums\CashFlowEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Statistics\MemberHourlySourceEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Jobs\UpdateMemberHourSummary;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 驗證代收/代付 Observer 在 status 轉成功時 dispatch 匯總 Job，且只觸發一次（防重複計）；
 * 4333 六類來源：代收依金流商是否 AutoDeposit 標三方充值／自助上分，代付依 24／4 標三方提現／自助洗分。
 *
 * php artisan test tests/Feature/Observers/HourSummaryObserverTest.php
 */
class HourSummaryObserverTest extends TestCase
{
    public function test_deposit_success_dispatches_summary_job_once_as_third_party_deposit(): void
    {
        Queue::fake();

        $order = Order::factory()->create([
            'member_id' => 990101,
            'order_type_id' => 0,
            'admin' => 0,
            'point' => 120,
            'status' => OrderStatusEnum::CREATED->value,
        ]);

        // 建立時非成功 → 不 dispatch
        Queue::assertNotPushed(UpdateMemberHourSummary::class);

        // status 轉成功 → dispatch 一次，來源＝三方充值（order_type_id=0 沒有金流商）
        $order->update(['status' => OrderStatusEnum::DEPOSIT_FINISHED->value]);
        Queue::assertPushed(UpdateMemberHourSummary::class, 1);
        Queue::assertPushed(UpdateMemberHourSummary::class, fn (UpdateMemberHourSummary $job) => $job->type === UpdateMemberHourSummary::TYPE_DEPOSIT
            && $job->source === MemberHourlySourceEnum::THIRD_PARTY_DEPOSIT->value);

        // 成功後再更新其他欄位（status 未變）→ 不重複 dispatch
        $order->update(['remark' => 'changed']);
        Queue::assertPushed(UpdateMemberHourSummary::class, 1);
    }

    public function test_auto_deposit_order_dispatches_as_auto_deposit(): void
    {
        Queue::fake();

        $autoOrderTypeId = (int) DB::table('order_types')->insertGetId([
            'cash_flow_id' => CashFlowEnum::DEPOSIT_AUTO_DEPOSIT,
            'parent' => 0,
            'name' => 'test auto deposit',
            'key' => 'test_auto_observer',
            'type' => 2,
            'currency_id' => 1,
            'admin' => 0,
        ]);

        $order = Order::factory()->create([
            'member_id' => 990103,
            'order_type_id' => $autoOrderTypeId,
            'admin' => 0,
            'point' => 200,
            'status' => OrderStatusEnum::SUCCESS->value, // 上傳憑證待審核
        ]);

        // 後台審核通過 → 自助上分
        $order->update(['status' => OrderStatusEnum::DEPOSIT_FINISHED->value]);
        Queue::assertPushed(UpdateMemberHourSummary::class, fn (UpdateMemberHourSummary $job) => $job->source === MemberHourlySourceEnum::AUTO_DEPOSIT->value);
    }

    public function test_third_party_withdrawal_success_dispatches_once_as_third_party_withdrawal(): void
    {
        Queue::fake();

        $order = WithdrawalOrder::factory()->create([
            'member_id' => 990102,
            'point' => 80,
            'status' => WithdrawalOrderStatusEnum::ORDER_CREATED->value,
        ]);

        $order->update(['status' => WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, 'payment_time' => '2026-03-01 10:20:00']);
        Queue::assertPushed(UpdateMemberHourSummary::class, 1);
        Queue::assertPushed(UpdateMemberHourSummary::class, fn (UpdateMemberHourSummary $job) => $job->type === UpdateMemberHourSummary::TYPE_WITHDRAWAL
            && $job->source === MemberHourlySourceEnum::THIRD_PARTY_WITHDRAWAL->value
            && $job->occurredAt === '2026-03-01 10:20:00');

        $order->update(['memo' => 'changed']);
        Queue::assertPushed(UpdateMemberHourSummary::class, 1);
    }

    public function test_transferred_withdrawal_dispatches_as_auto_withdrawal(): void
    {
        Queue::fake();

        $order = WithdrawalOrder::factory()->create([
            'member_id' => 990104,
            'point' => 50,
            'status' => WithdrawalOrderStatusEnum::APPROVED->value,
        ]);

        // 後台「已轉帳」→ 自助洗分
        $order->update(['status' => WithdrawalOrderStatusEnum::TRANSFERRED->value, 'payment_time' => '2026-03-01 11:05:00']);
        Queue::assertPushed(UpdateMemberHourSummary::class, fn (UpdateMemberHourSummary $job) => $job->source === MemberHourlySourceEnum::AUTO_WITHDRAWAL->value);
    }

    public function test_agent_self_orders_with_member_id_zero_never_dispatch(): void
    {
        Queue::fake();

        $order = WithdrawalOrder::factory()->create([
            'member_id' => 0,
            'point' => 500,
            'status' => WithdrawalOrderStatusEnum::APPROVED->value,
        ]);
        $order->update(['status' => WithdrawalOrderStatusEnum::TRANSFERRED->value]);

        Queue::assertNotPushed(UpdateMemberHourSummary::class);
    }
}
