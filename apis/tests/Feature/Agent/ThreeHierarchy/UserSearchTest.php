<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\ThreeHierarchy;

use App\Enums\Agent\RoleEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\Member;
use App\Models\ThreeHierarchySummaryBonus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 三層代用戶查詢（4190，由 API_Agent user_search 移植）
 *
 * php artisan test tests/Feature/Agent/ThreeHierarchy/UserSearchTest.php
 */
class UserSearchTest extends TestCase
{
    private Agent $agent;

    private Member $memberA;

    private Member $memberB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        // agent_menus code map 與 summary 快取都走 Cache（testing 為 array store），
        // 同 process 跨測試殘留會讓斷言吃到上一個測試的資料，逐測清空。
        Cache::flush();

        $this->agent = $this->createAgentAndDomain();

        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        // 選單＋個人權限（非 admin 代理須持有 three_layers_user_search 才放行）
        $menu = AgentMenu::create([
            'code' => 'three_layers_user_search',
            'name' => '用戶查詢',
            'parent' => 0,
            'url' => 'three_hierarchy/user_search',
            'symbol' => '',
            'action' => '',
            'sort' => 20,
            'status' => 1,
            'admin' => 0,
        ]);
        AgentPermission::create([
            'agent_id' => $this->agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 1,
        ]);

