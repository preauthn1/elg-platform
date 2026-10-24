<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2PeriodEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2Recycle;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\MemberWallet;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 紅包週期到期重置 AngpowPeriodResetService（target/tickets#4234）：依各檔活動的會員週期 member_period 重置上個週期還停在 JOIN 的紀錄。
 * 正式觸發路徑是既有的 app:init-event-v2（紅包類型列 schedule 0 16 * * *），event-v2:reset-period-joins 是手動／排查入口，兩條都驗。
 *
 * 必須成立：
 * 1. member_period=DAILY 的活動，前台時區今天 00:00 之前參加、還在 JOIN 的紀錄被重置：
 *    狀態 SYSTEM_INIT、活動錢包歸零、寫回收紀錄。作廢與「今天抽過沒」是同一條換日線。
 * 2. 今天參加的不動；member_period=NONE 的活動不動；非 JOIN 狀態不動。
 * 3. --dry-run 只列出、不寫入。
 * 4. app:init-event-v2 對紅包類型只清上一期（今天的不動）；對其他類型維持原本「所有 JOIN 全清」，不能被紅包改壞。
 *
 * php artisan test tests/Feature/Console/ResetExpiredPeriodJoinsTest.php
 */
class ResetExpiredPeriodJoinsTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'rpj' . bin2hex(random_bytes(5))]);
        MemberWallet::query()->updateOrCreate(
            ['member_id' => $this->member->id, 'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET->value],
            ['balance' => 50],
        );
    }

    public function test_resets_last_period_joins_only(): void
    {
        $daily = $this->createDetail(EventV2PeriodEnum::DAILY);
        $none = $this->createDetail(EventV2PeriodEnum::NONE);
        $yesterday = $this->frontendDayStart()->subMinute()->toSystemTime();
        $today = $this->frontendDayStart()->addMinute()->toSystemTime();

        $expired = $this->createJoin($daily, $yesterday);
        $fresh = $this->createJoin($daily, $today);
        $noPeriod = $this->createJoin($none, $yesterday);
        $completed = $this->createJoin($daily, $yesterday, EventV2UserJoinStatusEnum::COMPLETE);

        $this->artisan('event-v2:reset-period-joins')->assertExitCode(0);

        $this->assertSame(EventV2UserJoinStatusEnum::SYSTEM_INIT->value, $expired->fresh()->status, '昨天的 JOIN 被重置');
        $this->assertNotNull($expired->fresh()->end_time);
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, $fresh->fresh()->status, '今天的不動');
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, $noPeriod->fresh()->status, '不分週期的不動');
        $this->assertSame(EventV2UserJoinStatusEnum::COMPLETE->value, $completed->fresh()->status, '非 JOIN 不動');

        $this->assertSame('0.0000', $this->eventWalletBalance(), '活動錢包歸零');
        $this->assertSame(1, EventV2Recycle::query()->where('event_v2_user_join_id', $expired->id)->count(), '寫回收紀錄');
    }

    public function test_weekly_and_monthly_boundaries(): void
    {
        $weekly = $this->createDetail(EventV2PeriodEnum::WEEKLY);
        $monthly = $this->createDetail(EventV2PeriodEnum::MONTHLY);
        $frontendNow = Carbon::now()->toFrontendTime();

        $lastWeek = $this->createJoin($weekly, $frontendNow->copy()->startOfWeek()->subMinute()->toSystemTime());
        $thisWeek = $this->createJoin($weekly, $frontendNow->copy()->startOfWeek()->addMinute()->toSystemTime());
        $lastMonth = $this->createJoin($monthly, $frontendNow->copy()->startOfMonth()->subMinute()->toSystemTime());
        $thisMonth = $this->createJoin($monthly, $frontendNow->copy()->startOfMonth()->addMinute()->toSystemTime());

        $this->artisan('event-v2:reset-period-joins')->assertExitCode(0);

        $this->assertSame(EventV2UserJoinStatusEnum::SYSTEM_INIT->value, $lastWeek->fresh()->status);
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, $thisWeek->fresh()->status);
        $this->assertSame(EventV2UserJoinStatusEnum::SYSTEM_INIT->value, $lastMonth->fresh()->status);
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, $thisMonth->fresh()->status);
    }

    /**
     * 作廢的換日線＝前台時區 00:00，跟「今天抽過沒」同一條：台灣 10/2 01:30 跑排程，
     * 台灣 10/1 23:59 抽的要作廢、台灣 10/2 00:30 抽的（算今天）不能動。
     * 測試環境前台時區預設 UTC，這裡設成 +8 才驗得出跟系統時間的差別。
     */
    public function test_daily_resets_at_frontend_midnight_not_utc(): void
    {
        config(['custom.frontend.timezone' => 'Asia/Taipei']);
        $daily = $this->createDetail(EventV2PeriodEnum::DAILY);
        Carbon::setTestNow(Carbon::parse('2026-10-01 17:30:00', 'UTC')); // 台灣 10/02 01:30

        $yesterdayTaiwan = $this->createJoin($daily, Carbon::parse('2026-10-01 15:59:00', 'UTC')); // 台灣 10/01 23:59
        $todayTaiwan = $this->createJoin($daily, Carbon::parse('2026-10-01 16:30:00', 'UTC')); // 台灣 10/02 00:30

        try {
            $this->artisan('event-v2:reset-period-joins')->assertExitCode(0);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(EventV2UserJoinStatusEnum::SYSTEM_INIT->value, $yesterdayTaiwan->fresh()->status, '台灣昨天的被作廢');
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, $todayTaiwan->fresh()->status, '台灣今天 00:30 抽的不作廢（UTC 還是昨天）');
    }

    /** 正式路徑：check:event_v2_schedule 到點呼叫 app:init-event-v2，紅包類型要轉成依週期清，而不是整個類型全清 */
    public function test_init_event_v2_resets_angpow_by_period_not_all(): void
    {
        $daily = $this->createDetail(EventV2PeriodEnum::DAILY);
        $expired = $this->createJoin($daily, $this->frontendDayStart()->subMinute()->toSystemTime());
        $fresh = $this->createJoin($daily, $this->frontendDayStart()->addMinute()->toSystemTime());

        $this->artisan('app:init-event-v2', ['--eventV2Id' => $daily->event_v2_id])->assertExitCode(0);

        $this->assertSame(EventV2UserJoinStatusEnum::SYSTEM_INIT->value, $expired->fresh()->status, '上一期的作廢');
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, $fresh->fresh()->status, '今天抽的不能被類型重置清掉');
        $this->assertSame(1, EventV2Recycle::query()->where('event_v2_user_join_id', $expired->id)->count());
    }

    /** 迴歸：其他類型走 app:init-event-v2 仍是「該類型所有 JOIN 全清」，不看 created_at，行為與改前相同 */
    public function test_init_event_v2_still_resets_every_join_for_other_types(): void
    {
        $dailyFree = $this->createDetail(EventV2PeriodEnum::NONE, 'DailyFreeService', '每日登入');
        $old = $this->createJoin($dailyFree, $this->frontendDayStart()->subMinute()->toSystemTime());
        $today = $this->createJoin($dailyFree, $this->frontendDayStart()->addMinute()->toSystemTime());

        // 這條就是抓到正式站 bug 的測試：select 少了 event_v2_detail_id 時 initEventV2() 讀不到 eventV2Detail，兩筆都會留在 JOIN
        $this->artisan('app:init-event-v2', ['--eventV2Id' => $dailyFree->event_v2_id])->assertExitCode(0);

        $this->assertSame(EventV2UserJoinStatusEnum::SYSTEM_INIT->value, $old->fresh()->status);
        $this->assertSame(EventV2UserJoinStatusEnum::SYSTEM_INIT->value, $today->fresh()->status, '非紅包：今天的也清，原行為');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $daily = $this->createDetail(EventV2PeriodEnum::DAILY);
        $expired = $this->createJoin($daily, $this->frontendDayStart()->subMinute()->toSystemTime());

        $this->artisan('event-v2:reset-period-joins', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertExitCode(0);

        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, $expired->fresh()->status);
        $this->assertSame('50.0000', $this->eventWalletBalance());
    }

    // -------------------------------------------------------

    private function createDetail(EventV2PeriodEnum $period, string $handler = 'AngpowService', string $typeName = '紅包'): EventV2Detail
    {
        $typeId = EventV2::query()->firstOrCreate(['handler_class' => $handler], ['name' => $typeName, 'schedule' => '0 16 * * *'])->id;

        return EventV2Detail::factory()->create([
            'event_v2_id' => $typeId,
            'agent_id' => $this->agent->id,
            'condition_config' => ['score' => 10],
            'reward_formula' => '20',
            'withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value,
            'member_period' => $period->value,
            'join_withdraw' => 0,
            'is_recycle' => 1,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ]);
    }

    /**
     * Observer 讀前台 user context（測試沒有），關掉 events 建。
     * created_at 在 Model 的 $guarded 裡，create() 會丟掉，建好再 forceFill 回填成指定時間。
     */
    private function createJoin(EventV2Detail $detail, Carbon $createdAt, EventV2UserJoinStatusEnum $status = EventV2UserJoinStatusEnum::JOIN): EventV2UserJoin
    {
        return EventV2UserJoin::withoutEvents(function () use ($detail, $createdAt, $status) {
            $join = EventV2UserJoin::create([
                'event_v2_detail_id' => $detail->id,
                'member_id' => $this->member->id,
                'reward' => 20,
                'withdraw' => 0,
                'withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value,
                'status' => $status->value,
            ]);
            $join->forceFill(['created_at' => $createdAt->toDateTimeString()])->save();

            return $join->fresh();
        });
    }

    private function frontendDayStart(): Carbon
    {
        return Carbon::now()->toFrontendTime()->startOfDay();
    }

    private function eventWalletBalance(): string
    {
        $balance = MemberWallet::query()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_EVENT_WALLET->value)
            ->value('balance');

        return number_format((float) ($balance ?? 0), 4, '.', '');
    }
}
