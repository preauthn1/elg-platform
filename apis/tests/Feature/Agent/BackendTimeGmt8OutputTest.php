<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Http\Resources\Agent\Member\MemberTraceResource;
use App\Http\Resources\Agent\MemberGameRestriction\MemberGameRestrictionResource;
use App\Http\Resources\Agent\ThreeHierarchy\UserSearchResource;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Member;
use App\Models\MemberGameRestriction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 新後端時間欄位輸出後台時區 GMT+8（target/tickets#4492）：DB 存 UTC，原本原樣輸出，顯示少 8 小時。
 *
 * 必須成立：
 * 1. 成員活動篩選：最後存款等時間輸出 GMT+8；日期篩選以 GMT+8 日界解讀（台北凌晨的資料算當天，不算前一天）。
 * 2. 會員追蹤、三層代理用戶查詢、風控遊戲限制：時間欄位輸出 GMT+8；空值不炸。
 *
 * 後台時區設定釘 Asia/Taipei：本機測試是 UTC、CI 是 Asia/Taipei，不釘會兩邊結果不同。
 *
 * php artisan test tests/Feature/Agent/BackendTimeGmt8OutputTest.php
 */
class BackendTimeGmt8OutputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('custom.backend.timezone', 'Asia/Taipei');
        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);
    }

    public function test_activity_filter_outputs_gmt8_and_filters_by_gmt8_day(): void
    {
        $agent = $this->createAgentAndDomain();
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]'agent_id' => $agent->id, 'token' => $token, 'expire_date' => '2099-01-01 00:00:00', 'admin' => 1]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        // 工單實例：台北 10/09 03:50:21 的存款 ＝ UTC 10/08 19:50:21
        $member = $this->createMember($agent, ['username' => 'tz' . bin2hex(random_bytes(5)), 'last_login' => '2026-10-08 19:00:00']);
        DB::table('member_statistics')->insert([
            'member_id' => $member->id,
            'last_deposit_at' => '2026-10-08 19:50:21',
        ]);

        $row = $this->findRow($this->activityFilter(['last_deposit_at' => ['start' => '2026-10-09', 'end' => '2026-10-09']]), $member);
        $this->assertNotNull($row, '查台北 10/09 要找得到');
        $this->assertSame('2026-10-09 03:50:21', $row['last_deposit_at']);
        $this->assertSame('2026-10-09 03:00:00', $row['last_login']);

        $this->assertNull(
            $this->findRow($this->activityFilter(['last_deposit_at' => ['start' => '2026-10-08', 'end' => '2026-10-08']]), $member),
            '查台北 10/08 不該出現（改前會出現在這天）',
        );
    }

    public function test_member_trace_resource_outputs_gmt8(): void
    {
        $data = MemberTraceResource::make((object) [
            'id' => 1, 'username' => 'u1', 'agent_username' => 'a1', 'mobile' => '', 'email' => '',
            'first_name' => '', 'last_name' => '', 'last_login_ip' => null,
            'created_at' => '2026-10-07 13:09:47', 'last_login' => null,
        ])->toArray(Request::create('/'));

        $this->assertSame('2026-10-07 21:09:47', $data['created_at']);
        $this->assertSame('', $data['last_login'], '沒登入過維持空字串');
    }

    public function test_three_hierarchy_user_search_resource_outputs_gmt8(): void
    {
        $data = UserSearchResource::make((object) [
            'identity' => 'member', 'member_username' => 'm1', 'parent_username' => 'a1',
            'rebate_amount' => '0', 'down_rebate_amount' => '0', 'bonus_amount' => '0', 'withdraw_amount' => '0', 'withdraw_able' => '0',
            'created_at' => '2020-05-06 02:48:41',
        ])->toArray(Request::create('/'));

        $this->assertSame('2020-05-06 10:48:41', $data['created_at']);
    }

    public function test_game_restriction_resource_outputs_gmt8(): void
    {
        $restriction = new MemberGameRestriction;
        $restriction->setRawAttributes([
            'id' => 1, 'restrict_type' => 1, 'target_scope' => 1, 'agent_id' => 1, 'status' => 1, 'memo' => '',
            'created_at' => '2026-10-08 15:13:01', 'updated_at' => '2026-10-08 15:13:01',
        ]);
        $restriction->setRelation('targets', collect());
        $restriction->setRelation('members', collect());

        $data = MemberGameRestrictionResource::make($restriction)->toArray(Request::create('/'));

        $this->assertSame('2026-10-08 23:13:01', $data['created_at']);
        $this->assertSame('2026-10-08 23:13:01', $data['updated_at']);
    }

    private function activityFilter(array $filters): TestResponse
    {
        return $this->postJson('agent/api/member/activity-filter', [
            'filters' => $filters,
            'match' => 'and',
            'page' => 1,
            'per_page' => 100,
        ], $this->headers);
    }

    /** @return array<string, mixed>|null */
    private function findRow(TestResponse $response, Member $member): ?array
    {
        $response->assertOk();

        return collect($response->json('data.items') ?? [])->firstWhere('member_id', $member->id);
    }
}
