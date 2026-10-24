<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\LivechatBotPromotionSourceEnum;
use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Services\EventV2\AbstractEventV2;
use App\Services\EventV2\EventV2Service;
use App\Services\LivechatBot\PromotionV2Builder;
use App\Services\MemberService;
use Database\Seeders\LanguageSeeder;
use Mockery;
use Tests\TestCase;

/**
 * Livechat Bot V2 Promotion Builder 測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/PromotionV2BuilderTest.php
 */
class PromotionV2BuilderTest extends TestCase
{
    private ?Agent $agent = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->agent = $this->createAgentAndDomain();
    }

    public function test_buildActive_returns_empty_when_no_join_records(): void
    {
        $member = $this->createMember($this->agent);

        $builder = new PromotionV2Builder(
            Mockery::mock(MemberService::class),
            Mockery::mock(EventV2Service::class),
        );

        $this->assertSame([], $builder->buildActive($member));
    }

    public function test_buildActive_returns_join_records_with_turnover_progress(): void
    {
        $member = $this->createMember($this->agent);
        $eventV2 = EventV2::create(['name' => 'Welcome Bonus', 'handler_class' => 'WelcomeBonus50Service']);
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
        ]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => EventV2Service::DEFAULT_LANGUAGE_id,
            'name' => 'Welcome Bonus 50%',
        ]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $member->id,
            'order_id' => 0,
            'reward' => 100,
            'balance_limit' => 300,
            'flow_limit' => 1500,
            'withdraw_limit' => 500,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $userJoin = EventV2UserJoin::where('member_id', $member->id)->where('event_v2_detail_id', $detail->id)->firstOrFail();

        $memberService = Mockery::mock(MemberService::class);
        $memberService->shouldReceive('getFlowLimit')->andReturn([
            'flow_limit' => 1500,
            'reach' => 200,
            'pass' => 0,
        ]);

        $builder = new PromotionV2Builder(
            $memberService,
            Mockery::mock(EventV2Service::class),
        );

        $result = $builder->buildActive($member);

        $this->assertCount(1, $result);
        $entry = $result[0];

        $this->assertSame(LivechatBotPromotionSourceEnum::V2->value, $entry['promotionSource']);
        $this->assertSame($detail->id, $entry['promotionId']);
        $this->assertSame('Welcome Bonus', $entry['promotionName']);
        $this->assertSame('Welcome Bonus 50%', $entry['promotionTitle']);
        $this->assertSame($userJoin->id, $entry['applyId']);
        $this->assertSame('JOIN', $entry['joinStatus']);
        $this->assertSame('100.0000', $entry['reward']);
        $this->assertSame('300.0000', $entry['balanceLimit']);
        $this->assertSame('1500.0000', $entry['flowLimit']);
        $this->assertSame('500.0000', $entry['withdrawLimit']);
        $this->assertSame('200.0000 / 1500.0000', $entry['turnoverProgress']);
        $this->assertIsArray($entry['message']);
    }

    public function test_buildActive_excludes_non_join_records(): void
    {
        $member = $this->createMember($this->agent);
        $eventV2 = EventV2::create(['name' => 'Closed', 'handler_class' => 'WelcomeBonus50Service']);
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
        ]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $member->id,
            'order_id' => 0,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $builder = new PromotionV2Builder(
            Mockery::mock(MemberService::class),
            Mockery::mock(EventV2Service::class),
        );

        $this->assertSame([], $builder->buildActive($member));
    }

    public function test_buildClaimable_returns_only_passing_details(): void
    {
        $member = $this->createMember($this->agent);
        $eventV2 = EventV2::create(['name' => 'Pass', 'handler_class' => 'WelcomeBonus50Service']);
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
            'condition_formula' => 'deposit >= 20',
            'reward_formula' => 'deposit * 0.5',
            'flow_formula' => 'deposit * 3',
            'withdraw_formula' => 'reward * 3',
        ]);

        $eventClass = Mockery::mock(AbstractEventV2::class);
        $eventClass->shouldReceive('getQualificationIssues')->andReturn([]);

        $eventV2Service = Mockery::mock(EventV2Service::class);
        $eventV2Service->shouldReceive('getEventClass')->with($detail->id)->andReturn($eventClass);

        $builder = new PromotionV2Builder(
            Mockery::mock(MemberService::class),
            $eventV2Service,
        );

        $result = $builder->buildClaimable($member);

        $this->assertCount(1, $result);
        $entry = $result[0];

        $this->assertSame(LivechatBotPromotionSourceEnum::V2->value, $entry['promotionSource']);
        $this->assertSame($detail->id, $entry['promotionId']);
        $this->assertSame('deposit >= 20', $entry['conditionFormula']);
        $this->assertSame('deposit * 0.5', $entry['rewardFormula']);
        $this->assertSame('deposit * 3', $entry['flowFormula']);
        $this->assertSame('reward * 3', $entry['withdrawFormula']);
    }

    public function test_buildConditioned_returns_only_failing_details(): void
    {
        $member = $this->createMember($this->agent);
        $eventV2 = EventV2::create(['name' => 'Fail', 'handler_class' => 'WelcomeBonus50Service']);
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
            'condition_formula' => 'deposit >= 20',
        ]);

        $eventClass = Mockery::mock(AbstractEventV2::class);
        $eventClass->shouldReceive('getQualificationIssues')
            ->andReturn(['Your deposit has not reached the required threshold.']);

        $eventV2Service = Mockery::mock(EventV2Service::class);
        $eventV2Service->shouldReceive('getEventClass')->with($detail->id)->andReturn($eventClass);

        $builder = new PromotionV2Builder(
            Mockery::mock(MemberService::class),
            $eventV2Service,
        );

        $result = $builder->buildConditioned($member);

        $this->assertCount(1, $result);
        $entry = $result[0];

        $this->assertSame(LivechatBotPromotionSourceEnum::V2->value, $entry['promotionSource']);
        $this->assertSame($detail->id, $entry['promotionId']);
        $this->assertSame('Fail', $entry['promotionName']);
        $this->assertSame(['Your deposit has not reached the required threshold.'], $entry['nonQualify']);
        $this->assertSame('deposit >= 20', $entry['formulas']['conditionFormula']);
    }

    public function test_buildClaimable_and_conditioned_are_mutually_exclusive(): void
    {
        $member = $this->createMember($this->agent);

        $eventV2A = EventV2::create(['name' => 'A', 'handler_class' => 'WelcomeBonus50Service']);
        $detailA = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2A->id,
            'agent_id' => $this->agent->id,
        ]);

        $eventV2B = EventV2::create(['name' => 'B', 'handler_class' => 'WelcomeBonus50Service']);
        $detailB = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2B->id,
            'agent_id' => $this->agent->id,
        ]);

        $passingClass = Mockery::mock(AbstractEventV2::class);
        $passingClass->shouldReceive('getQualificationIssues')->andReturn([]);

        $failingClass = Mockery::mock(AbstractEventV2::class);
        $failingClass->shouldReceive('getQualificationIssues')->andReturn(['Some issue']);

        $eventV2Service = Mockery::mock(EventV2Service::class);
        $eventV2Service->shouldReceive('getEventClass')->with($detailA->id)->andReturn($passingClass);
        $eventV2Service->shouldReceive('getEventClass')->with($detailB->id)->andReturn($failingClass);

        $builder = new PromotionV2Builder(
            Mockery::mock(MemberService::class),
            $eventV2Service,
        );

        $claimable = $builder->buildClaimable($member);
        $conditioned = $builder->buildConditioned($member);

        $this->assertCount(1, $claimable);
        $this->assertSame($detailA->id, $claimable[0]['promotionId']);

        $this->assertCount(1, $conditioned);
        $this->assertSame($detailB->id, $conditioned[0]['promotionId']);
    }

    public function test_buildClaimable_excludes_details_outside_active_window(): void
    {
        $member = $this->createMember($this->agent);
        $eventV2 = EventV2::create(['name' => 'Expired', 'handler_class' => 'WelcomeBonus50Service']);

        // 已過期
        EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
            'start_time' => now()->subDays(10),
            'end_time' => now()->subDays(5),
        ]);

        $builder = new PromotionV2Builder(
            Mockery::mock(MemberService::class),
            Mockery::mock(EventV2Service::class),
        );

        $this->assertSame([], $builder->buildClaimable($member));
        $this->assertSame([], $builder->buildConditioned($member));
    }
}
