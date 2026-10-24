<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\RefreshRankingHelper;
use App\Models\Agent;
use App\Models\EventRefreshRankingRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RefreshRankingHelper 測試
 *
 * php artisan test tests/Unit/Helpers/RefreshRankingHelperTest.php
 */
class RefreshRankingHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @return object{err_id: int}
     */
    private function seedActiveRefreshRankingEvent(Agent $agent): object
    {
        $now = now();

        $eventId = DB::table('events')->insertGetId([
            'title' => 'Test refresh ranking',
            'content' => 'test',
            'type_id' => 1,
            'agent_id' => $agent->id,
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'admin' => 0,
        ]);

        $detailId = DB::table('event_details')->insertGetId([
            'event_id' => $eventId,
            'action_id' => 13,
            'rate' => 1,
            'point' => 100,
            'start_time' => $now->copy()->subHour(),
            'end_time' => $now->copy()->addHour(),
            'draw_time' => $now->copy()->addHours(2),
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'admin' => 0,
        ]);

        $errId = DB::table('event_refresh_rankings')->insertGetId([
            'event_id' => $eventId,
            'event_detail_id' => $detailId,
            'created_at' => $now,
            'updated_at' => $now,
            'admin' => 0,
        ]);

        return (object) ['err_id' => $errId];
    }

    public function test_check_event_record_returns_false_when_no_active_event(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, [
            'username' => 'm_' . uniqid(),
            'mobile' => '0911111111',
            'first_name' => 'A',
        ]);

        $this->assertFalse(RefreshRankingHelper::checkEventRecord($member));
    }

    public function test_check_event_record_returns_true_when_member_already_has_record(): void
    {
        $agent = $this->createAgentAndDomain();
        $seed = $this->seedActiveRefreshRankingEvent($agent);
        $member = $this->createMember($agent, [
            'username' => 'm_' . uniqid(),
            'mobile' => '0922222222',
            'first_name' => 'B',
        ]);

        EventRefreshRankingRecord::create([
            'event_refresh_ranking_id' => $seed->err_id,
            'member_id' => $member->id,
            'last_bet_time' => now()->toDateTimeString(),
            'is_calc' => 1,
        ]);

        $this->assertTrue(RefreshRankingHelper::checkEventRecord($member));
    }

    public function test_check_event_record_creates_record_with_is_calc_one_when_no_duplicate(): void
    {
        $agent = $this->createAgentAndDomain();
        $seed = $this->seedActiveRefreshRankingEvent($agent);
        $member = $this->createMember($agent, [
            'username' => 'm_' . uniqid(),
            'mobile' => '0933333333',
            'first_name' => 'Unique',
            'last_login_ip' => 0,
        ]);

        $this->assertTrue(RefreshRankingHelper::checkEventRecord($member));

        $record = EventRefreshRankingRecord::where('event_refresh_ranking_id', $seed->err_id)
            ->where('member_id', $member->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(1, (int) $record->is_calc);
    }

    public function test_check_event_record_creates_record_with_is_calc_zero_when_peer_already_recorded(): void
    {
        $agent = $this->createAgentAndDomain();
        $seed = $this->seedActiveRefreshRankingEvent($agent);

        $peer = $this->createMember($agent, [
            'username' => 'm_' . uniqid(),
            'mobile' => '0944444444',
            'first_name' => 'Shared',
            'last_login_ip' => 0,
        ]);

        EventRefreshRankingRecord::create([
            'event_refresh_ranking_id' => $seed->err_id,
            'member_id' => $peer->id,
            'last_bet_time' => now()->toDateTimeString(),
            'is_calc' => 1,
        ]);

        $member = $this->createMember($agent, [
            'username' => 'm_' . uniqid(),
            'mobile' => '0955555555',
            'first_name' => 'Shared',
            'last_login_ip' => 0,
        ]);

        $this->assertTrue(RefreshRankingHelper::checkEventRecord($member));

        $record = EventRefreshRankingRecord::where('event_refresh_ranking_id', $seed->err_id)
            ->where('member_id', $member->id)
            ->first();

        $this->assertNotNull($record);
        $this->assertSame(0, (int) $record->is_calc);
    }

    public function test_check_event_record_returns_false_when_profile_incomplete_and_no_record(): void
    {
        $agent = $this->createAgentAndDomain();
        $this->seedActiveRefreshRankingEvent($agent);
        $member = $this->createMember($agent, [
            'username' => 'm_' . uniqid(),
            'mobile' => '0966666666',
            'first_name' => null,
        ]);

        $this->assertFalse(RefreshRankingHelper::checkEventRecord($member));

        $this->assertSame(
            0,
            EventRefreshRankingRecord::where('member_id', $member->id)->count()
        );
    }
}
