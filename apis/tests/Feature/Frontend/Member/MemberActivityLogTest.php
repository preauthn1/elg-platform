<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Member;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Member/MemberActivityLogTest.php
 */
class MemberActivityLogTest extends TestCase
{
    protected Agent $agent;

    protected Member $member;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 DecryptRequest 中間件
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = $this->createMemberAndToken($this->agent, [
            'username' => 'keroro',
            'email' => 'user@example.invalid',
            'email_validate' => 1,
            'password' => Hash::make('123456'),
            'balance' => 0,
        ]);
    }

    public function test_member_balance_change_records_activity_log(): void
    {
        // 更新餘額為 200，應觸發 ActivityObserver
        $this->member->update(['balance' => 200]);

        // 取得最新的 member-balance-changed activity
        $activity = Activity::query()->where('log_name', 'member-balance-changed')->latest()->first();

        $this->assertNotNull($activity, '應建立 member-balance-changed 的 activity');

        // 轉為陣列，兼容 Spatie 的 properties 類型
        $props = json_decode(json_encode($activity->properties), true);

        // properties.attributes 應包含變更後的欄位值
        $this->assertArrayHasKey('attributes', $props);
        $this->assertEquals(200, $props['attributes']['balance']);

        // 敘述應包含 before/after 的實際值（例如 \"餘額從 100 變更為 200\"）
        $this->assertStringContainsString('餘額從', $activity->description);
        $this->assertStringContainsString('0', $activity->description);
        $this->assertStringContainsString('200', $activity->description);
    }
}
