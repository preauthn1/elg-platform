<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Models\Order;
use App\Services\Agent\MemberReferralInfoService;
use Tests\TestCase;

/**
 * 直屬會員紀錄的首存人數：期間內「第一筆成功儲值」落在區間內的直屬下線才算
 *
 * php artisan test tests/Feature/Agent/MemberReferralInfoFirstDepositTest.php
 */
class MemberReferralInfoFirstDepositTest extends TestCase
{
    private const string START = '2026-10-01 00:00:00';

    private const string END = '2026-10-20 23:59:59';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
    }

    public function test_counts_only_downlines_whose_first_deposit_is_in_range(): void
    {
        $topper = $this->createMember($this->agent, ['username' => 'topper_a']);
        $otherTopper = $this->createMember($this->agent, ['username' => 'topper_b']);

        // 首儲在區間內 → 算
        $inRange = $this->downline($topper, 'down_in_range');
        $this->paidOrder($inRange, '2026-10-05 10:00:00');
        $this->paidOrder($inRange, '2026-10-06 10:00:00');

        // 首儲在區間前、區間內又儲值 → 不算
        $before = $this->downline($topper, 'down_before');
        $this->paidOrder($before, '2026-09-20 10:00:00');
        $this->paidOrder($before, '2026-10-07 10:00:00');

        // 區間內只有未完成的訂單 → 不算
        $unpaid = $this->downline($topper, 'down_unpaid');
        $this->order($unpaid, OrderStatusEnum::CREATED->value, '2026-10-08 10:00:00');

        // 另一位上線的直屬下線 → 只算到自己的上線
        $other = $this->downline($otherTopper, 'down_other');
        $this->paidOrder($other, '2026-10-09 10:00:00');

        $admin = new Agent;
        $admin->id = 1;
        $admin->host_id = 0;

        $rows = (new MemberReferralInfoService)
            ->list($admin, ['agent_username' => $this->agent->username], self::START, self::END, 1, 50)
            ->getCollection()
            ->keyBy('username');

        $this->assertSame(1, $rows['topper_a']->first_deposit_count);
        $this->assertSame(1, $rows['topper_b']->first_deposit_count);
        $this->assertSame(0, $rows['down_in_range']->first_deposit_count, '沒有直屬下線的會員應為 0');
    }

    private function downline(Member $topper, string $username): Member
    {
        $member = $this->createMember($this->agent, ['username' => $username]);

        MemberAgent::create([
            'member_id' => $member->id,
            'topper_member_id' => $topper->id,
            'parent_id' => $this->agent->id,
            'agent_mode' => 1,
            'agent_level' => 2,
            'agent_relation' => "0,{$topper->id},{$member->id},",
        ]);

        return $member;
    }

    private function paidOrder(Member $member, string $payTime): void
    {
        $this->order($member, OrderStatusEnum::DEPOSIT_FINISHED->value, $payTime);
    }

    private function order(Member $member, int $status, string $payTime): void
    {
        Order::factory()->create([
            'member_id' => $member->id,
            'agent_id' => $member->parent,
            'status' => $status,
            'pay_time' => $payTime,
            'order_type_id' => 1,
            'admin' => 0,
        ]);
    }
}
