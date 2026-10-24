<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\MemberScore;

use App\Enums\Score\ScoreBatchStatusEnum;
use App\Enums\Score\ScoreLogDirectionEnum;
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
 * php artisan test tests/Feature/Agent/MemberScore/MemberScoreDeductTest.php
 *
 * 後台人工扣除積分接口（target/tickets#4233；開洗分彈窗「積分扣除」）：
 * 填總量、依到期日由近到遠自動拆批（PM 2026-09-16 定案為總量分配）、餘額不足整筆擋下、權限節點擋。
 */
class MemberScoreDeductTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private MemberScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
        config(['custom.backend.timezone' => 'Asia/Taipei']);

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['username' => 'sd' . bin2hex(random_bytes(6))]);
        $this->service = app(MemberScoreService::class);

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

    private function deduct(array $payload): TestResponse
    {
        return $this->postJson('agent/api/member/score/deduct', $payload, $this->headers);
    }

    private function grantHours(string $score, ?int $hours): MemberScoreBatch
    {
        return $this->service->grant($this->member, $score, $hours, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);
    }

    public function test_deduct_splits_by_nearest_expiry_and_returns_breakdown(): void
    {
        // 工單案例：A 100（到期 10/01）、B 200（到期 09/20）、C 50（無到期）
        $a = $this->grantHours('100', 16 * 24);
        $b = $this->grantHours('200', 5 * 24);
        $c = $this->grantHours('50', null);

        $res = $this->deduct(['member_id' => $this->member->id, 'score' => '250', 'remark' => '誤發收回']);

        $res->assertOk()->assertJsonPath('code', 200);
        $this->assertSame('250.0000', $res->json('data.score'));
        $this->assertSame('100.0000', $res->json('data.balance'));

        $deductions = $res->json('data.deductions');
        $this->assertCount(2, $deductions, '跨兩批拆兩筆');
        $this->assertSame($b->id, $deductions[0]['batch_id'], '最快到期的 B 先扣');
        $this->assertSame('200.0000', $deductions[0]['score']);
        $this->assertSame('2026-09-20 20:00:00', $deductions[0]['expired_at'], '到期日以後台時區 +8 回傳');
        $this->assertSame($a->id, $deductions[1]['batch_id']);
        $this->assertSame('50.0000', $deductions[1]['score']);

        $this->assertSame(ScoreBatchStatusEnum::EXHAUSTED, $b->fresh()->status);
        $this->assertSame('50.0000', number_format((float) $a->fresh()->remaining_score, 4, '.', ''));
        $this->assertSame('50.0000', number_format((float) $c->fresh()->remaining_score, 4, '.', ''), '無到期日的 C 沒被動到');

        $logs = MemberScoreLog::query()->where('member_id', $this->member->id)
            ->where('direction', ScoreLogDirectionEnum::DECREASE->value)->get();
        $this->assertCount(2, $logs);
        foreach ($logs as $log) {
            $this->assertSame(ScoreLogTypeEnum::MANUAL, $log->type);
            $this->assertSame($this->agent->username, $log->operator);
            $this->assertSame($this->agent->id, $log->admin);
            $this->assertSame('誤發收回', $log->remark);
        }
    }

    public function test_insufficient_blocks_whole_request(): void
    {
        $batch = $this->grantHours('100', 24);

        $res = $this->deduct(['member_id' => $this->member->id, 'score' => '999', 'remark' => '超扣']);

        $res->assertStatus(400);
        $this->assertSame(
            __('error_message.score.insufficient', ['available' => '100.0000', 'requested' => '999.0000']),
            $res->json('message'),
            '餘額不足訊息走語系並帶可用／要求數',
        );
        $this->assertSame('100.0000', number_format((float) $batch->fresh()->remaining_score, 4, '.', ''));
        $this->assertSame('100.0000', $this->service->balance($this->member->id));
        $this->assertSame(
            0,
            MemberScoreLog::query()->where('member_id', $this->member->id)->where('direction', ScoreLogDirectionEnum::DECREASE->value)->count(),
            '不可留下部分扣除的紀錄',
        );
    }

    public function test_validation_requires_remark(): void
    {
        $this->grantHours('100', null);

        $this->deduct(['member_id' => $this->member->id, 'score' => '10'])->assertStatus(400);
        $this->assertSame('100.0000', $this->service->balance($this->member->id));
    }

    public function test_permission_node_blocks_agent_without_grant(): void
    {
        $this->grantHours('100', null);

        $menu = AgentMenu::query()->firstOrCreate(
            ['code' => 'member_deduct_score'],
            ['name' => '會員積分扣除', 'parent' => 0, 'url' => '', 'symbol' => '', 'action' => '', 'sort' => -999, 'status' => 1, 'admin' => 0],
        );

        $denied = $this->deduct(['member_id' => $this->member->id, 'score' => '10', 'remark' => '無權限']);
        $this->assertNotSame(200, $denied->json('code'));
        $this->assertSame('100.0000', $this->service->balance($this->member->id));

        AgentPermission::create([
            'agent_id' => $this->agent->id,
            'agent_menu_id' => $menu->id,
            'action' => 'view',
            'admin' => 0,
        ]);

        $this->deduct(['member_id' => $this->member->id, 'score' => '10', 'remark' => '有權限'])
            ->assertOk()
            ->assertJsonPath('code', 200);
        $this->assertSame('90.0000', $this->service->balance($this->member->id));
    }
}
