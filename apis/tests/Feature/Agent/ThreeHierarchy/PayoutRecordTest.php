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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 三層代派發紀錄（4199，由 API_Agent payout_record 移植）
 *
 * php artisan test tests/Feature/Agent/ThreeHierarchy/PayoutRecordTest.php
 *
 * 時區設定：DB 存 UTC、查詢參數為 GMT+8 牆鐘。測試固定用結算日 2026-01-14、
 * 派發時間（UTC）2026-01-15 06:10:00（＝GMT+8 14:10），查詢期間 2026-01-15 全日（GMT+8）。
 */
class PayoutRecordTest extends TestCase
{
    private const PAYOUT_UTC = '2026-01-15 06:10:00';

    private const SUMMARY_TIME = '2026-01-14 00:00:00';

    private Agent $agent;

    private Member $memberA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        // agent_menus code map 與查詢整包快取（testing 為 array store）逐測清空，避免跨測殘留
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

        $menu = AgentMenu::create([
            'code' => 'three_layers_payout_record',
            'name' => '派發紀錄',
            'parent' => 0,
            'url' => 'three_hierarchy/payout_record',
            'symbol' => '',
            'action' => '',
            'sort' => 50,
            'status' => 1,
            'admin' => 0,
        ]);
        AgentPermission::create([
            'agent_id' => $this->agent->id,
            'agent_menu_id' => $menu->id,
            'action' => '',
            'admin' => 1,
        ]);

