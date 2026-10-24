<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Score\ScoreBatchStatusEnum;
use App\Enums\Score\ScoreLogDirectionEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberScoreBatch;
use App\Models\MemberScoreLog;
use App\Services\MemberScoreService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/AdjustMemberScoreTest.php
 *
 * score:adjust（target/tickets#4233）：以工單「積分核心」測試步驟 1～7 為腳本，
 * 全程只用 artisan 命令操作（grant ×3 → 查餘額 → deduct 250 → 查各批 → 推時間跑到期 → deduct 999）。
 * 另驗 dry-run 回滾、帳號查會員、找不到會員與非法 action。
 */
class AdjustMemberScoreTest extends TestCase
{
    private Agent $agent;

    private MemberScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 工單案例的基準日：批次 A 到期 10/01、B 到期 09/20，以 09/15 12:00 UTC 為「現在」
        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
        config(['custom.backend.timezone' => 'Asia/Taipei']);

        $this->agent = $this->createAgentAndDomain();
        $this->service = app(MemberScoreService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ticket_scenario_end_to_end_via_commands(): void
    {
        $member = $this->newMember();
        $id = (string) $member->id;

        // 步驟 1：建三批。A 100（到期 10/01 12:00 UTC＝16 天）、B 200（到期 09/20＝5 天）、C 50（無到期）
        $this->artisan('score:adjust', ['action' => 'grant', 'member' => $id, 'score' => '100', '--hours' => 16 * 24, '--yes' => true])->assertExitCode(0);
        $this->artisan('score:adjust', ['action' => 'grant', 'member' => $id, 'score' => '200', '--hours' => 5 * 24, '--yes' => true])->assertExitCode(0);
        $this->artisan('score:adjust', ['action' => 'grant', 'member' => $id, 'score' => '50', '--yes' => true])->assertExitCode(0);

        [$a, $b, $c] = MemberScoreBatch::query()->where('member_id', $member->id)->orderBy('id')->get()->all();

        // 步驟 2：餘額 350
        $this->assertSame('350.0000', $this->service->balance($member->id));

        // 步驟 3：扣 250 成功
        $this->artisan('score:adjust', ['action' => 'deduct', 'member' => $id, 'score' => '250', '--yes' => true])
            ->expectsOutputToContain('可用積分 350.0000 → 100.0000')
            ->assertExitCode(0);

        // 步驟 4：B 先扣光、再扣 A 50；C 完全沒被動到
        $this->assertSame('0.0000', $this->dec($b->fresh()->remaining_score));
        $this->assertSame(ScoreBatchStatusEnum::EXHAUSTED, $b->fresh()->status);
        $this->assertSame('50.0000', $this->dec($a->fresh()->remaining_score));
        $this->assertSame('50.0000', $this->dec($c->fresh()->remaining_score));

        $deductLogs = MemberScoreLog::query()->where('member_id', $member->id)
            ->where('direction', ScoreLogDirectionEnum::DECREASE->value)->orderBy('id')->get();
        $this->assertCount(2, $deductLogs, '跨兩批拆兩筆');
        $this->assertSame($b->id, $deductLogs[0]->batch_id);
        $this->assertSame($a->id, $deductLogs[1]->batch_id);
        $this->assertSame(ScoreLogTypeEnum::MANUAL, $deductLogs[0]->type);

        // 步驟 5：把時間推到 09/21 之後跑到期（B 已用罄不再處理；A、C 未到期）
        $this->artisan('score:expire', ['--at' => '2026-09-21 08:00', '--member' => $member->id])
            ->expectsOutputToContain('0 批到期失效')
            ->assertExitCode(0);

        // 步驟 6：A 未受影響；再推到 10/02，A 那批到期失效並留一筆紀錄
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $a->fresh()->status);

        $this->artisan('score:expire', ['--at' => '2026-10-02 08:00', '--member' => $member->id])
            ->expectsOutputToContain('1 批到期失效')
            ->assertExitCode(0);

        $this->assertSame(ScoreBatchStatusEnum::EXPIRED, $a->fresh()->status);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $c->fresh()->status, '無到期日永不失效');
        $expireLog = MemberScoreLog::query()->where('batch_id', $a->id)->where('type', ScoreLogTypeEnum::EXPIRE->value)->sole();
        $this->assertSame('50.0000', $this->dec($expireLog->score));
        $this->assertSame('50.0000', $this->service->balance($member->id));

        // 步驤 7：扣 999 整筆擋下，餘額不變
        $this->artisan('score:adjust', ['action' => 'deduct', 'member' => $id, 'score' => '999', '--yes' => true])
            ->expectsOutputToContain('餘額不足，整筆擋下')
            ->assertExitCode(1);

        $this->assertSame('50.0000', $this->service->balance($member->id));
        $this->assertSame('50.0000', $this->dec($c->fresh()->remaining_score));
    }

    public function test_dry_run_rolls_back(): void
    {
        $member = $this->newMember();

        $this->artisan('score:adjust', ['action' => 'grant', 'member' => (string) $member->id, 'score' => '10', '--dry-run' => true])
            ->expectsOutputToContain('可用積分 0.0000 → 10.0000')
            ->expectsOutputToContain('dry-run：已回滾')
            ->assertExitCode(0);

        $this->assertSame(0, MemberScoreBatch::query()->where('member_id', $member->id)->count());
        $this->assertSame('0.0000', $this->service->balance($member->id));
    }

    public function test_member_can_be_resolved_by_username_and_log_carries_operator_remark(): void
    {
        $member = $this->newMember();

        $this->artisan('score:adjust', [
            'action' => 'grant',
            'member' => $member->username,
            'score' => '12.34',
            '--remark' => 'QA 手動補分',
            '--operator' => 'qa01',
            '--yes' => true,
        ])->assertExitCode(0);

        $log = MemberScoreLog::query()->where('member_id', $member->id)->sole();
        $this->assertSame('12.3400', $this->dec($log->score));
        $this->assertSame('QA 手動補分', $log->remark);
        $this->assertSame('qa01', $log->operator);
        $this->assertNull($log->expired_at);
    }

    public function test_unknown_member_and_invalid_action_fail_fast(): void
    {
        $this->artisan('score:adjust', ['action' => 'grant', 'member' => 'no_such_user_' . bin2hex(random_bytes(3)), 'score' => '10', '--yes' => true])
            ->expectsOutputToContain('找不到會員')
            ->assertExitCode(1);

        $this->artisan('score:adjust', ['action' => 'refund', 'member' => '1', 'score' => '10', '--yes' => true])
            ->expectsOutputToContain('action 只接受 grant 或 deduct')
            ->assertExitCode(1);
    }

    public function test_invalid_score_is_rejected_and_rolled_back(): void
    {
        $member = $this->newMember();

        $this->artisan('score:adjust', ['action' => 'grant', 'member' => (string) $member->id, 'score' => '1.005', '--yes' => true])
            ->expectsOutputToContain('執行失敗，已回滾')
            ->assertExitCode(1);

        $this->assertSame(0, MemberScoreBatch::query()->where('member_id', $member->id)->count());
    }

    private function newMember(): Member
    {
        return $this->createMember($this->agent, ['username' => 'score_' . bin2hex(random_bytes(4))]);
    }

    private function dec(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
