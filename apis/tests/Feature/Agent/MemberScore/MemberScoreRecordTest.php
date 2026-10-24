<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\MemberScore;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\LanguageEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Services\MemberScoreService;
use Database\Seeders\LanguageSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Agent/MemberScore/MemberScoreRecordTest.php
 *
 * 積分紀錄（target/tickets#4232）：主表只列有積分錢包列且在範圍內的會員、七欄聚合正確、
 * 帳號／所屬代理篩選；明細依時間排序、到期日與活動名稱、期間篩選、範圍外擋。
 */
class MemberScoreRecordTest extends TestCase
{
    private Agent $agent;

    private Member $memberA;

    private Member $memberB;

    private MemberScoreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-15 12:00:00', 'UTC'));
        config(['custom.backend.timezone' => 'Asia/Taipei']);

        // event_v2_detail_langs.language_id 有 FK 指向 languages，測試庫該表為空（比照 EventV2/DetailTest）
        $this->seed(LanguageSeeder::class);

        $this->withoutMiddleware([
            DecryptRequest::class,
            RequestLogger::class,
        ]);

        $this->agent = $this->createAgentAndDomain();
        $this->memberA = $this->createMember($this->agent, ['username' => 'sra' . bin2hex(random_bytes(5))]);
        $this->memberB = $this->createMember($this->agent, ['username' => 'srb' . bin2hex(random_bytes(5))]);
        $this->service = app(MemberScoreService::class);

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

    private function list(array $payload = []): TestResponse
    {
        return $this->postJson('agent/api/score/records', array_merge(['page' => 1, 'per_page' => 50], $payload), $this->headers);
    }

    private function logs(array $payload): TestResponse
    {
        return $this->postJson('agent/api/score/records/logs', array_merge(['page' => 1, 'per_page' => 50], $payload), $this->headers);
    }

    /**
     * A 的資料：30 分（1 小時）→ 兩小時後到期失效；再發 100（24h）、50（無限期）；扣 30。
     * 可用 120、已過期 30、已使用 30、累計 180 → 120 + 30 + 30 = 180。
     */
    private function seedMemberA(): void
    {
        $this->service->grant($this->memberA, '30', 1, ScoreSourceTypeEnum::MANUAL, remark: '會過期的那批');
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->service->expireMember($this->memberA);

        $this->service->grant($this->memberA, '100', 24, ScoreSourceTypeEnum::MANUAL, operator: 'op01');
        $this->service->grant($this->memberA, '50', null, ScoreSourceTypeEnum::MANUAL);
        $this->service->deduct($this->memberA, '30', ScoreLogTypeEnum::MANUAL, remark: '人工扣');
    }

