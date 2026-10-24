<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\Missions\MissionActionTypeEnum;
use App\Enums\Missions\MissionConditionTypeEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Mission;
use App\Models\TotalBetRecord;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * app:summary-mission-datas 的彙總查詢去重。
 *
 * 階梯式任務（同活動、只有 condition_value 不同）產生的彙總 SQL 完全相同，必須只查一次 DB
 * 再共用結果；total_bet_records.created_at 沒有索引，多查一次就是多掃一次全表。
 *
 * php artisan test tests/Feature/Console/SummaryMissionDatasTest.php
 */
class SummaryMissionDatasTest extends TestCase
{
    private const SUMMARY_DATE = '2026-01-15';

    private const SUMMARY_HOUR = 3;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    public function test_stepped_missions_share_one_summary_query()
    {
        foreach ([20, 50, 100] as $conditionValue) {
            $this->createBetMission($conditionValue);
        }

        $this->createBetRecord(60);
        $this->createBetRecord(40);

        DB::enableQueryLog();

        $this->artisan('app:summary-mission-datas', [
            '--date' => self::SUMMARY_DATE,
            '--hour' => self::SUMMARY_HOUR,
        ])->assertSuccessful();

        $summaryQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query) => str_contains($query['query'], 'total_bet_records'))
            ->values();

        DB::disableQueryLog();

        $this->assertCount(1, $summaryQueries, '三個階梯任務的彙總 SQL 相同，應只查一次 total_bet_records');

        $memberMissions = DB::table('member_missions')
            ->where('member_id', $this->member->id)
            ->get();

        $this->assertCount(3, $memberMissions, '每個任務仍要各自寫入自己的 member_missions');
        $this->assertEquals([100.0], $memberMissions->pluck('progress')->map(fn ($p) => (float) $p)->unique()->values()->all());
    }

    private function createBetMission(int $conditionValue): Mission
    {
        return Mission::factory()->create([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'action_type' => MissionActionTypeEnum::BET->value,
            'condition_type' => MissionConditionTypeEnum::AMOUNT->value,
            'condition_value' => $conditionValue,
            'start_time' => self::SUMMARY_DATE . ' 00:00:00',
            'end_time' => self::SUMMARY_DATE . ' 23:59:59',
            'info' => [
                'action_config' => ['type' => 'bet'],
                'reward_config' => ['type' => 'balance', 'value' => '1'],
                'condition_config' => ['type' => 'amount', 'value' => (string) $conditionValue],
            ],
        ]);
    }

    private function createBetRecord(float $validBet): void
    {
        $createdAt = sprintf('%s %02d:30:00', self::SUMMARY_DATE, self::SUMMARY_HOUR);

        (new TotalBetRecord)->forceFill([
            'summary_time' => $createdAt,
            'member_id' => $this->member->id,
            'vendor_id' => 1,
            'bet_record_id' => random_int(1, PHP_INT_MAX),
            'game_id' => 1,
            'reference_no' => 'ut_' . uniqid(),
            'bet' => $validBet,
            'valid_bet' => $validBet,
            'bet_time' => $createdAt,
            'payout_time' => $createdAt,
            'created_at' => $createdAt,
        ])->save();
    }
}
