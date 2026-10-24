<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Member;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 統計摘要期間改用後台時區（GMT+8）的日界（target/tickets#4438 第 8 項）。
 *
 * 工單實例：devtsaiyoa 的存款 3 筆 51.00、提款 1 筆 1.00 發生在台北 10/09 03:06～03:50
 * （DB 存 UTC 10/08 19:xx）；改前查 10/09 全是 0、查 10/08 才看得到。
 *
 * 必須成立：
 * 1. 查台北 10/09 當天看得到這些存提；查 10/08 看不到。
 * 2. 期間參數用後台時區解讀：本機測試 BACKEND_TIMEZONE 是 UTC、CI 是 Asia/Taipei，所以把設定釘死。
 *
 * php artisan test tests/Feature/Agent/Member/MemberDetailStatisticsTimezoneTest.php
 */
class MemberDetailStatisticsTimezoneTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
        config()->set('custom.backend.timezone', 'Asia/Taipei');

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'mst' . bin2hex(random_bytes(5))]);

        // 台北 2026-10-09 03:00 那個小時 ＝ UTC 2026-10-08 19:00
        DB::table('member_hourly_statistics')->insert([
            'member_id' => $this->member->id,
            'summary_hour' => '2026-10-08 19:00:00',
            'deposit_count' => 3,
            'deposit_amount' => '51.0000',
            'withdrawal_count' => 1,
            'withdrawal_amount' => '1.0000',
        ]);

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]'agent_id' => $this->agent->id, 'token' => $token, 'expire_date' => '2099-01-01 00:00:00', 'admin' => 1]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    public function test_taipei_day_includes_early_morning_transactions(): void
    {
        $data = $this->statistics('2026-10-09 00:00:00', '2026-10-09 23:59:59')->assertOk()->json('data');

        $this->assertSame(3, (int) $data['deposit_count']);
        $this->assertSame('51.0000', $data['deposit_amount']);
        $this->assertSame(1, (int) $data['withdrawal_count']);
        $this->assertSame('1.0000', $data['withdrawal_amount']);
    }

    public function test_previous_taipei_day_no_longer_counts_them(): void
    {
        $data = $this->statistics('2026-10-08 00:00:00', '2026-10-08 23:59:59')->assertOk()->json('data');

        $this->assertSame(0, (int) $data['deposit_count']);
        $this->assertSame(0, (int) $data['withdrawal_count']);
    }

    public function test_date_only_period_is_rejected(): void
    {
        $this->assertNotSame(200, $this->statistics('2026-10-09', '2026-10-09')->json('code'));
    }

    private function statistics(string $start, string $end): TestResponse
    {
        return $this->postJson('agent/api/member/detail/statistics', [
            'member_id' => $this->member->id,
            'start_time' => $start,
            'end_time' => $end,
        ], $this->headers);
    }
}
