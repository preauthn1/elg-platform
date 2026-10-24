<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Enums\Statistics\MemberHourlySourceEnum;
use App\Jobs\UpdateMemberHourSummary;
use App\Models\MemberHourlyStatistic;
use Tests\TestCase;

/**
 * 驗證 member_hourly_statistics 小時匯總累加：整點分桶、count/amount 累加、同小時合併、跨小時分列；
 * 4333 六類來源：帶 source 同時累加總額欄與明細欄，不帶只累加總額。
 *
 * php artisan test tests/Unit/Jobs/UpdateMemberHourSummaryTest.php
 */
class UpdateMemberHourSummaryTest extends TestCase
{
    public function test_deposit_creates_row_bucketed_to_hour_and_accumulates(): void
    {
        $memberId = 990001;
        $occurredAt = '2026-07-08 03:30:00';

        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '100.5000', $occurredAt))->handle();

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertNotNull($summary);
        $this->assertEquals('2026-07-08 03:00:00', $summary->summary_hour);
        $this->assertEquals(1, $summary->deposit_count);
        $this->assertEquals('100.5000', $summary->deposit_amount);

        // 同會員同整點再一筆 → 累加，不新增列
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '50.2500', $occurredAt))->handle();

        $this->assertEquals(1, MemberHourlyStatistic::where('member_id', $memberId)->count());
        $summary->refresh();
        $this->assertEquals(2, $summary->deposit_count);
        $this->assertEquals('150.7500', $summary->deposit_amount);
    }

    public function test_deposit_and_withdrawal_same_hour_share_one_row(): void
    {
        $memberId = 990002;
        $occurredAt = '2026-07-08 03:45:00';

        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '200', $occurredAt))->handle();
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_WITHDRAWAL, '80', $occurredAt))->handle();

        $this->assertEquals(1, MemberHourlyStatistic::where('member_id', $memberId)->count());

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertEquals(1, $summary->deposit_count);
        $this->assertEquals('200.0000', $summary->deposit_amount);
        $this->assertEquals(1, $summary->withdrawal_count);
        $this->assertEquals('80.0000', $summary->withdrawal_amount);
    }

    public function test_different_hours_create_separate_rows(): void
    {
        $memberId = 990003;

        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '10', '2026-07-08 03:10:00'))->handle();
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '10', '2026-07-08 04:10:00'))->handle();

        $this->assertEquals(2, MemberHourlyStatistic::where('member_id', $memberId)->count());
    }

    public function test_source_accumulates_detail_columns_alongside_totals(): void
    {
        $memberId = 990004;
        $occurredAt = '2026-07-08 05:10:00';

        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '100', $occurredAt, MemberHourlySourceEnum::THIRD_PARTY_DEPOSIT->value))->handle();
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '300', $occurredAt, MemberHourlySourceEnum::AUTO_DEPOSIT->value))->handle();
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '50', $occurredAt, MemberHourlySourceEnum::ADMIN_DEPOSIT->value))->handle();
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_WITHDRAWAL, '200', $occurredAt, MemberHourlySourceEnum::THIRD_PARTY_WITHDRAWAL->value))->handle();
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_WITHDRAWAL, '70', $occurredAt, MemberHourlySourceEnum::AUTO_WITHDRAWAL->value))->handle();
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_WITHDRAWAL, '30', $occurredAt, MemberHourlySourceEnum::ADMIN_WITHDRAWAL->value))->handle();

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertEquals(1, MemberHourlyStatistic::where('member_id', $memberId)->count());

        // 總額＝三類明細相加
        $this->assertEquals(3, $summary->deposit_count);
        $this->assertEquals('450.0000', $summary->deposit_amount);
        $this->assertEquals(1, $summary->third_party_deposit_count);
        $this->assertEquals('100.0000', $summary->third_party_deposit_amount);
        $this->assertEquals(1, $summary->auto_deposit_count);
        $this->assertEquals('300.0000', $summary->auto_deposit_amount);
        $this->assertEquals(1, $summary->admin_deposit_count);
        $this->assertEquals('50.0000', $summary->admin_deposit_amount);

        $this->assertEquals(3, $summary->withdrawal_count);
        $this->assertEquals('300.0000', $summary->withdrawal_amount);
        $this->assertEquals(1, $summary->third_party_withdrawal_count);
        $this->assertEquals('200.0000', $summary->third_party_withdrawal_amount);
        $this->assertEquals(1, $summary->auto_withdrawal_count);
        $this->assertEquals('70.0000', $summary->auto_withdrawal_amount);
        $this->assertEquals(1, $summary->admin_withdrawal_count);
        $this->assertEquals('30.0000', $summary->admin_withdrawal_amount);
    }

    public function test_mismatched_or_unknown_source_only_touches_totals(): void
    {
        $memberId = 990005;
        $occurredAt = '2026-07-08 06:10:00';

        // 充值型別配提現來源 → 來源忽略，只累加總額
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '100', $occurredAt, MemberHourlySourceEnum::ADMIN_WITHDRAWAL->value))->handle();
        // 未知來源字串 → 同上
        (new UpdateMemberHourSummary($memberId, UpdateMemberHourSummary::TYPE_DEPOSIT, '20', $occurredAt, 'no_such_source'))->handle();

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertEquals(2, $summary->deposit_count);
        $this->assertEquals('120.0000', $summary->deposit_amount);
        $this->assertEquals(0, $summary->third_party_deposit_count + $summary->auto_deposit_count + $summary->admin_deposit_count);
        $this->assertEquals(0, $summary->admin_withdrawal_count);
    }

    /**
     * 部署切換瞬間 queue 裡的舊版 payload 沒有 source 屬性：反序列化後 handle() 不能炸，只累加總額。
     */
    public function test_legacy_payload_without_source_property_still_handles(): void
    {
        $memberId = 990006;
        $job = (new \ReflectionClass(UpdateMemberHourSummary::class))->newInstanceWithoutConstructor();
        $job->memberId = $memberId;
        $job->type = UpdateMemberHourSummary::TYPE_DEPOSIT;
        $job->point = '40';
        $job->occurredAt = '2026-07-08 08:10:00';

        $job->handle();

        $summary = MemberHourlyStatistic::where('member_id', $memberId)->first();
        $this->assertEquals(1, $summary->deposit_count);
        $this->assertEquals('40.0000', $summary->deposit_amount);
        $this->assertEquals(0, $summary->third_party_deposit_count + $summary->auto_deposit_count + $summary->admin_deposit_count);
    }

    public function test_member_id_zero_is_skipped(): void
    {
        (new UpdateMemberHourSummary(0, UpdateMemberHourSummary::TYPE_WITHDRAWAL, '500', '2026-07-08 07:10:00', MemberHourlySourceEnum::AUTO_WITHDRAWAL->value))->handle();

        $this->assertEquals(0, MemberHourlyStatistic::where('member_id', 0)->where('summary_hour', '2026-07-08 07:00:00')->count());
    }
}
