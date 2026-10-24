<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Event;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\Member;
use App\Models\PrizeMember;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Event/RemindTest.php
 */
class RemindTest extends TestCase
{
    private Agent $agent;

    private ?Member $member = null;

    private ?Event $event = null;

    private PrizeMember $prizeMember;

    private string $apiPath = 'frontend/api/event/remind';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        // lad('agent', $this->agent);

        $this->member = $this->createMemberAndToken($this->agent);

        // lad('member', $this->member);

        // $this->seed(EventSeeder::class);
        $this->creatEvent($this->agent);

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);
    }

    public function creatEvent($agent): void
    {
        $startDate = now();
        $drawDate = $startDate->copy()->addDays(2);
        $endDate = $startDate->copy()->addDays(7);

        /** @var Event $event */

        // 建立活動
        $event = Event::factory()->create([
            'title' => '充值随机奖金(僅限充值活動)首充',
            'agent_id' => $agent->id,
            'start_time' => $startDate,
            'end_time' => $endDate,
            'status' => 1,
        ]);

        $eventDetail = EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => 53,
            'start_time' => $startDate,
            'end_time' => $endDate,
            'draw_time' => $drawDate,
        ]);

        $eventPrize = $event->prize()->create([
            'name' => '首充隨機獎金',
            'type_id' => 3, // 獎勵遊戲類型編號
            'number' => 1,
            'expire_date' => $endDate,
            'draw_date' => $drawDate,
            'status' => 1,
            'background' => '',
        ]);

        // lad('event', $event);
        // lad('eventDetail', $eventDetail);
        // lad('eventPrize', $eventPrize);

        $this->event = $event;
    }

    // 建立未領獎紀錄
    public function insertPrizeMember()
    {
        return PrizeMember::create([
            'member_id' => $this->member->id,
            'prize_id' => $this->event->prize->id,
            'event_detail_id' => $this->event->eventDetail->id,
            'status' => 0,
            'admin' => 51,
        ]);
    }

    // 無領獎紀錄
    public function test_no_prize_success()
    {
        // Params
        $params = [];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'has_prize' => false,
                    'count' => 0,
                ],
            ]);
    }

    // 有領獎紀錄
    public function test_has_prize_success()
    {
        $this->prizeMember = $this->insertPrizeMember();
        // lad('prizeMember', $this->prizeMember);

        // Params
        $params = [];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'has_prize' => true,
                ],
            ]);
    }

    // 已領獎紀錄
    public function test_received_prize_success()
    {
        $this->prizeMember = $this->insertPrizeMember();
        $this->prizeMember->update(['status' => 1]);

        // Params
        $params = [];

        // Request
        $response = $this->requestApi($this->apiPath, $params);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 200,
                'data' => [
                    'has_prize' => false,
                    'count' => 0,
                ],
            ]);
    }
}
