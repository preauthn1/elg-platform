<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Event;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\Member;
use App\Models\PrizeMember;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Event/PrizeRemindTest.php
 */
class PrizeRemindTest extends TestCase
{
    private Agent $agent;

    private ?Member $member = null;

    private ?Event $event = null;

    private string $apiPath = 'frontend/api/event/prize-remind';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent);

        $this->withoutMiddleware(DecryptRequest::class);
    }

    public function createEventV1(): void
    {
        $startDate = now();
        $endDate = $startDate->copy()->addDays(7);

        $event = Event::factory()->create([
            'title' => '充值随机奖金(僅限充值活動)首充',
            'agent_id' => $this->agent->id,
            'start_time' => $startDate,
            'end_time' => $endDate,
            'status' => 1,
        ]);

        EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => 53,
            'start_time' => $startDate,
            'end_time' => $endDate,
            'draw_time' => $startDate->copy()->addDays(2),
        ]);

        $event->prize()->create([
            'name' => '首充隨機獎金',
            'type_id' => 3,
            'number' => 1,
            'expire_date' => $endDate,
            'draw_date' => $startDate->copy()->addDays(2),
            'status' => 1,
            'background' => '',
        ]);

        $this->event = $event;
    }

    public function createEventV2Detail()
    {
        $eventV2 = EventV2::query()->first() ?? EventV2::create([
            'name' => '註冊',
            'handler_class' => 'RegisterService',
        ]);

        return EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
        ]);
    }

    // 無任何驚嘆號來源
    public function test_no_prize_success()
    {
        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'vip' => false,
                    'event' => false,
                ],
            ]);
    }

    // 活動 V1 有未領取獎勵
    public function test_event_v1_has_prize_success()
    {
        $this->createEventV1();
        PrizeMember::create([
            'member_id' => $this->member->id,
            'prize_id' => $this->event->prize->id,
            'event_detail_id' => $this->event->eventDetail->id,
            'status' => 0,
            'admin' => 51,
        ]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'event' => true,
                ],
            ]);
    }

    // 活動 V2 有可參加但尚未參加的活動（RegisterService 無條件限制，validate 直接成功）
    public function test_event_v2_available_success()
    {
        $this->createEventV2Detail();

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'event' => true,
                ],
            ]);
    }

    // 活動 V2 存在但 validate 不通過（需實名但會員尚未實名）時，不計入驚嘆號
    public function test_event_v2_validate_fail_not_counted()
    {
        $eventV2 = EventV2::create([
            'name' => '註冊',
            'handler_class' => 'RegisterService',
        ]);

        EventV2Detail::factory()->create([
            'event_v2_id' => $eventV2->id,
            'agent_id' => $this->agent->id,
            'condition_config' => ['realname' => 1],
        ]);

        $this->member->update(['realname_validate' => 0]);

        $response = $this->requestApi($this->apiPath, []);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'event' => false,
                ],
            ]);
    }
}
