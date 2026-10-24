<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\BetHelper;
use App\Models\Member;
use Tests\TestCase;

/**
 * BetHelper::isMemberFlowLimitAllowed 輕量測試（無需 PrizeContentFlowLimit）
 *
 * php artisan test tests/Unit/Helpers/BetHelperFlowLimitTest.php
 */
class BetHelperFlowLimitTest extends TestCase
{
    public function test_member_inactive_returns_false(): void
    {
        $member = Member::factory()->make([
            'status' => 0,
            'event_mode' => 0,
        ]);

        $this->assertFalse(BetHelper::isMemberFlowLimitAllowed($member, vendorId: 1));
    }

    public function test_event_mode_not_positive_short_circuits_true(): void
    {
        $member = Member::factory()->make([
            'status' => 1,
            'event_mode' => 0,
        ]);

        $this->assertTrue(BetHelper::isMemberFlowLimitAllowed($member, vendorId: 1));
    }
}
