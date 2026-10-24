<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\MemberScore;

use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\Member;
use App\Models\MemberScoreBatch;
use App\Models\MemberScoreLog;
use App\Services\MemberScoreService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/MemberScore/MemberScoreGrantTest.php
 *
 * 後台人工發放積分接口（target/tickets#4233；開洗分彈窗「積分」選項）：
 * 成功建批次＋寫 log＋回餘額、無限期、驗證擋 3 位小數與缺備註、範疇外會員擋、權限節點擋。
 */
class MemberScoreGrantTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-16 04:00:00', 'UTC'));
        config(['custom.backend.timezone' => 'Asia/Taipei']);

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'sc' . bin2hex(random_bytes(6))]);

        // 認證 middleware 以真實時間比對 expire_date，不吃 Carbon::setTestNow；固定遠期避免被釘住的測試時間搞成 319 過期
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => '2099-01-01 00:00:00',
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function grant(array $payload): TestResponse
    {
        return $this->postJson('agent/api/member/score/grant', $payload, $this->headers);
    }

    public function test_grant_creates_batch_log_and_returns_balance(): void
    {
        $res = $this->grant([
            'member_id' => $this->member->id,
            'score' => '100.5',
            'hours' => 24,
            'remark' => 'QA 補發',
        ]);

        $res->assertOk()->assertJsonPath('code', 200);
        $this->assertSame('100.5000', $res->json('data.balance'));
        // 到期 2026-09-17 04:00 UTC → 後台時區 +8 顯示 12:00
        $this->assertSame('2026-09-17 12:00:00', $res->json('data.expired_at'));

        $batch = MemberScoreBatch::query()->where('member_id', $this->member->id)->sole();
        $this->assertSame(ScoreSourceTypeEnum::MANUAL, $batch->source_type);
        $this->assertSame(0, $batch->source_id);
        $this->assertSame($this->agent->id, $batch->admin);

        $log = MemberScoreLog::query()->where('batch_id', $batch->id)->sole();
        $this->assertSame(ScoreLogTypeEnum::MANUAL, $log->type);
        $this->assertSame($this->agent->username, $log->operator, '操作者寫代理帳號');
        $this->assertSame('QA 補發', $log->remark);

        $this->assertSame('100.5000', app(MemberScoreService::class)->balance($this->member->id));
    }

    public function test_grant_without_hours_is_unlimited(): void
    {
        $res = $this->grant([
            'member_id' => $this->member->id,
            'score' => '50',
            'remark' => '無限期',
        ]);

        $res->assertOk();
        $this->assertNull($res->json('data.expired_at'));
        $this->assertNull(MemberScoreBatch::query()->where('member_id', $this->member->id)->sole()->expired_at);
    }

    public function test_validation_rejects_three_decimals_and_missing_remark(): void
    {
        // 專案的 FormRequest 驗證失敗統一回 400（非 Laravel 預設 422）
        $this->grant(['member_id' => $this->member->id, 'score' => '1.005', 'remark' => 'x'])
            ->assertStatus(400);

        $this->grant(['member_id' => $this->member->id, 'score' => '10'])
            ->assertStatus(400);

        $this->assertSame(0, MemberScoreBatch::query()->where('member_id', $this->member->id)->count());
    }

    public function test_member_outside_agent_tree_is_rejected(): void
    {
        // createAgentAndDomain 共用 $this->domain，第二棵樹需換新域名避免撞唯一鍵
        $this->domain = 'sc' . bin2hex(random_bytes(6)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $outsider = $this->createMember($otherAgent, ['username' => 'sco' . bin2hex(random_bytes(6))]);

        $res = $this->grant(['member_id' => $outsider->id, 'score' => '10', 'remark' => '越權']);

        $this->assertNotSame(200, $res->json('code'));
        $this->assertSame(0, MemberScoreBatch::query()->where('member_id', $outsider->id)->count());
    }

    public function test_permission_node_blocks_agent_without_grant(): void
    {
        // 測試庫無 seeder：自建 member_add_score 節點讓權限檢查從 fail-open 變成真的擋
        $menu = AgentMenu::query()->firstOrCreate(
            ['code' => 'member_add_score'],
            ['name' => '會員積分發放', 'parent' => 0, 'url' => '', 'symbol' => '', 'action' => '', 'sort' => -999, 'status' => 1, 'admin' => 0],
        );

        $denied = $this->grant(['member_id' => $this->member->id, 'score' => '10', 'remark' => '無權限']);
        $this->assertNotSame(200, $denied->json('code'), '無權限節點應被 CheckAgentPermission 擋下');
        $this->assertSame(0, MemberScoreBatch::query()->where('member_id', $this->member->id)->count());

        AgentPermission::create([
            'agent_id' => $this->agent->id,
            'agent_menu_id' => $menu->id,
            'action' => 'view',
            'admin' => 0,
        ]);

        $this->grant(['member_id' => $this->member->id, 'score' => '10', 'remark' => '有權限'])
            ->assertOk()
            ->assertJsonPath('code', 200);
    }
}