        // 會員累計兩筆＋代理累計一筆（代理列＝登入代理自己）
        $this->memberA = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => 'm' . bin2hex(random_bytes(6)),
        ]);
        $this->memberB = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => 'm' . bin2hex(random_bytes(6)),
        ]);

        ThreeHierarchySummaryBonus::create([
            'member_id' => $this->memberA->id,
            'rebate_amount' => '10.0000',
            'down_rebate_amount' => '20.0000',
            'down2_rebate_amount' => '70.5000',
            'bonus_amount' => '100.5000',
            'withdraw_amount' => '30.2500',
            'admin' => 0,
        ]);
        ThreeHierarchySummaryBonus::create([
            'member_id' => $this->memberB->id,
            'rebate_amount' => '50.0000',
            'down_rebate_amount' => '0.0000',
            'down2_rebate_amount' => '0.0000',
            'bonus_amount' => '50.0000',
            'withdraw_amount' => '0.0000',
            'admin' => 0,
        ]);
        DB::table('three_hierarchy_summary_bonus_agents')->insert([
            'agent_id' => $this->agent->id,
            'rebate_amount' => '0.0000',
            'down_rebate_amount' => '10.0000',
            'down2_rebate_amount' => '20.0000',
            'bonus_amount' => '30.0000',
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function act(array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/three-hierarchy/user-search', $payload, $this->headers);
    }

    /** 第二條總代線：不走 createAgentAndDomain（其 domain 固定值，同測試第二次呼叫撞 unique） */
    private function createOtherAgentLine(): Agent
    {
        $unique = bin2hex(random_bytes(8));
        $agent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'a' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'status' => 1,
            'role_id' => RoleEnum::GENERAL_AGENT->value,
        ]);
        $agent->hierarchy_path = "/{$agent->id}";
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        return $agent;
    }

    public function test_union_rows_with_identity_and_summary_consistency(): void
    {
        $response = $this->act(['page' => 1, 'per_page' => 20]);

        $response->assertStatus(200)->assertJson(['code' => 200]);

        $items = collect($response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertCount(2, $items->where('identity', 'member'));
        $this->assertCount(1, $items->where('identity', 'agent'));

        // 代理列：無提領概念，佔位 '_'
        $agentRow = $items->firstWhere('identity', 'agent');
        $this->assertSame('_', $agentRow['member_username']);
        $this->assertSame('_', $agentRow['withdraw_able']);
        $this->assertSame($this->agent->username, $agentRow['parent_username']);

        // 會員列：withdraw_able = bonus − withdraw，金額為 4 位小數字串
        $memberRow = $items->firstWhere('member_username', $this->memberA->username);
        $this->assertSame('70.2500', $memberRow['withdraw_able']);
        $this->assertSame('100.5000', $memberRow['bonus_amount']);

        // 卡片＝列表合計
        $summary = $response->json('data.summary');
        $this->assertSame(2, $summary['member']['total']);
        $this->assertSame(1, $summary['agent']['total']);
        $this->assertSame('150.5000', $summary['member']['bonus_amount']);
        $this->assertSame('120.2500', $summary['member']['withdraw_able']);
        $this->assertSame('30.0000', $summary['agent']['bonus_amount']);
    }

    public function test_identity_filter_member_only_zeroes_agent_card(): void
    {
        $response = $this->act(['identity' => 'member', 'page' => 1, 'per_page' => 20]);

        $items = collect($response->json('data.items'));
        $this->assertCount(2, $items);
        $this->assertCount(0, $items->where('identity', 'agent'));
        $this->assertSame(0, $response->json('data.summary.agent.total'));
    }

    public function test_member_filter_forces_member_identity(): void
    {
        $response = $this->act(['member' => $this->memberA->username, 'identity' => 'agent', 'page' => 1, 'per_page' => 20]);

        // member 篩選覆寫 identity（沿 API_Agent 原版口徑）
        $items = collect($response->json('data.items'));
        $this->assertCount(1, $items);
        $this->assertSame($this->memberA->username, $items->first()['member_username']);
        $this->assertSame(0, $response->json('data.summary.agent.total'));
        $this->assertSame(1, $response->json('data.summary.member.total'));
    }

    public function test_withdraw_sort_locks_member_identity_and_orders_desc(): void
    {
        $response = $this->act(['sort' => 'withdraw_amount', 'direction' => 'desc', 'page' => 1, 'per_page' => 20]);

        $items = collect($response->json('data.items'));
        $this->assertCount(2, $items);
        $this->assertCount(0, $items->where('identity', 'agent'));
        $this->assertSame($this->memberA->username, $items->first()['member_username']);
    }

    public function test_sort_outside_whitelist_rejected(): void
    {
        $response = $this->act(['sort' => 'parent_username', 'page' => 1, 'per_page' => 20]);

        $this->assertNotSame(200, (int) $response->json('code'));
    }

    public function test_agent_without_menu_permission_denied(): void
    {
        $other = $this->createOtherAgentLine();
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $other->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);

        $response = $this->postJson(
            'agent/api/three-hierarchy/user-search',
            ['page' => 1, 'per_page' => 20],
            array_merge($this->headers, ['Authorization' => 'Bearer ' . $token]),
        );

        $this->assertNotSame(200, (int) $response->json('code'));
    }

    public function test_scope_excludes_other_agent_line(): void
    {
        // 另一條總代線的會員不得出現在本代理的查詢結果
        $outsider = $this->createOtherAgentLine();
        $outsideMember = Member::factory()->create([
            'parent' => $outsider->id,
            'username' => 'm' . bin2hex(random_bytes(6)),
        ]);
        ThreeHierarchySummaryBonus::create([
            'member_id' => $outsideMember->id,
            'rebate_amount' => '999.0000',
            'down_rebate_amount' => '0.0000',
            'down2_rebate_amount' => '0.0000',
            'bonus_amount' => '999.0000',
            'withdraw_amount' => '0.0000',
            'admin' => 0,
        ]);

        $response = $this->act(['page' => 1, 'per_page' => 20]);

        $items = collect($response->json('data.items'));
        $this->assertNull($items->firstWhere('member_username', $outsideMember->username));
        $this->assertSame(2, $response->json('data.summary.member.total'));
    }

    // ─────────── 快路徑（fix/4190-user-search-slow-query：admin 無篩選走純表＋當頁補名） ───────────

    /** 造一個 platform admin 子帳號（host_id=1 ⇒ scopePath null ⇒ 快路徑）並回傳其 headers */
    private function actAsPlatformAdmin(array $payload): TestResponse
    {
        $unique = bin2hex(random_bytes(8));
        $admin = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'a' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'status' => 1,
            'host_id' => 1,
            'role_id' => RoleEnum::GENERAL_AGENT->value,
        ]);
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $admin->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);

        return $this->postJson(
            'agent/api/three-hierarchy/user-search',
            $payload,
            array_merge($this->headers, ['Authorization' => 'Bearer ' . $token]),
        );
    }

    public function test_fast_path_admin_semantics_match(): void
    {
        // admin 無篩選＝快路徑：口徑須與慢路徑一致（identity、金額、卡片＝列表）
        $response = $this->actAsPlatformAdmin(['page' => 1, 'per_page' => 20, 'sort' => 'bonus_amount', 'direction' => 'desc']);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $items = collect($response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertCount(2, $items->where('identity', 'member'));
        $this->assertCount(1, $items->where('identity', 'agent'));

        // 排序在純表 union 上生效：bonus 最大者在首列
        $this->assertSame('100.5000', $items->first()['bonus_amount']);

        // 補名正確：會員列帶所屬代理帳號、代理列佔位 '_'
        $memberRow = $items->firstWhere('member_username', $this->memberA->username);
        $this->assertSame($this->agent->username, $memberRow['parent_username']);
        $this->assertSame('70.2500', $memberRow['withdraw_able']);
        $agentRow = $items->firstWhere('identity', 'agent');
        $this->assertSame('_', $agentRow['member_username']);

        // 卡片＝列表（快路徑純表聚合）
        $summary = $response->json('data.summary');
        $this->assertSame(2, $summary['member']['total']);
        $this->assertSame(1, $summary['agent']['total']);
        $this->assertSame('150.5000', $summary['member']['bonus_amount']);
    }

    public function test_fast_path_shows_orphan_row_with_placeholder(): void
    {
        // 孤兒列（member 已刪的殘留累計）：快路徑如實顯示、帳號「—」、計入卡片
        ThreeHierarchySummaryBonus::create([
            'member_id' => 999999888,
            'rebate_amount' => '7.0000',
            'down_rebate_amount' => '0.0000',
            'down2_rebate_amount' => '0.0000',
            'bonus_amount' => '7.0000',
            'withdraw_amount' => '0.0000',
            'admin' => 0,
        ]);

        $response = $this->actAsPlatformAdmin(['page' => 1, 'per_page' => 20]);

        $items = collect($response->json('data.items'));
        $orphan = $items->firstWhere('bonus_amount', '7.0000');
        $this->assertNotNull($orphan);
        $this->assertSame('—', $orphan['member_username']);
        $this->assertSame('—', $orphan['parent_username']);
        $this->assertSame(4, $response->json('data.pagination.total'));
        $this->assertSame(3, $response->json('data.summary.member.total'));
    }

    public function test_fast_path_created_at_sort_falls_back_to_join(): void
    {
        // created_at 排序需 members/agents 的建立時間，須退回 join 路徑。
        // 用孤兒列當探針：join 路徑（inner join）不含孤兒、快路徑含——藉此驗證路徑切換。
        ThreeHierarchySummaryBonus::create([
            'member_id' => 999999888,
            'rebate_amount' => '7.0000',
            'down_rebate_amount' => '0.0000',
            'down2_rebate_amount' => '0.0000',
            'bonus_amount' => '7.0000',
            'withdraw_amount' => '0.0000',
            'admin' => 0,
        ]);

        $withSort = $this->actAsPlatformAdmin(['page' => 1, 'per_page' => 20, 'sort' => 'created_at', 'direction' => 'desc']);
        $this->assertSame(3, $withSort->json('data.pagination.total')); // join 路徑：孤兒被 inner join 排除

        $noSort = $this->actAsPlatformAdmin(['page' => 1, 'per_page' => 20]);
        $this->assertSame(4, $noSort->json('data.pagination.total')); // 快路徑：孤兒如實計入
    }
}