        $this->memberA = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => 'm' . bin2hex(random_bytes(6)),
        ]);

        // 會員受益列（agent_id=0）：同結算日兩列（不同遊戲商）→ 主表應聚合為一列
        $this->insertReport(['member_id' => $this->memberA->id, 'vendor_id' => 3, 'down_bet' => '100.0000', 'down_rebate' => '1.0000']);
        $this->insertReport(['member_id' => $this->memberA->id, 'vendor_id' => 54, 'bet' => '50.0000', 'rebate' => '0.5000']);

        // 代理受益列（member_id=0）
        $this->insertReport(['agent_id' => $this->agent->id, 'vendor_id' => 3, 'down2_bet' => '200.0000', 'down2_rebate' => '2.0000']);

        // 收據：派發時間之後 → memberA 的列應配到此單號
        DB::table('three_hierarchy_withdraw_logs')->insert([
            'order_no' => strtoupper(bin2hex(random_bytes(5))),
            'type' => 1,
            'member_id' => $this->memberA->id,
            'withdraw_point' => '1.50',
            'remaining_point' => '0.00',
            'status' => 1,
            'created_at' => '2026-01-15 07:31:00',
            'updated_at' => '2026-01-15 07:31:00',
            'admin' => 51,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function insertReport(array $overrides): void
    {
        DB::table('three_hierarchy_bonus_report')->insert(array_merge([
            'summary_time' => self::SUMMARY_TIME,
            'rebate_type' => 1,
            'reward_type' => 1,
            'agent_id' => 0,
            'member_id' => 0,
            'from_member_id' => $this->memberA->id,
            'vendor_id' => 3,
            'game_type_id' => 2,
            'bet' => '0.0000',
            'down_bet' => '0.0000',
            'down2_bet' => '0.0000',
            'rebate' => '0.0000',
            'down_rebate' => '0.0000',
            'down2_rebate' => '0.0000',
            'created_at' => self::PAYOUT_UTC,
            'updated_at' => self::PAYOUT_UTC,
            'admin' => 0,
        ], $overrides));
    }

    private function act(array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/three-hierarchy/payout-record', array_merge([
            'start_time' => '2026-01-15 00:00:00',
            'end_time' => '2026-01-15 23:59:59',
            'page' => 1,
            'per_page' => 20,
        ], $payload), $this->headers);
    }

    public function test_aggregates_per_beneficiary_and_summary_matches_list(): void
    {
        $response = $this->act();

        $response->assertStatus(200)->assertJson(['code' => 200]);

        $items = collect($response->json('data.items'));
        $this->assertSame(2, $response->json('data.pagination.total'));

        // 會員兩列日報聚合為一列：基數 150、返利 1.5、配到提現單號
        $memberRow = $items->firstWhere('identity', 'member');
        $this->assertSame($this->memberA->username, $memberRow['username']);
        $this->assertSame('2026-01-14', $memberRow['summary_time']);
        $this->assertSame('2026-01-15 14:10:00', $memberRow['created_at']); // UTC+8
        $this->assertSame('150.0000', $memberRow['calc_base']);
        $this->assertSame('1.5000', $memberRow['reward']);
        $this->assertNotNull($memberRow['withdraw_log_id']);

        // 代理列：無提領概念，單號恆 null
        $agentRow = $items->firstWhere('identity', 'agent');
        $this->assertSame($this->agent->username, $agentRow['username']);
        $this->assertSame('2.0000', $agentRow['reward']);
        $this->assertNull($agentRow['withdraw_log_id']);

        // 統計卡＝列表合計
        $summary = $response->json('data.summary');
        $this->assertSame('1.5000', $summary['member']['reward']);
        $this->assertSame(1, $summary['member']['member_count']);
        $this->assertSame('2.0000', $summary['agent']['reward']);
        $this->assertSame(1, $summary['agent']['agent_count']);
    }

    public function test_identity_filter_syncs_list_and_summary(): void
    {
        $response = $this->act(['identity' => 'agent']);

        $items = collect($response->json('data.items'));
        $this->assertCount(1, $items);
        $this->assertSame('agent', $items->first()['identity']);
        $this->assertSame(0, $response->json('data.summary.member.member_count'));
    }

    public function test_member_filter_locks_member_identity(): void
    {
        $response = $this->act(['member' => $this->memberA->username]);

        $items = collect($response->json('data.items'));
        $this->assertCount(1, $items);
        $this->assertSame('member', $items->first()['identity']);
        $this->assertSame(0, $response->json('data.summary.agent.agent_count'));
    }

    public function test_period_over_32_days_rejected(): void
    {
        $response = $this->act(['end_time' => '2026-02-17 00:00:00']);

        $this->assertNotSame(200, (int) $response->json('code'));
    }

    public function test_mixed_rebate_type_blanks_calc_base(): void
    {
        // 混入一列輸分佔成（rebate_type=2）→ 防呆：計算基數回 null（前端顯示「混合，見明細」）
        $this->insertReport(['member_id' => $this->memberA->id, 'vendor_id' => 99, 'rebate_type' => 2, 'bet' => '30.0000', 'rebate' => '0.3000']);

        $response = $this->act(['identity' => 'member']);

        $memberRow = collect($response->json('data.items'))->firstWhere('identity', 'member');
        $this->assertNull($memberRow['calc_base']);
        $this->assertSame('1.8000', $memberRow['reward']);
    }

    public function test_detail_matches_master_row_and_tier(): void
    {
        $response = $this->postJson('agent/api/three-hierarchy/payout-record/detail', [
            'identity' => 'member',
            'id' => $this->memberA->id,
            'summary_time' => '2026-01-14',
            'page' => 1,
            'per_page' => 10,
        ], $this->headers);

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $items = collect($response->json('data.items'));
        $this->assertCount(2, $items);

        // 明細合計＝主表該列（工單重點驗算項）
        $this->assertSame('150.0000', $response->json('data.summary.base'));
        $this->assertSame('1.5000', $response->json('data.summary.reward'));

        // 層級由非零金額欄推斷
        $this->assertSame('down', $items->firstWhere('base', '100.0000')['tier']);
        $this->assertSame('self', $items->firstWhere('base', '50.0000')['tier']);
    }

    public function test_detail_out_of_scope_rejected(): void
    {
        // 另一條總代線的會員：不在請求者範疇內，下鑽必須擋（防越權）
        $unique = bin2hex(random_bytes(8));
        $outsider = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'a' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'status' => 1,
            'role_id' => RoleEnum::GENERAL_AGENT->value,
        ]);
        $outsider->hierarchy_path = "/{$outsider->id}";
        $outsider->hierarchy = "[-1,{$outsider->id},-2]";
        $outsider->save();
        $outsideMember = Member::factory()->create([
            'parent' => $outsider->id,
            'username' => 'm' . bin2hex(random_bytes(6)),
        ]);

        $response = $this->postJson('agent/api/three-hierarchy/payout-record/detail', [
            'identity' => 'member',
            'id' => $outsideMember->id,
            'summary_time' => '2026-01-14',
        ], $this->headers);

        $this->assertNotSame(200, $response->getStatusCode());
    }

    // ─────────── 快路徑（4199 效能修正：admin 無帳號篩選走純表＋逐批湊頁＋當頁補名） ───────────

    /** 造一個 platform admin 子帳號（host_id=1 ⇒ scopePath null ⇒ 快路徑）並發查詢 */
    private function actAsPlatformAdmin(array $payload = []): TestResponse
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

        return $this->postJson('agent/api/three-hierarchy/payout-record', array_merge([
            'start_time' => '2026-01-15 00:00:00',
            'end_time' => '2026-01-16 23:59:59',
            'page' => 1,
            'per_page' => 20,
        ], $payload), array_merge($this->headers, ['Authorization' => 'Bearer ' . $token]));
    }

    public function test_fast_path_admin_semantics_match(): void
    {
        // admin 無篩選＝快路徑：口徑須與 join 路徑一致（聚合、卡片、單號、防呆）
        $response = $this->actAsPlatformAdmin();

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $items = collect($response->json('data.items'));
        $this->assertSame(2, $response->json('data.pagination.total'));

        $memberRow = $items->firstWhere('identity', 'member');
        $this->assertSame($this->memberA->username, $memberRow['username']);
        $this->assertSame('2026-01-14', $memberRow['summary_time']);
        $this->assertSame('150.0000', $memberRow['calc_base']);
        $this->assertSame('1.5000', $memberRow['reward']);
        $this->assertNotNull($memberRow['withdraw_log_id']);

        $agentRow = $items->firstWhere('identity', 'agent');
        $this->assertSame($this->agent->username, $agentRow['username']);
        $this->assertNull($agentRow['withdraw_log_id']);

        $summary = $response->json('data.summary');
        $this->assertSame('1.5000', $summary['member']['reward']);
        $this->assertSame(1, $summary['member']['member_count']);
        $this->assertSame('2.0000', $summary['agent']['reward']);
        $this->assertSame(1, $summary['agent']['agent_count']);
    }

    public function test_fast_path_pages_across_batches_newest_first(): void
    {
        // 第二個結算批次（隔日派發）：逐批湊頁應新批先出、跨批分頁正確
        $this->insertReport([
            'member_id' => $this->memberA->id,
            'vendor_id' => 3,
            'down_bet' => '30.0000',
            'down_rebate' => '0.9000',
            'summary_time' => '2026-01-15 00:00:00',
            'created_at' => '2026-01-16 06:10:00',
            'updated_at' => '2026-01-16 06:10:00',
        ]);

        $pageOne = $this->actAsPlatformAdmin(['per_page' => 2, 'page' => 1]);
        $this->assertSame(3, $pageOne->json('data.pagination.total'));
        $first = collect($pageOne->json('data.items'));
        // 新批（01-15 結算、01-16 派發）排最前
        $this->assertSame('2026-01-15', $first->first()['summary_time']);
        $this->assertCount(2, $first);

        $pageTwo = $this->actAsPlatformAdmin(['per_page' => 2, 'page' => 2]);
        $second = collect($pageTwo->json('data.items'));
        $this->assertCount(1, $second);
        $this->assertSame('2026-01-14', $second->first()['summary_time']);
    }

    public function test_fast_path_orphan_row_with_placeholder(): void
    {
        // 孤兒日報（member 已刪）：快路徑如實顯示、帳號「—」、計入卡片與 total
        $this->insertReport([
            'member_id' => 999999888,
            'from_member_id' => 999999888,
            'vendor_id' => 3,
            'bet' => '10.0000',
            'rebate' => '0.1000',
        ]);

        $response = $this->actAsPlatformAdmin();

        $items = collect($response->json('data.items'));
        $orphan = $items->firstWhere('username', '—');
        $this->assertNotNull($orphan);
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertSame(2, $response->json('data.summary.member.member_count'));
    }
}
