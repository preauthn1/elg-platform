<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\WalletEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Services\MemberScoreService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/VerifyMemberScoreBalanceTest.php
 *
 * score:verify-balance（target/tickets#4233）：一致時零差異、投影漂移時偵測、--fix 以批次表為準重算、
 * 已到期未掃的批次不算差異。
 */
class VerifyMemberScoreBalanceTest extends TestCase
{
    private Agent $agent;

    private MemberScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
        $this->agent = $this->createAgentAndDomain();
        $this->service = app(MemberScoreService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_consistent_member_has_no_diff(): void
    {
        $member = $this->newMember();
        $this->service->grant($member, '30', 24, ScoreSourceTypeEnum::MANUAL);
        $this->service->grant($member, '20', null, ScoreSourceTypeEnum::MANUAL);

        $this->artisan('score:verify-balance', ['--member' => $member->id])
            ->expectsOutputToContain('差異 0 位')
            ->assertExitCode(0);
    }

    public function test_drifted_projection_is_detected_and_fixed_from_batches(): void
    {
        $member = $this->newMember();
        $this->service->grant($member, '30', 24, ScoreSourceTypeEnum::MANUAL);

        // 模擬投影漂移（例如 schema 重建後錢包列殘留舊值）
        $this->scoreWallet($member)->update(['balance' => '35.0000000000']);

        $this->artisan('score:verify-balance', ['--member' => $member->id])
            ->expectsOutputToContain('差異 1 位')
            ->assertExitCode(0);
        $this->assertSame('35.0000', $this->walletBalance($member), '不帶 --fix 只告警不改資料');

        $this->artisan('score:verify-balance', ['--member' => $member->id, '--fix' => true])
            ->expectsOutputToContain('已重算 1 位')
            ->assertExitCode(0);
        $this->assertSame('30.0000', $this->walletBalance($member), '以批次表為準重算');
        $this->assertSame('30.0000', $this->service->balance($member->id));
    }

    public function test_expired_but_unswept_batch_is_not_a_diff(): void
    {
        $member = $this->newMember();
        $this->service->grant($member, '30', 1, ScoreSourceTypeEnum::MANUAL);

        // 兩小時後批次已過期但排程還沒掃：批次仍 ACTIVE、錢包列仍 30，兩邊一致，不該誤報
        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->artisan('score:verify-balance', ['--member' => $member->id])
            ->expectsOutputToContain('差異 0 位')
            ->assertExitCode(0);
    }

    private function newMember(): Member
    {
        return $this->createMember($this->agent, ['username' => 'sv' . bin2hex(random_bytes(4))]);
    }

    private function scoreWallet(Member $member): MemberWallet
    {
        return MemberWallet::query()
            ->where('member_id', $member->id)
            ->where('wallet_type_id', WalletEnum::CODE_SCORE->value)
            ->sole();
    }

    private function walletBalance(Member $member): string
    {
        return number_format((float) $this->scoreWallet($member)->balance, 4, '.', '');
    }
}
