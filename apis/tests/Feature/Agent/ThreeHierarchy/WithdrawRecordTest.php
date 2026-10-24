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
 * 三層代提現紀錄（4214，由 API_Agent withdraw_record 移植）
 *
 * php artisan test tests/Feature/Agent/ThreeHierarchy/WithdrawRecordTest.php
 *
 * 時區：DB 存 UTC、查詢參數為 GMT+8 牆鐘。固定用 UTC 2026-01-15 07:31:00
 * （＝GMT+8 15:31），查詢期間 2026-01-15 全日（GMT+8）。
 */
class WithdrawRecordTest extends TestCase
{
    private const LOG_UTC = '2026-01-15 07:31:00';

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
            'code' => 'three_layers_withdrawal_record',
            'name' => '提現紀錄',
            'parent' => 0,
            'url' => 'three_hierarchy/withdrawal_record',
            'symbol' => '',
            'action' => '',
            'sort' => 30,
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
        $this->memberB = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => 'm' . bin2hex(random_bytes(6)),
        ]);

        // memberA 兩筆（排程＋手動）＋ memberB 一筆（排程）：人數去重應為 2、筆數 3
        $this->insertLog(['member_id' => $this->memberA->id, 'withdraw_point' => '40.00', 'admin' => 51]);
        $this->insertLog(['member_id' => $this->memberA->id, 'withdraw_point' => '10.00', 'remaining_point' => '5.00', 'admin' => 0]);
        $this->insertLog(['member_id' => $this->memberB->id, 'withdraw_point' => '20.00', 'admin' => 51]);
    }

    /** @param array<string, mixed> $overrides */
    private function insertLog(array $overrides): int
    {
        return (int) DB::table('three_hierarchy_withdraw_logs')->insertGetId(array_merge([
            'order_no' => strtoupper(bin2hex(random_bytes(5))),
            'type' => 1,
            'member_id' => 0,
            'withdraw_point' => '0.00',
            'remaining_point' => '0.00',
            'status' => 1,
            'created_at' => self::LOG_UTC,
            'updated_at' => self::LOG_UTC,
            'admin' => 51,
        ], $overrides));
    }

    private function act(array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/three-hierarchy/withdraw-record', array_merge([
            'start_time' => '2026-01-15 00:00:00',
            'end_time' => '2026-01-15 23:59:59',
            'page' => 1,
            'per_page' => 20,
        ], $payload), $this->headers);
    }

    public function test_list_with_source_and_summary_dedup(): void
    {
        $response = $this->act();

        $response->assertStatus(200)->assertJson(['code' => 200]);

        $items = collect($response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));

        // 來源欄：admin 51 → schedule、0 → manual
        $this->assertCount(2, $items->where('source', 'schedule'));
        $this->assertCount(1, $items->where('source', 'manual'));

        // 申請時間為 GMT+8（UTC 07:31 → 15:31）
        $this->assertSame('2026-01-15 15:31:00', $items->first()['created_at']);

        // 統計卡：總額直加、人數去重（memberA 兩筆只算一人）
        $summary = $response->json('data.summary');
        $this->assertSame('70.00', $summary['total_point']);
        $this->assertSame(3, $summary['total_count']);
        $this->assertSame(2, $summary['member_count']);
    }

    public function test_agent_filter_scopes_member_rows(): void
    {
        $response = $this->act(['agent' => $this->agent->username]);
        $this->assertSame(3, $response->json('data.pagination.total'));

        $none = $this->act(['agent' => 'no_such_agent']);
        $this->assertSame(0, $none->json('data.pagination.total'));
        $this->assertSame(0, $none->json('data.summary.total_count'));
    }

    public function test_agent_and_member_filter_intersect(): void
    {
        $response = $this->act(['agent' => $this->agent->username, 'member' => $this->memberB->username]);

        $items = collect($response->json('data.items'));
        $this->assertCount(1, $items);
        $this->assertSame($this->memberB->username, $items->first()['username']);
        $this->assertSame(1, $response->json('data.summary.member_count'));
    }

    public function test_order_no_filter_matches_log_id(): void
    {
        $id = $this->insertLog(['member_id' => $this->memberA->id, 'withdraw_point' => '7.00']);

        $response = $this->act(['order_no' => (string) $id]);

        $items = collect($response->json('data.items'));
        $this->assertCount(1, $items);
        $this->assertSame($id, $items->first()['id']);
        $this->assertSame('7.00', $items->first()['withdraw_point']);
    }

    public function test_scope_excludes_other_agent_line(): void
    {
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
        $this->insertLog(['member_id' => $outsideMember->id, 'withdraw_point' => '999.00']);

        $response = $this->act();

        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertSame('70.00', $response->json('data.summary.total_point'));
    }

    public function test_period_over_32_days_rejected(): void
    {
        $response = $this->act(['end_time' => '2026-02-17 00:00:00']);

        $this->assertNotSame(200, (int) $response->json('code'));
    }

    // ─────────── 快路徑（4214 效能修正：admin 無帳號篩選走純表＋當頁補名） ───────────

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

        return $this->postJson('agent/api/three-hierarchy/withdraw-record', array_merge([
            'start_time' => '2026-01-15 00:00:00',
            'end_time' => '2026-01-15 23:59:59',
            'page' => 1,
            'per_page' => 20,
        ], $payload), array_merge($this->headers, ['Authorization' => 'Bearer ' . $token]));
    }

    public function test_fast_path_admin_semantics_match(): void
    {
        // admin 無篩選＝快路徑：口徑須與 join 路徑一致（來源、金額、時區、統計）
        $response = $this->actAsPlatformAdmin();

        $response->assertStatus(200)->assertJson(['code' => 200]);
        $items = collect($response->json('data.items'));
        $this->assertSame(3, $response->json('data.pagination.total'));
        $this->assertCount(2, $items->where('source', 'schedule'));
        $this->assertCount(1, $items->where('source', 'manual'));
        $this->assertSame('2026-01-15 15:31:00', $items->first()['created_at']); // UTC+8

        // 補名正確
        $this->assertNotNull($items->firstWhere('username', $this->memberA->username));

        $summary = $response->json('data.summary');
        $this->assertSame('70.00', $summary['total_point']);
        $this->assertSame(3, $summary['total_count']);
        $this->assertSame(2, $summary['member_count']);
    }

    public function test_fast_path_order_no_filter_and_orphan_row(): void
    {
        // 孤兒收據（member 已刪）：快路徑如實顯示、帳號「—」；order_no 篩選在純表生效
        $orphanId = $this->insertLog(['member_id' => 999999888, 'withdraw_point' => '9.00']);

        $all = $this->actAsPlatformAdmin();
        $orphan = collect($all->json('data.items'))->firstWhere('id', $orphanId);
        $this->assertNotNull($orphan);
        $this->assertSame('—', $orphan['username']);
        $this->assertSame(4, $all->json('data.summary.total_count'));

        $one = $this->actAsPlatformAdmin(['order_no' => (string) $orphanId]);
        $items = collect($one->json('data.items'));
        $this->assertCount(1, $items);
        $this->assertSame('9.00', $items->first()['withdraw_point']);
    }
}