    public function test_list_only_includes_scoped_members_with_score_wallet_and_aggregates_correctly(): void
    {
        $this->seedMemberA();

        // 範圍外會員也有積分，但不該出現
        $this->domain = 'sr' . bin2hex(random_bytes(5)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $outsider = $this->createMember($otherAgent, ['username' => 'sro' . bin2hex(random_bytes(5))]);
        $this->service->grant($outsider, '10', null, ScoreSourceTypeEnum::MANUAL);

        $res = $this->list();
        $res->assertOk()->assertJsonPath('code', 200);

        $items = collect($res->json('data.items'));
        $this->assertTrue($items->contains('member_id', $this->memberA->id));
        $this->assertFalse($items->contains('member_id', $this->memberB->id), '沒發過積分的會員不列');
        $this->assertFalse($items->contains('member_id', $outsider->id), '範圍外會員不列');

        $rowA = $items->firstWhere('member_id', $this->memberA->id);
        $this->assertSame($this->memberA->username, $rowA['username']);
        $this->assertSame($this->agent->username, $rowA['agent_username']);
        $this->assertSame('120.0000', $rowA['available_score']);
        $this->assertSame('30.0000', $rowA['expired_score']);
        $this->assertSame('180.0000', $rowA['granted_score']);
        // 最近異動＝最後那筆人工扣，setTestNow 12:00+2h = 14:00 UTC → +8 22:00
        $this->assertSame('2026-09-15 22:00:00', $rowA['last_changed_at']);
        // 已使用＝人工扣 30（到期失效的 30 不算）；可用 120 ＝ 累計 180 − 已使用 30 − 已過期 30
        $this->assertSame('30.0000', $rowA['used_score']);
        // 最近到期＝100 那批（14:00 發、24h → 隔天 14:00 UTC → +8 22:00），FIFO 先扣它 30 → 剩 70；無限期那批不算
        $this->assertSame('2026-09-16 22:00:00', $rowA['next_expire_at']);
        $this->assertSame('70.0000', $rowA['next_expire_score']);
    }

    /**
     * 最近到期（target/tickets#4438）：已過期但排程還沒標失效的批次不算；同一刻到期的多批剩餘加總；
     * 只有無限期的會員回 null；已使用＝系統消耗＋人工扣除，不含人工發放。
     */
    public function test_list_next_expiry_skips_past_batches_and_sums_same_moment(): void
    {
        // A：1 小時那批，時間推過到期但不跑 expireMember（模擬排程還沒掃到）；另兩批同一刻到期
        $this->service->grant($this->memberA, '40', 1, ScoreSourceTypeEnum::MANUAL);
        $this->service->grant($this->memberA, '25', 48, ScoreSourceTypeEnum::MANUAL);
        $this->service->grant($this->memberA, '15', 48, ScoreSourceTypeEnum::MANUAL);
        Carbon::setTestNow(Carbon::now()->addHours(2));

        // B：只有無限期
        $this->service->grant($this->memberB, '10', null, ScoreSourceTypeEnum::MANUAL);

        $items = collect($this->list()->assertOk()->json('data.items'));
        $rowA = $items->firstWhere('member_id', $this->memberA->id);
        // 12:00 UTC 發、48h → 9/17 12:00 UTC → +8 20:00；25 + 15 同一刻
        $this->assertSame('2026-09-17 20:00:00', $rowA['next_expire_at'], '已過期未掃的 40 那批不算');
        $this->assertSame('40.0000', $rowA['next_expire_score']);
        $this->assertSame('0.0000', $rowA['used_score'], '人工發放不算已使用');

        $rowB = $items->firstWhere('member_id', $this->memberB->id);
        $this->assertNull($rowB['next_expire_at']);
        $this->assertNull($rowB['next_expire_score']);
    }

    public function test_list_filters_by_username_and_agent_line(): void
    {
        $this->seedMemberA();
        $this->service->grant($this->memberB, '5', null, ScoreSourceTypeEnum::MANUAL);

        $byUser = $this->list(['username' => $this->memberA->username]);
        $this->assertCount(1, $byUser->json('data.items'));
        $this->assertSame($this->memberA->id, $byUser->json('data.items.0.member_id'));

        $byAgent = $this->list(['agent_username' => $this->agent->username]);
        $this->assertCount(2, $byAgent->json('data.items'), '所屬代理＝整條線，兩位會員都在');

        $unknownAgent = $this->list(['agent_username' => 'no_such_agent_' . bin2hex(random_bytes(3))]);
        $this->assertCount(0, $unknownAgent->json('data.items'));
        $this->assertSame(0, $unknownAgent->json('data.pagination.total'));
    }

    public function test_logs_return_expiry_activity_name_and_respect_time_range(): void
    {
        $this->seedMemberA();

        // 一筆活動 V2 來源的發放：建活動詳細＋語系名稱＋參加紀錄，log 的 reference 指向參加紀錄
        $detail = EventV2Detail::factory()->create(['event_v2_id' => 2, 'agent_id' => $this->agent->id]);
        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => LanguageEnum::EN->value,
            'name' => '積分測試活動',
            'image' => 'image',
            'reward_name' => '積分',
            'content' => '積分測試活動',
        ]);
        // 只要一筆參照用的參加紀錄；EventV2UserJoinObserver 會讀前台 user context（Agent 測試沒有），關掉 events 建
        $join = EventV2UserJoin::withoutEvents(fn () => EventV2UserJoin::create([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->memberA->id,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
        ]));
        $this->service->grant($this->memberA, '8', 240, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, $join->id);

        $res = $this->logs(['member_id' => $this->memberA->id]);
        $res->assertOk()->assertJsonPath('code', 200);

        $items = $res->json('data.items');
        $this->assertCount(6, $items, '30 發放、30 到期、100 發放、50 發放、30 扣除、8 活動發放');

        // 新→舊：第一筆是活動發放
        $first = $items[0];
        $this->assertSame(ScoreLogTypeEnum::REWARD->value, $first['type']);
        $this->assertSame(ScoreSourceTypeEnum::EVENT_V2_USER_JOIN->value, $first['reference_type']);
        $this->assertSame($join->id, $first['reference_id']);
        $this->assertSame('積分測試活動', $first['activity_name'], '活動名稱依參照查語系表');
        $this->assertSame('8.0000', $first['score']);
        $this->assertGreaterThan(0, $first['batch_id'], '每筆明細帶批次 id，跨批扣除才對得回發放列');
        $this->assertSame('128.0000', $first['after_score']);
        // 14:00 UTC + 240h = 09/25 14:00 UTC（已在刻度上）→ +8 22:00
        $this->assertSame('2026-09-25 22:00:00', $first['expired_at']);

        // 第二筆是人工扣除：到期日留空、活動名稱 null、操作者與備註帶到
        $second = $items[1];
        $this->assertSame(ScoreLogTypeEnum::MANUAL->value, $second['type']);
        $this->assertSame(2, $second['direction']);
        $this->assertNull($second['expired_at']);
        $this->assertNull($second['activity_name']);
        $this->assertSame('人工扣', $second['remark']);

