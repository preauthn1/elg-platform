<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\UpdateMemberActivity;
use App\Models\MemberStatistic;
use Tests\TestCase;

/**
 * 驗證 UpdateMemberActivity：以 type 區分寫入 last_activity_at / last_bet_at，且時間只前進。
 *
 * php artisan test tests/Unit/Jobs/UpdateMemberActivityTest.php
 */
class UpdateMemberActivityTest extends TestCase
{
    public function test_request_type_writes_last_activity_at(): void
    {
        $memberId = 992001;

        (new UpdateMemberActivity($memberId, UpdateMemberActivity::TYPE_REQUEST, '2026-07-14 10:00:00'))->handle();

        $stat = MemberStatistic::where('member_id', $memberId)->first();
        $this->assertNotNull($stat);
        $this->assertEquals('2026-07-14 10:00:00', $stat->last_activity_at->toDateTimeString());
        $this->assertNull($stat->last_bet_at);
    }

    public function test_bet_type_writes_last_bet_at(): void
    {
        $memberId = 992002;

        (new UpdateMemberActivity($memberId, UpdateMemberActivity::TYPE_BET, '2026-07-14 10:00:00'))->handle();

        $stat = MemberStatistic::where('member_id', $memberId)->first();
        $this->assertEquals('2026-07-14 10:00:00', $stat->last_bet_at->toDateTimeString());
        $this->assertNull($stat->last_activity_at);
    }

    public function test_only_moves_forward_ignores_older_time(): void
    {
        $memberId = 992003;

        (new UpdateMemberActivity($memberId, UpdateMemberActivity::TYPE_BET, '2026-07-14 10:00:00'))->handle();
        // 亂序進來較舊時間 → 不覆蓋
        (new UpdateMemberActivity($memberId, UpdateMemberActivity::TYPE_BET, '2026-07-14 09:00:00'))->handle();

        $stat = MemberStatistic::where('member_id', $memberId)->first();
        $this->assertEquals('2026-07-14 10:00:00', $stat->last_bet_at->toDateTimeString());

        // 較新時間 → 更新
        (new UpdateMemberActivity($memberId, UpdateMemberActivity::TYPE_BET, '2026-07-14 11:30:00'))->handle();
        $stat->refresh();
        $this->assertEquals('2026-07-14 11:30:00', $stat->last_bet_at->toDateTimeString());
    }
}
