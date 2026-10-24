<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\EventV2;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Enums\Score\ScoreLogDirectionEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Helpers\Common;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Http\Requests\Agent\EventV2\CreateRequest;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\MemberScoreLog;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 活動 V2 參加紀錄的「消耗積分」欄與統計卡（target/tickets#4235）。
 *
 * 必須成立：
 * 1. 每筆回 score_cost＝參照該筆參加的積分扣除加總；沒扣分的活動回 null（前端顯示「—」，不是 0）。
 * 2. summary 依同一組篩選算「全部」筆數／獎勵加總／消耗積分加總，翻頁不變；篩到沒資料回 0 不回 null。
 * 3. 既有欄位照舊回，只是多一個 score_cost（迴歸：其他類型的紀錄不受影響）。
 * 4. 依參加紀錄凍結的 withdraw_type 分流：主錢包型 reward 有值、score_reward null；積分型反過來，withdraw 也是 null。
 * 5. 八張統計卡：積分不混進任何金額卡；total_reward ＝ 完成筆 reward ＋ 進行中 ＋ 放棄 ＋ 重置，已發放取實領 withdraw。
 *
 * php artisan test tests/Feature/Agent/EventV2/JoinListScoreCostTest.php
 */
class JoinListScoreCostTest extends TestCase
{
    private const string JOIN_LIST = 'agent/api/event-v2/join-list';

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        if (array_key_exists('is_backend_deposit', (new CreateRequest)->rules())
            && !Schema::hasColumn('event_v2_details', 'is_backend_deposit')) {
            $this->markTestSkipped('event_v2_details.is_backend_deposit 尚無 migration（target/tickets#4352），後台活動 V2 端點無法在乾淨庫執行');
        }

        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        $this->agent = $this->createAgentAndDomain(['host_id' => 1]);
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        $this->member = $this->createMember($this->agent, ['username' => 'jl' . bin2hex(random_bytes(5))]);
    }

    public function test_score_cost_per_row_and_summary_over_all_pages(): void
    {
        $angpow = $this->createDetail('AngpowService', '紅包');
        $daily = $this->createDetail('DailyFreeService', '每日登入');

        // 紅包兩筆各扣 10 分、獎勵 20／35；每日登入一筆沒扣分、獎勵 5
        $a1 = $this->createJoin($angpow, '20');
        $this->deductLog($a1, '10');
        $a2 = $this->createJoin($angpow, '35');
        $this->deductLog($a2, '10');
        $this->createJoin($daily, '5');

        // 只篩本測試的代理，避免撞到庫裡其他紀錄。三筆同一秒建立、列表只按時間排序，
        // 分頁切法不穩定，逐列斷言用一頁撈全部；翻頁只驗統計卡不變
        $all = $this->list()->assertOk()->assertJsonPath('code', 200);
        $byId = collect($all->json('data.items'))->keyBy('id');
        $this->assertCount(3, $byId);

        // JSON 把 10.0 編成 10，型別不固定，用 assertEquals 比值
        $this->assertEquals(10, $byId[$a1->id]['score_cost']);
        $this->assertEquals(10, $byId[$a2->id]['score_cost']);
        $dailyRow = $byId->first(fn (array $row) => $row['event_v2_detail_id'] === $daily->id);
        $this->assertNull($dailyRow['score_cost'], '沒扣分的活動回 null 不回 0');
        $this->assertEquals(20, $byId[$a1->id]['reward'], '既有欄位照舊');

        // 統計卡：每頁 2 筆分兩頁，兩頁都回同一組全量數字
        $page1 = $this->list(['page' => 1, 'per_page' => 2])->assertOk();
        $page2 = $this->list(['page' => 2, 'per_page' => 2])->assertOk();
        $this->assertCount(2, $page1->json('data.items'));
        $this->assertCount(1, $page2->json('data.items'));
        foreach ([$all, $page1, $page2] as $page) {
            $page->assertJsonPath('data.summary.total_count', 3)
                ->assertJsonPath('data.summary.total_reward', '60.0000')
                ->assertJsonPath('data.summary.total_score', '20.0000');
        }
    }

    public function test_summary_follows_filters_and_returns_zero_when_empty(): void
    {
        $angpow = $this->createDetail('AngpowService', '紅包');
        $daily = $this->createDetail('DailyFreeService', '每日登入');
        $this->deductLog($this->createJoin($angpow, '20'), '10');
        $this->createJoin($daily, '5');

        // 篩某一檔活動：只統計該檔
        $this->list(['event_v2_detail_id' => $angpow->id])->assertOk()
            ->assertJsonPath('data.summary.total_count', 1)
            ->assertJsonPath('data.summary.total_reward', '20.0000')
            ->assertJsonPath('data.summary.total_score', '10.0000');

        // 篩到沒資料：三個都是 0，不是 null、不報錯
        $this->list(['member_username' => 'nobody-' . Str::random(6)])->assertOk()
            ->assertJsonPath('data.summary.total_count', 0)
            ->assertJsonPath('data.summary.total_reward', '0.0000')
            ->assertJsonPath('data.summary.total_score', '0.0000')
            ->assertJsonPath('data.pagination.total', 0);
    }

    public function test_rows_split_cash_and_score_by_frozen_withdraw_type(): void
    {
        $wallet = $this->createDetail('DailyFreeService', '每日登入');
        $score = $this->createDetail('RegisterService', '註冊');
        $cash = $this->createJoin($wallet, '30');
        $points = $this->createJoin($score, '20', withdraw: null, type: EventV2WithdrawTypeEnum::SCORE);

        // 活動中途改成主錢包：歷史那筆仍照參加當下凍結的積分型顯示
        $score->forceFill(['withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value])->save();

        $byId = collect($this->list()->assertOk()->json('data.items'))->keyBy('id');
        $this->assertEquals(30, $byId[$cash->id]['reward']);
        $this->assertNull($byId[$cash->id]['score_reward'], '主錢包型沒有獲得積分');
        $this->assertEquals(30, $byId[$cash->id]['withdraw']);

        $this->assertNull($byId[$points->id]['reward'], '積分型的獎勵欄顯示「—」');
        $this->assertEquals(20, $byId[$points->id]['score_reward']);
        $this->assertNull($byId[$points->id]['withdraw'], '積分沒轉進主錢包，洗分欄是 null 不是 0');
    }

    public function test_eight_summary_cards_split_by_type_and_status(): void
    {
        $wallet = $this->createDetail('DailyFreeService', '每日登入');
        $score = $this->createDetail('RegisterService', '註冊');

        // 主錢包型：完成 reward 50 實領 45、進行中 10、放棄 7＋後台操作放棄 3、重置 4；積分型 20（另扣 10 分）
        $this->createJoin($wallet, '50', withdraw: '45');
        $this->createJoin($wallet, '10', withdraw: '0', status: EventV2UserJoinStatusEnum::JOIN);
        $this->createJoin($wallet, '7', withdraw: '0', status: EventV2UserJoinStatusEnum::QUIT);
        $this->createJoin($wallet, '3', withdraw: '0', status: EventV2UserJoinStatusEnum::OPERATOR_QUIT);
        $this->createJoin($wallet, '4', withdraw: '0', status: EventV2UserJoinStatusEnum::SYSTEM_INIT);
        $this->deductLog($this->createJoin($score, '20', withdraw: null, type: EventV2WithdrawTypeEnum::SCORE), '10');

        $summary = $this->list()->assertOk()->json('data.summary');
        $this->assertSame([
            'total_count' => 6,
            'total_reward' => '74.0000',
            'total_score_reward' => '20.0000',
            'total_score' => '10.0000',
            'total_issued' => '45.0000',
            'total_joining' => '10.0000',
            'total_quit' => '10.0000',
            'total_reset' => '4.0000',
        ], $summary, '積分 20 不進任何金額卡');

        // 驗算：總獎勵金額 ＝ 完成筆 reward(50) ＋ 進行中 ＋ 放棄 ＋ 重置；已發放取實領，不在等式內
        $this->assertSame($summary['total_reward'], Common::numAdd(Common::numAdd(Common::numAdd('50', $summary['total_joining'], 4), $summary['total_quit'], 4), $summary['total_reset'], 4));

        // 空條件：八個都是 0
        $empty = $this->list(['member_username' => 'nobody-' . Str::random(6)])->assertOk()->json('data.summary');
        $this->assertSame(0, $empty['total_count']);
        foreach (['total_reward', 'total_score_reward', 'total_score', 'total_issued', 'total_joining', 'total_quit', 'total_reset'] as $key) {
            $this->assertSame('0.0000', $empty[$key], $key);
        }
    }

    // -------------------------------------------------------

    private function list(array $params = [])
    {
        return $this->postJson(self::JOIN_LIST, array_merge([
            'agent_username' => $this->agent->username,
            'page' => 1,
            'per_page' => 15,
        ], $params), $this->headers);
    }

    private function createDetail(string $handlerClass, string $typeName): EventV2Detail
    {
        $typeId = EventV2::query()->firstOrCreate(['handler_class' => $handlerClass], ['name' => $typeName, 'schedule' => null])->id;

        return EventV2Detail::factory()->create([
            'event_v2_id' => $typeId,
            'agent_id' => $this->agent->id,
            'condition_config' => ['score' => 10],
            'reward_formula' => '20',
            'withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value,
            'join_withdraw' => 1,
            'is_recycle' => 1,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ]);
    }

    /** Observer 讀前台 user context（測試沒有），關掉 events 建 */
    private function createJoin(
        EventV2Detail $detail,
        string $reward,
        ?string $withdraw = 'same',
        EventV2UserJoinStatusEnum $status = EventV2UserJoinStatusEnum::COMPLETE,
        EventV2WithdrawTypeEnum $type = EventV2WithdrawTypeEnum::MAIN_WALLET,
    ): EventV2UserJoin {
        return EventV2UserJoin::withoutEvents(fn () => EventV2UserJoin::create([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'reward' => $reward,
            'withdraw' => $withdraw === 'same' ? $reward : $withdraw,
            'withdraw_type' => $type->value,
            'status' => $status->value,
        ]));
    }

    /** 模擬 AngpowService::afterJoinRecorded() 留下的扣分紀錄：參照 event_v2_user_joins.id、方向扣除 */
    private function deductLog(EventV2UserJoin $join, string $score): void
    {
        MemberScoreLog::create([
            'member_id' => $this->member->id,
            'batch_id' => 0,
            'type' => ScoreLogTypeEnum::CONSUME->value,
            'direction' => ScoreLogDirectionEnum::DECREASE->value,
            'score' => $score,
            'before_score' => '100',
            'after_score' => '90',
            'reference_type' => ScoreSourceTypeEnum::EVENT_V2_USER_JOIN->value,
            'reference_id' => $join->id,
        ]);
    }
}