        // 到期失效那筆：無操作者
        $expire = collect($items)->firstWhere('type', ScoreLogTypeEnum::EXPIRE->value);
        $this->assertNull($expire['operator']);
        $this->assertSame('30.0000', $expire['score']);

        // 期間篩選（+8 輸入）：只取 setTestNow 推兩小時之後的（22:00 起）→ 排除最早那筆 30 發放（20:00）
        $ranged = $this->logs([
            'member_id' => $this->memberA->id,
            'start_time' => '2026-09-15 21:00:00',
            'end_time' => '2026-09-15 23:59:59',
        ]);
        $this->assertCount(5, $ranged->json('data.items'));
    }

    public function test_logs_activity_name_prefers_viewer_language_then_english(): void
    {
        // 營運只填英文（dev 實例：活動 265／267 只有 language_id 1）：繁中後台看，名稱要退回英文而不是空白
        $onlyEnglish = $this->grantFromEventDetail([LanguageEnum::EN->value => 'English Only']);
        // 看的人的語系有填時優先用它
        $bothLangs = $this->grantFromEventDetail([
            LanguageEnum::EN->value => 'English Name',
            LanguageEnum::ZH_TW->value => '繁中名稱',
        ]);

        $res = $this->postJson(
            'agent/api/score/records/logs',
            ['page' => 1, 'per_page' => 50, 'member_id' => $this->memberA->id],
            array_merge($this->headers, ['Accept-Language' => 'zh-tw']),
        );
        $res->assertOk()->assertJsonPath('code', 200);

        $names = collect($res->json('data.items'))->pluck('activity_name', 'reference_id');
        $this->assertSame('English Only', $names[$onlyEnglish->id]);
        $this->assertSame('繁中名稱', $names[$bothLangs->id]);
    }

    public function test_logs_filter_by_batch_and_source(): void
    {
        $this->seedMemberA();

        // 人工扣除（type MANUAL × direction 2）只有一筆
        $manualDeduct = $this->logs(['member_id' => $this->memberA->id, 'type' => ScoreLogTypeEnum::MANUAL->value, 'direction' => 2]);
        $this->assertCount(1, $manualDeduct->json('data.items'));
        $this->assertSame('人工扣', $manualDeduct->json('data.items.0.remark'));

        // 到期失效一筆
        $expired = $this->logs(['member_id' => $this->memberA->id, 'type' => ScoreLogTypeEnum::EXPIRE->value]);
        $this->assertCount(1, $expired->json('data.items'));

        // 指定批次：會過期的那批（30）有發放＋到期兩筆
        $batchId = $expired->json('data.items.0.batch_id');
        $byBatch = $this->logs(['member_id' => $this->memberA->id, 'batch_id' => $batchId]);
        $this->assertCount(2, $byBatch->json('data.items'));
        $this->assertSame([$batchId, $batchId], array_column($byBatch->json('data.items'), 'batch_id'));

        // 不合法的 type 擋下
        $this->assertNotSame(200, $this->logs(['member_id' => $this->memberA->id, 'type' => 9])->json('code'));
    }

    /**
     * 建一筆活動詳細＋指定語系名稱＋參加紀錄，並以該參加紀錄為來源發 10 分給 A。
     *
     * @param  array<int, string>  $names  language_id => 活動名稱
     */
    private function grantFromEventDetail(array $names): EventV2UserJoin
    {
        $detail = EventV2Detail::factory()->create(['event_v2_id' => 2, 'agent_id' => $this->agent->id]);
        foreach ($names as $languageId => $name) {
            EventV2DetailLang::create([
                'event_v2_detail_id' => $detail->id,
                'language_id' => $languageId,
                'name' => $name,
                'image' => 'image',
                'reward_name' => '積分',
                'content' => $name,
            ]);
        }
        // EventV2UserJoinObserver 會讀前台 user context（Agent 測試沒有），關掉 events 建
        $join = EventV2UserJoin::withoutEvents(fn () => EventV2UserJoin::create([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->memberA->id,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::COMPLETE->value,
        ]));
        $this->service->grant($this->memberA, '10', null, ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, $join->id);

        return $join;
    }

    public function test_logs_reject_member_outside_scope(): void
    {
        $this->domain = 'sr' . bin2hex(random_bytes(5)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $outsider = $this->createMember($otherAgent, ['username' => 'sro' . bin2hex(random_bytes(5))]);
        $this->service->grant($outsider, '10', null, ScoreSourceTypeEnum::MANUAL);

        $res = $this->logs(['member_id' => $outsider->id]);
        $this->assertNotSame(200, $res->json('code'));
    }
}
