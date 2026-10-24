<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Summary;

use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Services\Summary\VipRewardSummaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * summary:event-record-vip：VIP 獎勵彙總 event 9（target/tickets#4420）。
 *
 * 守四件事：排程窗口只動窗內、不濾交易狀態（與原 SP 一致）、回補以 +8 日曆日切、
 * 以及本次改造的重點——兩張表的刪除與重建在同一個交易裡（原 SP 分兩段裸跑才會漏整小時）。
 *
 * php artisan test tests/Feature/Console/Commands/Summary/SummarizeVipRewardTest.php
 */
class SummarizeVipRewardTest extends TestCase
{
    private const int EVENT_ID = VipRewardSummaryService::EVENT_ID;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rebuilds_only_the_recent_hour_window(): void
    {
        Carbon::setTestNow('2026-09-10 06:20:00'); // UTC；窗口 [03:00, 06:00)

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'vip_' . uniqid()]);

        $this->insertVipReward($member->id, '2026-09-10 04:15:00', '2.0000');
        $this->insertVipReward($member->id, '2026-09-10 05:59:00', '3.0000');
        $this->insertVipReward($member->id, '2026-09-10 02:30:00', '9.0000'); // 窗口外

        // 窗口外（02:00 桶）預先放一筆舊值，排程不該碰它
        DB::table('summary_event_records')->insert([
            'summary_time' => '2026-09-10 02:00:00', 'member_id' => $member->id, 'event_id' => self::EVENT_ID, 'point' => 111,
        ]);

        $this->artisan('summary:event-record-vip', ['--hours' => 3])->assertSuccessful();

        $this->assertSame(
            ['2026-09-10 02:00:00' => '111.000000', '2026-09-10 04:00:00' => '2.000000', '2026-09-10 05:00:00' => '3.000000'],
            $this->memberBuckets($member->id),
        );

        // 代理彙總必須與會員彙總同步產出——原 SP 漏的就是這張表
        $this->assertSame(
            ['2026-09-10 04:00:00' => '2.000000', '2026-09-10 05:00:00' => '3.000000'],
            $this->agentBuckets($agent->id),
        );

        $this->cleanup($member->id, $agent->id);
    }

    /**
     * 刻意不濾 status，與原 SP 一致——這個測試是為了擋住「順手加上 status = 1000」的好意。
     *
     * 加了會讓優化器改選 index_merge intersect(idx_transaction_logs_type, idx_transaction_logs_bak_status)，
     * 放棄 created_at 索引，TPower 實測單日回補 10 秒跑不完；而 type 32 自 2026-07-01 起沒有任何一筆非 1000，
     * 濾了也一列都不會少。真要濾請先補 (type, created_at) 複合索引。
     */
    public function test_sums_every_vip_reward_regardless_of_status(): void
    {
        Carbon::setTestNow('2026-09-10 06:20:00');

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'vip_' . uniqid()]);

        $this->insertVipReward($member->id, '2026-09-10 04:10:00', '5.0000');
        $this->insertVipReward($member->id, '2026-09-10 04:20:00', '7.0000', status: 1001);

        $this->artisan('summary:event-record-vip', ['--hours' => 3])->assertSuccessful();

        $this->assertSame(['2026-09-10 04:00:00' => '12.000000'], $this->memberBuckets($member->id));

        $this->cleanup($member->id, $agent->id);
    }

    public function test_backfills_by_taipei_calendar_days(): void
    {
        Carbon::setTestNow('2026-09-12 06:20:00'); // UTC

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'vip_' . uniqid()]);

        // +8 的 09-10 ＝ UTC [09-09 16:00, 09-10 16:00)
        $this->insertVipReward($member->id, '2026-09-09 16:30:00', '4.0000');  // 在 09-10(+8) 內
        $this->insertVipReward($member->id, '2026-09-09 15:30:00', '6.0000');  // 落在 09-09(+8)，區間外

        $this->artisan('summary:event-record-vip', ['--from' => '2026-09-10', '--to' => '2026-09-10'])
            ->assertSuccessful();

        $this->assertSame(['2026-09-09 16:00:00' => '4.000000'], $this->memberBuckets($member->id));

        $this->cleanup($member->id, $agent->id);
    }

    public function test_deletes_and_inserts_run_inside_one_transaction(): void
    {
        $agent = $this->createAgentAndDomain();
        $levels = [];

        DB::listen(function ($query) use (&$levels): void {
            if (preg_match('/summary_event_record/i', $query->sql) === 1) {
                $levels[] = DB::transactionLevel();
            }
        });

        app(VipRewardSummaryService::class)->rebuild('2026-09-10 03:00:00', '2026-09-10 06:00:00');

        // 兩次 DELETE ＋ 兩次 INSERT 都必須在交易裡；任何一句落在交易外，失敗時就會留下刪掉補不回來的空格
        $this->assertNotEmpty($levels, '沒有攔截到彙總表的語句，測試本身失效');
        $this->assertNotContains(0, $levels, '彙總表的刪除或寫入跑在交易外');

        DB::table('summary_event_record_agents')->where('agent_id', $agent->id)->delete();
    }

    private function insertVipReward(int $memberId, string $createdAt, string $point, int $status = TransactionStatusEnum::STATUS_SUCCESS->value): void
    {
        DB::table('transaction_logs')->insert([
            'transaction_no' => 'T' . bin2hex(random_bytes(6)),
            'member_id' => $memberId,
            'type' => TransactionEnum::VIP_REWARD->value,
            'status' => $status,
            'point' => $point,
            'vendor_point' => 0,
            'rate' => 1,
            'operator' => 'test',
            'admin' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** @return array<string, string> summary_time ⇒ point */
    private function memberBuckets(int $memberId): array
    {
        return DB::table('summary_event_records')
            ->where(['member_id' => $memberId, 'event_id' => self::EVENT_ID])
            ->orderBy('summary_time')
            ->pluck('point', 'summary_time')
            ->map(fn ($point) => (string) $point)
            ->all();
    }

    /** @return array<string, string> summary_time ⇒ point */
    private function agentBuckets(int $agentId): array
    {
        return DB::table('summary_event_record_agents')
            ->where(['agent_id' => $agentId, 'event_id' => self::EVENT_ID])
            ->orderBy('summary_time')
            ->pluck('point', 'summary_time')
            ->map(fn ($point) => (string) $point)
            ->all();
    }

    private function cleanup(int $memberId, int $agentId): void
    {
        DB::table('summary_event_records')->where('member_id', $memberId)->delete();
        DB::table('summary_event_record_agents')->where('agent_id', $agentId)->delete();
    }
}
