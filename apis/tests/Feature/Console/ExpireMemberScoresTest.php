<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Score\ScoreBatchStatusEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberScoreBatch;
use App\Models\MemberScoreLog;
use App\Services\MemberScoreService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/ExpireMemberScoresTest.php
 *
 * score:expire（target/tickets#4233）：--dry-run 不寫入、--at 以 +8 輸入模擬判定時點、
 * --member 限定範圍、未到期與無限期批次不受影響。
 */
class ExpireMemberScoresTest extends TestCase
{
    private MemberScoreService $service;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
        // .env.testing 未設 BACKEND_TIMEZONE（預設 UTC）；--at 的 +8 語意要在測試內釘住才不依賴環境
        config(['custom.backend.timezone' => 'Asia/Taipei']);

        $this->service = app(MemberScoreService::class);
        // createAgentAndDomain() 的 domain 是每個測試實例固定值，同一測試呼叫兩次會撞唯一鍵；一個代理底下建多個會員
        $this->agent = $this->createAgentAndDomain();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_lists_due_batches_without_writing(): void
    {
        $member = $this->newMember();
        $batch = $this->service->grant($member, '30', 1, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->artisan('score:expire', ['--dry-run' => true, '--member' => $member->id])
            ->expectsOutputToContain('共 1 批將到期失效')
            ->assertExitCode(0);

        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $batch->fresh()->status);
        $this->assertSame(0, MemberScoreLog::query()->where('type', ScoreLogTypeEnum::EXPIRE->value)->where('member_id', $member->id)->count());
    }

    public function test_at_option_is_parsed_in_backend_timezone(): void
    {
        $member = $this->newMember();
        $batch = $this->service->grant($member, '30', 24, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);
        // 到期 2026-09-16 12:00 UTC ＝ 2026-09-16 20:00 +8

        // 判定時點 +8 的 19:59 → UTC 11:59，尚未到期
        $this->artisan('score:expire', ['--at' => '2026-09-16 19:59', '--member' => $member->id])
            ->expectsOutputToContain('0 批到期失效')
            ->assertExitCode(0);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $batch->fresh()->status);

        // 判定時點 +8 的 20:00 → UTC 12:00，剛好到期（expired_at <= at）
        $this->artisan('score:expire', ['--at' => '2026-09-16 20:00', '--member' => $member->id])
            ->expectsOutputToContain('1 批到期失效')
            ->assertExitCode(0);
        $this->assertSame(ScoreBatchStatusEnum::EXPIRED, $batch->fresh()->status);
        $this->assertSame('0.0000', number_format((float) $batch->fresh()->remaining_score, 4, '.', ''));
        $this->assertSame('0.0000', $this->service->balance($member->id));
    }

    public function test_invalid_at_option_fails_fast(): void
    {
        $this->artisan('score:expire', ['--at' => 'not-a-date'])
            ->expectsOutputToContain('--at 格式無法解析')
            ->assertExitCode(1);
    }

    public function test_member_option_limits_scope(): void
    {
        $target = $this->newMember();
        $other = $this->newMember();
        $targetBatch = $this->service->grant($target, '10', 1, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);
        $otherBatch = $this->service->grant($other, '10', 1, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->artisan('score:expire', ['--member' => $target->id])
            ->expectsOutputToContain('處理 1 位會員、1 批到期失效')
            ->assertExitCode(0);

        $this->assertSame(ScoreBatchStatusEnum::EXPIRED, $targetBatch->fresh()->status);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $otherBatch->fresh()->status, '未指定的會員不得被動到');
    }

    public function test_unexpired_and_unlimited_batches_are_untouched(): void
    {
        $member = $this->newMember();
        $due = $this->service->grant($member, '10', 1, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);
        $future = $this->service->grant($member, '20', 48, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);
        $unlimited = $this->service->grant($member, '30', null, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->artisan('score:expire', ['--member' => $member->id])
            ->expectsOutputToContain('1 批到期失效')
            ->assertExitCode(0);

        $this->assertSame(ScoreBatchStatusEnum::EXPIRED, $due->fresh()->status);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $future->fresh()->status);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $unlimited->fresh()->status);
        $this->assertSame('50.0000', $this->service->balance($member->id));
    }

    public function test_rerun_is_idempotent(): void
    {
        $member = $this->newMember();
        $this->service->grant($member, '10', 1, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->artisan('score:expire', ['--member' => $member->id])->assertExitCode(0);
        $this->artisan('score:expire', ['--member' => $member->id])
            ->expectsOutputToContain('處理 0 位會員、0 批到期失效')
            ->assertExitCode(0);

        $this->assertSame(1, MemberScoreLog::query()->where('member_id', $member->id)->where('type', ScoreLogTypeEnum::EXPIRE->value)->count());
        $this->assertSame(1, MemberScoreBatch::query()->where('member_id', $member->id)->count());
    }

    private function newMember(): Member
    {
        return $this->createMember($this->agent, ['username' => 'score_' . bin2hex(random_bytes(4))]);
    }
}
