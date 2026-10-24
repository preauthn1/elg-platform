<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Summary;

use App\Services\Summary\SmsCostSummaryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * summary:event-record-sms：簡訊成本彙總 event 4（target/tickets#4424）。
 *
 * 守四件事：排程窗口只動窗內、只計成功且屬於會員的發送、回補以 +8 日曆日切、
 * 以及兩張表的刪除與重建在同一個交易裡（原 SP 分兩段裸跑才會漏整小時）。
 *
 * php artisan test tests/Feature/Console/Commands/Summary/SummarizeSmsCostTest.php
 */
class SummarizeSmsCostTest extends TestCase
{
    private const int EVENT_ID = SmsCostSummaryService::EVENT_ID;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rebuilds_only_the_recent_hour_window(): void
    {
        Carbon::setTestNow('2026-09-10 06:20:00'); // UTC；窗口 [03:00, 06:00)

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'sms_' . uniqid()]);

        $this->insertSend($member->id, '2026-09-10 04:15:00', '0.4595');
        $this->insertSend($member->id, '2026-09-10 04:45:00', '0.3061');
        $this->insertSend($member->id, '2026-09-10 05:59:00', '0.1100');
        $this->insertSend($member->id, '2026-09-10 02:30:00', '9.0000'); // 窗口外

        // 窗口外（02:00 桶）預先放一筆舊值，排程不該碰它
        DB::table('summary_event_records')->insert([
            'summary_time' => '2026-09-10 02:00:00', 'member_id' => $member->id, 'event_id' => self::EVENT_ID, 'point' => 111,
        ]);

        $this->artisan('summary:event-record-sms', ['--hours' => 3])->assertSuccessful();

        // 04:00 桶是同一小時兩則相加：0.4595 + 0.3061
        $this->assertSame(
            ['2026-09-10 02:00:00' => '111.000000', '2026-09-10 04:00:00' => '0.765600', '2026-09-10 05:00:00' => '0.110000'],
            $this->memberBuckets($member->id),
        );

        // 代理彙總必須與會員彙總同步產出——原 SP 漏的就是這張表
        $this->assertSame(
            ['2026-09-10 04:00:00' => '0.765600', '2026-09-10 05:00:00' => '0.110000'],
            $this->agentBuckets($agent->id),
        );

        $this->cleanup($member->id, $agent->id);
    }

    /**
     * 只計 status = 1 且 member_id > 0 的發送。
     *
     * member_id = 0 是代理發的簡訊（agent_id 才有值）。原 SP 的 JOIN members 把它們整批排除，
     * 因為這張彙總表以 member_id 為鍵、代理簡訊沒有會員可歸屬；TPower 實測九月份有 82% 的成功
     * 發送屬於這一類，拿掉 join 後若不補 member_id > 0，會灌進大量 member_id = 0 的列。
     */
    public function test_ignores_failed_sends_and_agent_sms(): void
    {
        Carbon::setTestNow('2026-09-10 06:20:00');

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'sms_' . uniqid()]);

        $this->insertSend($member->id, '2026-09-10 04:10:00', '0.5000');
        $this->insertSend($member->id, '2026-09-10 04:20:00', '7.0000', status: 2);   // 發送失敗
        $this->insertSend(0, '2026-09-10 04:30:00', '3.0000', agentId: $agent->id);   // 代理簡訊

        $this->artisan('summary:event-record-sms', ['--hours' => 3])->assertSuccessful();

        $this->assertSame(['2026-09-10 04:00:00' => '0.500000'], $this->memberBuckets($member->id));
        $this->assertSame([], $this->memberBuckets(0));

        $this->cleanup($member->id, $agent->id);
    }

    public function test_backfills_by_taipei_calendar_days(): void
    {
        Carbon::setTestNow('2026-09-12 06:20:00'); // UTC

        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['username' => 'sms_' . uniqid()]);

        // +8 的 09-10 ＝ UTC [09-09 16:00, 09-10 16:00)
        $this->insertSend($member->id, '2026-09-09 16:30:00', '0.4000');  // 在 09-10(+8) 內
        $this->insertSend($member->id, '2026-09-09 15:30:00', '0.6000');  // 落在 09-09(+8)，區間外

        $this->artisan('summary:event-record-sms', ['--from' => '2026-09-10', '--to' => '2026-09-10'])
            ->assertSuccessful();

        $this->assertSame(['2026-09-09 16:00:00' => '0.400000'], $this->memberBuckets($member->id));

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

        app(SmsCostSummaryService::class)->rebuild('2026-09-10 03:00:00', '2026-09-10 06:00:00');

        // 兩次 DELETE ＋ 兩次 INSERT 都必須在交易裡；任何一句落在交易外，失敗時就會留下刪掉補不回來的空格
        $this->assertNotEmpty($levels, '沒有攔截到彙總表的語句，測試本身失效');
        $this->assertNotContains(0, $levels, '彙總表的刪除或寫入跑在交易外');

        DB::table('summary_event_record_agents')->where('agent_id', $agent->id)->delete();
    }

    private function insertSend(int $memberId, string $createdAt, string $cost, int $status = 1, int $agentId = 0): void
    {
        DB::table('sms_send_records')->insert([
            'sms_gateway_id' => 11,
            'sms_gateway_info_id' => 38,
            'cost' => $cost,
            'agent_id' => $agentId,
            'member_id' => $memberId,
            'mobile' => '84' . random_int(100000000, 999999999),
            'message_content' => 'test',
            'status' => $status,
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
