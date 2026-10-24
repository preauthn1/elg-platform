<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Score\ScoreBatchStatusEnum;
use App\Enums\Score\ScoreLogDirectionEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\WalletEnum;
use App\Exceptions\InsufficientScoreException;
use App\Models\Member;
use App\Models\MemberScoreBatch;
use App\Models\MemberScoreLog;
use App\Models\MemberWallet;
use App\Services\MemberScoreService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/MemberScoreServiceTest.php
 *
 * 積分核心（target/tickets#4233）：發放建批次、FIFO 扣點跨批拆筆、餘額不足整筆擋下、
 * 到期失效、發放／扣點前即時到期。時間以 Carbon::setTestNow() 控制，不等真實時鐘。
 *
 * TestCase 已 use DatabaseTransactions，測試本身就在交易內，Service 的交易斷言自然成立。
 */
class MemberScoreServiceTest extends TestCase
{
    private MemberScoreService $service;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));

        $this->service = app(MemberScoreService::class);
        $this->member = $this->createMember($this->createAgentAndDomain());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -------------------------------------------------------
    // grant
    // -------------------------------------------------------

    public function test_grant_creates_batch_log_and_wallet_projection(): void
    {
        $batch = $this->service->grant(
            member: $this->member,
            score: '100.5',
            hours: 24,
            source: ScoreSourceTypeEnum::EVENT_V2_USER_JOIN,
            sourceId: 777,
        );

        $this->assertSame('100.5000', (string) $batch->score);
        $this->assertSame('100.5000', (string) $batch->remaining_score);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $batch->status);
        $this->assertSame(ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, $batch->source_type);
        $this->assertSame(777, $batch->source_id);
        $this->assertSame('2026-09-16 12:00:00', $batch->expired_at?->toDateTimeString());

        $log = MemberScoreLog::query()->where('batch_id', $batch->id)->sole();
        $this->assertSame(ScoreLogTypeEnum::REWARD, $log->type);
        $this->assertSame(ScoreLogDirectionEnum::INCREASE, $log->direction);
        $this->assertSame('100.5000', (string) $log->score);
        $this->assertSame('0.0000', (string) $log->before_score);
        $this->assertSame('100.5000', (string) $log->after_score);
        $this->assertSame(ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, $log->reference_type, '系統發放的 log 對回來源系統');
        $this->assertSame(777, $log->reference_id);
        $this->assertSame('2026-09-16 12:00:00', $log->expired_at?->toDateTimeString());

        $this->assertSame('100.5000', $this->walletBalance());
        $this->assertSame('100.5000', $this->service->balance($this->member->id));
    }

    public function test_grant_without_hours_has_no_expiry_and_manual_source_does_not_bind_join(): void
    {
        $batch = $this->service->grant(
            member: $this->member,
            score: '50',
            hours: null,
            source: ScoreSourceTypeEnum::MANUAL,
            sourceId: 999, // 人工調整不該把這個值當成活動單據
            remark: '測試備註',
            operator: 'agent01',
            adminId: 5,
        );

        $this->assertNull($batch->expired_at);

        $log = MemberScoreLog::query()->where('batch_id', $batch->id)->sole();
        $this->assertSame(ScoreLogTypeEnum::MANUAL, $log->type);
        $this->assertSame(ScoreSourceTypeEnum::NONE, $log->reference_type, '人工調整無參照');
        $this->assertSame(0, $log->reference_id);
        $this->assertNull($log->expired_at);
        $this->assertSame('測試備註', $log->remark);
        $this->assertSame('agent01', $log->operator);
        $this->assertSame(5, $log->admin);
    }

    /**
     * 到期時間進位到下一個 10 分鐘刻度（Max 2026-09-16 定案）：14:15:47→14:20、14:20:01→14:30、14:55→15:00；
     * 剛好在刻度上不動。setUp 釘的 12:00:00 正好在刻度上，所以其他測試的到期斷言不受影響。
     */
    public function test_grant_expiry_is_ceiled_to_next_ten_minute_grid(): void
    {
        $cases = [
            '2026-09-16 14:15:47' => '2026-09-18 14:20:00',
            '2026-09-16 14:20:00' => '2026-09-18 14:20:00',
            '2026-09-16 14:20:01' => '2026-09-18 14:30:00',
            '2026-09-16 14:55:00' => '2026-09-18 15:00:00',
            '2026-09-16 23:59:30' => '2026-09-19 00:00:00',
        ];

        foreach ($cases as $now => $expected) {
            Carbon::setTestNow(Carbon::parse($now, 'UTC'));
            $batch = $this->grantHours('1', 48);
            $this->assertSame($expected, $batch->expired_at?->toDateTimeString(), "now={$now}");
        }
    }

    public function test_grant_rejects_more_than_two_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 2 decimal places');

        $this->service->grant($this->member, '10.505', null, ScoreSourceTypeEnum::MANUAL);
    }

    public function test_grant_rejects_non_positive_score(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive');

        $this->service->grant($this->member, '0', null, ScoreSourceTypeEnum::MANUAL);
    }

    public function test_grant_rejects_non_positive_hours(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hours must be a positive integer');

        $this->service->grant($this->member, '10', 0, ScoreSourceTypeEnum::MANUAL);
    }

    // -------------------------------------------------------
    // deduct — 工單 #4233 需求 1 的測試案例
    // -------------------------------------------------------

    public function test_grant_expires_due_batches_before_crediting(): void
    {
        $shortLived = $this->grantHours('200', 1);

        // 排程還沒掃到，錢包列仍含 200；兩小時後再發放必須先把那 200 清掉，
        // 否則新批次 log 的 before 與投影餘額會多算已到期的分數（與 deduct 對稱）
        Carbon::setTestNow(Carbon::now()->addHours(2));

        $fresh = $this->grantHours('50', null);

        $this->assertSame(ScoreBatchStatusEnum::EXPIRED, $shortLived->fresh()->status);
        $this->assertSame('0.0000', (string) $shortLived->fresh()->remaining_score);

        $expireLog = MemberScoreLog::query()->where('batch_id', $shortLived->id)->where('type', ScoreLogTypeEnum::EXPIRE->value)->sole();
        $this->assertSame('200.0000', (string) $expireLog->before_score);
        $this->assertSame('0.0000', (string) $expireLog->after_score);

        $grantLog = MemberScoreLog::query()->where('batch_id', $fresh->id)->sole();
        $this->assertSame('0.0000', (string) $grantLog->before_score, '入帳前餘額已扣掉過期批次');
        $this->assertSame('50.0000', (string) $grantLog->after_score);

        $this->assertSame('50.0000', $this->walletBalance());
    }

    public function test_deduct_follows_fifo_and_leaves_unlimited_batch_untouched(): void
    {
        // A 100（到期 10/01）、B 200（到期 09/20）、C 50（無到期）；now = 09/15 12:00
        $a = $this->grantHours('100', 16 * 24);
        $b = $this->grantHours('200', 5 * 24);
        $c = $this->grantHours('50', null);

        $this->assertSame('350.0000', $this->service->balance($this->member->id));

        $logs = $this->service->deduct($this->member, '250', ScoreLogTypeEnum::CONSUME, referenceType: ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, referenceId: 4231);

        // B 先扣光、再扣 A 50；C 完全沒被動到
        $this->assertSame('0.0000', (string) $b->fresh()->remaining_score);
        $this->assertSame(ScoreBatchStatusEnum::EXHAUSTED, $b->fresh()->status);
        $this->assertSame('50.0000', (string) $a->fresh()->remaining_score);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $a->fresh()->status);
        $this->assertSame('50.0000', (string) $c->fresh()->remaining_score);

        // 跨兩批拆兩筆，各自綁被扣的批次，after_score 依序累減
        $this->assertCount(2, $logs);
        $this->assertSame($b->id, $logs[0]->batch_id);
        $this->assertSame('200.0000', (string) $logs[0]->score);
        $this->assertSame('350.0000', (string) $logs[0]->before_score);
        $this->assertSame('150.0000', (string) $logs[0]->after_score);
        $this->assertSame($a->id, $logs[1]->batch_id);
        $this->assertSame('50.0000', (string) $logs[1]->score);
        $this->assertSame('150.0000', (string) $logs[1]->before_score);
        $this->assertSame('100.0000', (string) $logs[1]->after_score);

        foreach ($logs as $log) {
            $this->assertSame(ScoreLogTypeEnum::CONSUME, $log->type);
            $this->assertSame(ScoreLogDirectionEnum::DECREASE, $log->direction);
            $this->assertSame(ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, $log->reference_type);
            $this->assertSame(4231, $log->reference_id);
            $this->assertNull($log->expired_at, '扣除類的到期日留空');
        }

        $this->assertSame('100.0000', $this->walletBalance());
    }

    public function test_deduct_insufficient_blocks_whole_request_without_partial_deduction(): void
    {
        $batch = $this->grantHours('100', 24);

        try {
            $this->service->deduct($this->member, '999', ScoreLogTypeEnum::CONSUME);
            $this->fail('餘額不足應拋 InsufficientScoreException');
        } catch (InsufficientScoreException $e) {
            $this->assertSame('100.0000', $e->available);
            $this->assertSame('999.0000', $e->requested);
        }

        $this->assertSame('100.0000', (string) $batch->fresh()->remaining_score);
        $this->assertSame('100.0000', $this->walletBalance());
        $this->assertSame(
            0,
            MemberScoreLog::query()->where('member_id', $this->member->id)->where('direction', ScoreLogDirectionEnum::DECREASE->value)->count(),
            '不可留下任何部分扣除的紀錄',
        );
    }

    public function test_deduct_expires_due_batches_before_taking(): void
    {
        $unlimited = $this->grantHours('100', null);
        $shortLived = $this->grantHours('200', 1);

        // 排程還沒掃到，錢包列仍含 300；兩小時後扣點必須先把那 200 清掉
        Carbon::setTestNow(Carbon::now()->addHours(2));

        $logs = $this->service->deduct($this->member, '50', ScoreLogTypeEnum::CONSUME);

        $this->assertSame(ScoreBatchStatusEnum::EXPIRED, $shortLived->fresh()->status);
        $this->assertSame('0.0000', (string) $shortLived->fresh()->remaining_score);
        $this->assertSame('50.0000', (string) $unlimited->fresh()->remaining_score);

        $expireLog = MemberScoreLog::query()->where('batch_id', $shortLived->id)->where('type', ScoreLogTypeEnum::EXPIRE->value)->sole();
        $this->assertSame('300.0000', (string) $expireLog->before_score);
        $this->assertSame('100.0000', (string) $expireLog->after_score);

        $this->assertCount(1, $logs);
        $this->assertSame($unlimited->id, $logs[0]->batch_id);
        $this->assertSame('100.0000', (string) $logs[0]->before_score);
        $this->assertSame('50.0000', (string) $logs[0]->after_score);

        $this->assertSame('50.0000', $this->walletBalance());
    }

    public function test_deduct_insufficient_after_lazy_expiry_uses_post_expiry_balance(): void
    {
        $this->grantHours('200', 1);
        Carbon::setTestNow(Carbon::now()->addHours(2));

        try {
            $this->service->deduct($this->member, '10', ScoreLogTypeEnum::CONSUME);
            $this->fail('過期後餘額為 0，應擋下');
        } catch (InsufficientScoreException $e) {
            $this->assertSame('0.0000', $e->available);
        }

        $this->assertSame('0.0000', $this->walletBalance(), '即時到期已把錢包列歸零');
    }

    public function test_deduct_rejects_non_deduction_log_type(): void
    {
        $this->grantHours('100', null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not a deduction type');

        $this->service->deduct($this->member, '10', ScoreLogTypeEnum::REWARD);
    }

    // -------------------------------------------------------
    // expireMember
    // -------------------------------------------------------

    public function test_expire_member_only_touches_due_batches(): void
    {
        $due = $this->grantHours('30', 1);
        $future = $this->grantHours('40', 48);
        $unlimited = $this->grantHours('50', null);

        Carbon::setTestNow(Carbon::now()->addHours(2));

        $logs = $this->service->expireMember($this->member);

        $this->assertCount(1, $logs);
        $this->assertSame($due->id, $logs[0]->batch_id);
        $this->assertSame(ScoreLogTypeEnum::EXPIRE, $logs[0]->type);
        $this->assertSame('30.0000', (string) $logs[0]->score);
        $this->assertSame('120.0000', (string) $logs[0]->before_score);
        $this->assertSame('90.0000', (string) $logs[0]->after_score);
        $this->assertNull($logs[0]->operator, '系統到期無操作人員');

        $this->assertSame(ScoreBatchStatusEnum::EXPIRED, $due->fresh()->status);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $future->fresh()->status);
        $this->assertSame(ScoreBatchStatusEnum::ACTIVE, $unlimited->fresh()->status);
        $this->assertSame('90.0000', $this->walletBalance());
    }

    public function test_expire_member_is_idempotent(): void
    {
        $this->grantHours('30', 1);
        Carbon::setTestNow(Carbon::now()->addHours(2));

        $this->assertCount(1, $this->service->expireMember($this->member));
        $this->assertCount(0, $this->service->expireMember($this->member), '重跑不重複寫紀錄');
        $this->assertSame('0.0000', $this->walletBalance());
    }

    // -------------------------------------------------------
    // Helpers
    public function test_grant_rejects_second_batch_for_same_source_document(): void
    {
        $this->service->grant($this->member, '10', null, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, 4321);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->service->grant($this->member, '10', null, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, 4321);
    }

    public function test_manual_grants_without_source_document_are_not_constrained(): void
    {
        $this->service->grant($this->member, '10', null, ScoreSourceTypeEnum::MANUAL);
        $this->service->grant($this->member, '10', null, ScoreSourceTypeEnum::MANUAL);

        $this->assertSame(2, MemberScoreBatch::query()->where('member_id', $this->member->id)->count());
        $this->assertSame('20.0000', $this->walletBalance());
    }

    // -------------------------------------------------------

    private function grantHours(string $score, ?int $hours): MemberScoreBatch
    {
        return $this->service->grant($this->member, $score, $hours, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN);
    }

    private function walletBalance(): string
    {
        $wallet = MemberWallet::query()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_SCORE->value)
            ->sole();

        return number_format((float) $wallet->balance, 4, '.', '');
    }
}
