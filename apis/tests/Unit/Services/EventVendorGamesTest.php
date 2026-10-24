<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\Member;
use App\Models\Vendor;
use App\Services\EventService;
use Tests\TestCase;

/**
 * EventService::getEventsByVendor 測試（api/event/vendorGames；tpower-master 與 vw-master 逐行完全相同）
 *
 * php artisan test tests/Unit/Services/EventVendorGamesTest.php
 */
class EventVendorGamesTest extends TestCase
{
    private EventService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(EventService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
    }

    private function makeVendorEvent(Vendor $vendor, array $eventOverrides = [], array $detailOverrides = []): array
    {
        $event = Event::factory()->create(array_merge([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'title' => 'vendor event',
        ], $eventOverrides));

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::DEPOSIT->value,
            'status' => 1,
            'vendor_id' => $vendor->id,
            'game_type_id' => 0,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ], $detailOverrides));

        return compact('event', 'detail');
    }

    public function test_returns_events_with_playable_games_for_vendor(): void
    {
        $vendor = $this->createBasicVendorGames($this->agent);
        $ctx = $this->makeVendorEvent($vendor);

        $result = $this->service->getEventsByVendor($this->member, $this->agent, $vendor->id, 1);

        $this->assertCount(1, $result);
        $this->assertSame($ctx['event']->id, (int) $result[0]->id);
        $this->assertSame('vendor event', $result[0]->title);
        $this->assertCount(1, $result[0]->games);
    }

    public function test_excludes_expired_event_details(): void
    {
        $vendor = $this->createBasicVendorGames($this->agent);
        $this->makeVendorEvent($vendor, [], ['end_time' => now()->subHour()]);

        $result = $this->service->getEventsByVendor($this->member, $this->agent, $vendor->id, 1);

        $this->assertCount(0, $result);
    }

    public function test_excludes_other_agent_hierarchy(): void
    {
        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->save();

        $vendor = $this->createBasicVendorGames($this->agent);
        $this->makeVendorEvent($vendor, ['agent_id' => $other->id]);

        $result = $this->service->getEventsByVendor($this->member, $this->agent, $vendor->id, 1);

        $this->assertCount(0, $result);
    }

    public function test_includes_platform_wide_event(): void
    {
        $vendor = $this->createBasicVendorGames($this->agent);
        // agent_id = 0 代表全平台皆可參加
        $this->makeVendorEvent($vendor, ['agent_id' => 0]);

        $result = $this->service->getEventsByVendor($this->member, $this->agent, $vendor->id, 1);

        $this->assertCount(1, $result);
    }

    public function test_throws_when_vendor_not_playable_by_member(): void
    {
        $vendor = Vendor::factory()->create();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1426'));
        $this->service->getEventsByVendor($this->member, $this->agent, $vendor->id, 1);
    }
}
