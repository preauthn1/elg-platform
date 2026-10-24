<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\Event\PrizeMemberStatusEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventName;
use App\Models\EventProcess;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\LivechatBot;
use App\Models\Prize;
use App\Models\PrizeMember;
use App\Services\EventV2\AbstractEventV2;
use App\Services\EventV2\EventV2Service;
use App\Services\LivechatBot\GetPromotionDetailService;
use App\Services\LivechatBot\LivechatBotContext;
use App\Services\LivechatBot\MemberAgentGuard;
use App\Services\LivechatBot\PromotionV1Builder;
use App\Services\LivechatBot\PromotionV2Builder;
use App\Services\MemberService;
use Database\Seeders\LanguageSeeder;
use Mockery;
use Tests\TestCase;

/**
 * Livechat Bot GetPromotionDetailService 單元測試。
 *
 * php artisan test tests/Unit/Services/LivechatBot/GetPromotionDetailTest.php
 */
class GetPromotionDetailTest extends TestCase
{
    private ?Agent $agent = null;

    private ?LivechatBotContext $context = null;

    private ?MemberAgentGuard $guard = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(LanguageSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->context = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($this->agent->id)));
        $this->guard = new MemberAgentGuard;
    }

    private function makeBot(int $agentId): LivechatBot
    {
        return LivechatBot::create([
            'name' => 'unit_bot_' . bin2hex(random_bytes(4)),
            'agent_id' => $agentId,
            'api_key' => bin2hex(random_bytes(16)),
            'vip_url' => null,
            'is_active' => true,
        ]);
    }

    public function test_throws_404_when_member_not_found(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service = new GetPromotionDetailService(
            Mockery::mock(PromotionV1Builder::class),
            Mockery::mock(PromotionV2Builder::class),
            $this->context,
            $this->guard,
        );

        $service->getPromotionDetail('nonexistent_user_999', 'FREE');
    }

    public function test_returns_empty_array_when_keyword_matches_nothing(): void
    {
        $member = $this->createMember($this->agent);

        $service = $this->buildRealService();
        $result = $service->getPromotionDetail($member->username, 'NONEXISTENT_KEYWORD_XYZ');

        $this->assertSame([], $result);
    }

    public function test_keyword_matches_v2_active_only(): void
    {
        $member = $this->createMember($this->agent);

        $eventV2 = EventV2::create(['name' => 'FREE100 Bonus', 'handler_class' => 'WelcomeBonus50Service']);
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
        ]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => EventV2Service::DEFAULT_LANGUAGE_id,
            'name' => 'FREE 100',
        ]);

        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $member->id,
            'order_id' => 0,
            'reward' => 100,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 此 EventV2 對應的 detail 必須通過 getQualificationIssues 評估，故 mock 為失敗（避免出現在 claimable / conditioned）
        $failingClass = Mockery::mock(AbstractEventV2::class);
        $failingClass->shouldReceive('getQualificationIssues')->andReturn(['Some issue']);

        $eventV2Service = Mockery::mock(EventV2Service::class);
        $eventV2Service->shouldReceive('getEventClass')->andReturn($failingClass);

        $memberService = Mockery::mock(MemberService::class);
        $memberService->shouldReceive('getFlowLimit')->andReturn(['flow_limit' => 100, 'reach' => 0, 'pass' => 0]);

        $v2 = new PromotionV2Builder($memberService, $eventV2Service);
        $v1 = new PromotionV1Builder;

        $service = new GetPromotionDetailService($v1, $v2, $this->context, $this->guard);
        // 關鍵字 FREE100 → 命中 EventV2.name，但 conditioned 也會出現（因為相同 detail 在 conditioned 且 keyword 也命中）
        // 為精確測試 active 標記，使用較精準關鍵字 "FREE100" 並讓 conditioned 也出現以驗證合併
        $result = $service->getPromotionDetail($member->username, 'FREE100');

        // active 一定有
        $activeEntries = array_values(array_filter($result, fn ($e) => $e['eligibilityStatus'] === 'active'));
        $this->assertCount(1, $activeEntries);
        $this->assertSame('active', $activeEntries[0]['eligibilityStatus']);
        $this->assertSame('FREE100 Bonus', $activeEntries[0]['promotionName']);
    }

    public function test_keyword_matches_claimable_v1_and_v2(): void
    {
        $member = $this->createMember($this->agent);

        // V1: prize_member 待領取，event title 含 FREE
        $v1Event = Event::factory()->create(['title' => 'FREE Login Bonus']);
        $v1Detail = EventDetail::factory()->create([
            'event_id' => $v1Event->id,
            'action_id' => EventActionIdEnum::LOGIN->value,
        ]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => 0,
            'event_detail_id' => $v1Detail->id,
            'lottery_point' => 25.5,
            'ip' => '0.0.0.0',
            'status' => PrizeMemberStatusEnum::UNAWARD->value,
            'admin' => 0,
        ]);

        // V2: detail 通過 getQualificationIssues（claimable）
        $v2Event = EventV2::create(['name' => 'FREE 200 Bonus', 'handler_class' => 'WelcomeBonus50Service']);
        $v2Detail = EventV2Detail::factory()->create([
            'event_v2_id' => $v2Event->id,
            'agent_id' => $this->agent->id,
        ]);

        $passingClass = Mockery::mock(AbstractEventV2::class);
        $passingClass->shouldReceive('getQualificationIssues')->andReturn([]);

        $eventV2Service = Mockery::mock(EventV2Service::class);
        $eventV2Service->shouldReceive('getEventClass')->with($v2Detail->id)->andReturn($passingClass);

        $service = new GetPromotionDetailService(
            new PromotionV1Builder,
            new PromotionV2Builder(Mockery::mock(MemberService::class), $eventV2Service),
            $this->context,
            $this->guard,
        );

        $result = $service->getPromotionDetail($member->username, 'FREE');

        $statuses = array_column($result, 'eligibilityStatus');
        $this->assertContains('claimable', $statuses);
        $this->assertNotContains('active', $statuses);
        $this->assertNotContains('conditioned', $statuses);

        // claimable 應有 v1 + v2 兩筆
        $claimableEntries = array_values(array_filter($result, fn ($e) => $e['eligibilityStatus'] === 'claimable'));
        $this->assertCount(2, $claimableEntries);

        $sources = array_column($claimableEntries, 'promotionSource');
        $this->assertContains('v1', $sources);
        $this->assertContains('v2', $sources);
    }

    public function test_keyword_matches_conditioned_v1_and_v2(): void
    {
        $member = $this->createMember($this->agent);

        // V1: event_process 進行中且未達標
        // PromotionV1Builder::buildConditioned 新架構需 event.agent_id 在 member 代理線下 + active prize
        $v1Event = Event::factory()->create([
            'title' => 'FREE Daily Quest',
            'agent_id' => $this->agent->id,
            'status' => 1,
        ]);
        Prize::create([
            'event_id' => $v1Event->id,
            'type_id' => 0,
            'name' => 'V1 Prize',
            'background' => '',
            'number' => 100,
            'flow_limit_multiple' => 1,
            'max_point' => 1000,
            'withdrawal_cancel_reward' => 0,
            'cancel_other_reward' => 0,
            'expire_date' => now()->addDays(30)->toDateTimeString(),
            'status' => 1,
            'admin' => 0,
        ]);
        $v1Detail = EventDetail::factory()->create([
            'event_id' => $v1Event->id,
            'action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value,
            'point' => 5,
            'status' => 1,
        ]);
        EventProcess::create([
            'detail_id' => $v1Detail->id,
            'member_id' => $member->id,
            'point' => 1,
            'status' => 0,
            'admin' => 0,
        ]);

        // V2: detail 失敗（conditioned）
        $v2Event = EventV2::create(['name' => 'FREE Promo', 'handler_class' => 'WelcomeBonus50Service']);
        $v2Detail = EventV2Detail::factory()->create([
            'event_v2_id' => $v2Event->id,
            'agent_id' => $this->agent->id,
            'condition_formula' => 'deposit >= 20',
        ]);

        $failingClass = Mockery::mock(AbstractEventV2::class);
        $failingClass->shouldReceive('getQualificationIssues')->andReturn(['Deposit not reached']);

        $eventV2Service = Mockery::mock(EventV2Service::class);
        $eventV2Service->shouldReceive('getEventClass')->with($v2Detail->id)->andReturn($failingClass);

        $service = new GetPromotionDetailService(
            new PromotionV1Builder,
            new PromotionV2Builder(Mockery::mock(MemberService::class), $eventV2Service),
            $this->context,
            $this->guard,
        );

        $result = $service->getPromotionDetail($member->username, 'FREE');

        $statuses = array_column($result, 'eligibilityStatus');
        $this->assertNotContains('active', $statuses);
        $this->assertNotContains('claimable', $statuses);

        $conditioned = array_values(array_filter($result, fn ($e) => $e['eligibilityStatus'] === 'conditioned'));
        $this->assertCount(2, $conditioned);
        $sources = array_column($conditioned, 'promotionSource');
        $this->assertContains('v1', $sources);
        $this->assertContains('v2', $sources);
    }

    public function test_keyword_matches_mixed_categories(): void
    {
        $member = $this->createMember($this->agent);

        // V2 active: detail 有 user join 在 JOIN 狀態
        $eventActive = EventV2::create(['name' => 'FREE Active', 'handler_class' => 'WelcomeBonus50Service']);
        $detailActive = EventV2Detail::factory()->create([
            'event_v2_id' => $eventActive->id,
            'agent_id' => $this->agent->id,
        ]);
        EventV2UserJoin::insert([
            'event_v2_detail_id' => $detailActive->id,
            'member_id' => $member->id,
            'order_id' => 0,
            'reward' => 50,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // V2 conditioned
        $eventCond = EventV2::create(['name' => 'FREE Cond', 'handler_class' => 'WelcomeBonus50Service']);
        $detailCond = EventV2Detail::factory()->create([
            'event_v2_id' => $eventCond->id,
            'agent_id' => $this->agent->id,
        ]);

        // V1 claimable
        $v1Event = Event::factory()->create(['title' => 'FREE Reward']);
        $v1Detail = EventDetail::factory()->create([
            'event_id' => $v1Event->id,
            'action_id' => EventActionIdEnum::LOGIN->value,
        ]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => 0,
            'event_detail_id' => $v1Detail->id,
            'lottery_point' => 10,
            'ip' => '0.0.0.0',
            'status' => PrizeMemberStatusEnum::UNAWARD->value,
            'admin' => 0,
        ]);

        // active detail 與 conditioned detail 都會被 V2 builder 載入；mock getQualificationIssues
        // 對 detailActive 回傳失敗（讓它只出現在 active，不出現在 claimable）
        // 對 detailCond 回傳失敗（讓它出現在 conditioned）
        $failingClass = Mockery::mock(AbstractEventV2::class);
        $failingClass->shouldReceive('getQualificationIssues')->andReturn(['issue']);

        $eventV2Service = Mockery::mock(EventV2Service::class);
        $eventV2Service->shouldReceive('getEventClass')->andReturn($failingClass);

        $memberService = Mockery::mock(MemberService::class);
        $memberService->shouldReceive('getFlowLimit')->andReturn(['flow_limit' => 0, 'reach' => 0, 'pass' => 0]);

        $service = new GetPromotionDetailService(
            new PromotionV1Builder,
            new PromotionV2Builder($memberService, $eventV2Service),
            $this->context,
            $this->guard,
        );

        $result = $service->getPromotionDetail($member->username, 'FREE');

        $statuses = array_column($result, 'eligibilityStatus');
        $this->assertContains('active', $statuses);
        $this->assertContains('claimable', $statuses);
        $this->assertContains('conditioned', $statuses);
    }

    public function test_keyword_is_case_insensitive(): void
    {
        $member = $this->createMember($this->agent);

        // 使用 V1 路徑驗證 case-insensitive，避免 V2 路徑需要 mock
        $v1Event = Event::factory()->create(['title' => 'FREE100 Bonus']);
        $v1Detail = EventDetail::factory()->create([
            'event_id' => $v1Event->id,
            'action_id' => EventActionIdEnum::LOGIN->value,
        ]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => 0,
            'event_detail_id' => $v1Detail->id,
            'lottery_point' => 10,
            'ip' => '0.0.0.0',
            'status' => PrizeMemberStatusEnum::UNAWARD->value,
            'admin' => 0,
        ]);

        $service = $this->buildRealService();
        $result = $service->getPromotionDetail($member->username, 'free');

        $this->assertCount(1, $result);
        $this->assertSame('claimable', $result[0]['eligibilityStatus']);
        $this->assertSame('FREE100 Bonus', $result[0]['promotionName']);
    }

    public function test_keyword_matches_v1_event_name_table_when_event_title_does_not_contain(): void
    {
        $member = $this->createMember($this->agent);

        // events.title 不含 keyword，但 event_names.title 含 keyword（多語系）
        $v1Event = Event::factory()->create(['title' => 'Standard Bonus']);
        EventName::create([
            'event_id' => $v1Event->id,
            'language_id' => 2,
            'title' => 'BONUS FREE 100',
            'content' => 'localized content',
            'admin' => 0,
        ]);
        $v1Detail = EventDetail::factory()->create([
            'event_id' => $v1Event->id,
            'action_id' => EventActionIdEnum::LOGIN->value,
        ]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => 0,
            'event_detail_id' => $v1Detail->id,
            'lottery_point' => 10,
            'ip' => '0.0.0.0',
            'status' => PrizeMemberStatusEnum::UNAWARD->value,
            'admin' => 0,
        ]);

        $service = $this->buildRealService();
        $result = $service->getPromotionDetail($member->username, 'FREE');

        $this->assertCount(1, $result);
        $this->assertSame('claimable', $result[0]['eligibilityStatus']);
    }

    public function test_keyword_matches_v2_detail_lang_when_event_v2_name_does_not_contain(): void
    {
        $member = $this->createMember($this->agent);

        $eventV2 = EventV2::create(['name' => 'Standard Bonus', 'handler_class' => 'WelcomeBonus50Service']);
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
        ]);
        // 多語言 lang.name 含 FREE
        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => 2,
            'name' => 'Bonus FREE 100',
        ]);

        $passingClass = Mockery::mock(AbstractEventV2::class);
        $passingClass->shouldReceive('getQualificationIssues')->andReturn([]);

        $eventV2Service = Mockery::mock(EventV2Service::class);
        $eventV2Service->shouldReceive('getEventClass')->with($detail->id)->andReturn($passingClass);

        $service = new GetPromotionDetailService(
            new PromotionV1Builder,
            new PromotionV2Builder(Mockery::mock(MemberService::class), $eventV2Service),
            $this->context,
            $this->guard,
        );

        $result = $service->getPromotionDetail($member->username, 'FREE');

        $this->assertCount(1, $result);
        $this->assertSame('claimable', $result[0]['eligibilityStatus']);
        $this->assertSame('v2', $result[0]['promotionSource']);
        $this->assertSame($detail->id, $result[0]['promotionId']);
    }

    /**
     * 跨 agent 存取（bot 綁不同 agent）→ 404
     */
    public function test_member_not_in_bot_agent_hierarchy_throws_404(): void
    {
        $member = $this->createMember($this->agent);

        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $otherContext = tap(new LivechatBotContext, fn ($c) => $c->setBot($this->makeBot($otherAgent->id)));

        $service = new GetPromotionDetailService(
            Mockery::mock(PromotionV1Builder::class),
            Mockery::mock(PromotionV2Builder::class),
            $otherContext,
            $this->guard,
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Member not found');
        $this->expectExceptionCode(404);

        $service->getPromotionDetail($member->username, 'FREE');
    }

    /**
     * 建立一個 builder 都使用真實實作（V2 Service 為空 mock）的 service。
     * 用於不會觸發 V2 detail 評估的測試（例如僅 V1 路徑）。
     */
    private function buildRealService(): GetPromotionDetailService
    {
        return new GetPromotionDetailService(
            new PromotionV1Builder,
            new PromotionV2Builder(
                Mockery::mock(MemberService::class),
                Mockery::mock(EventV2Service::class),
            ),
            $this->context,
            $this->guard,
        );
    }
}
