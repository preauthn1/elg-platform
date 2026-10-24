<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Event;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\Event\EventTypeEnum;
use App\Enums\Event\PrizeTypeEnum;
use App\Enums\LanguageEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Http\Middleware\RequestLogger;
use App\Models\Agent;
use App\Models\AgentAccessToken;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventJackpot;
use App\Models\EventName;
use App\Models\Prize;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 後台活動管理（V1）列表：後端分頁、排序白名單、代理範疇、detail 挑選（target/tickets#4320）。
 *
 * 自 API_Agent EventController@list 遷入 apis。舊接口前端一次全取 1000 筆再本地排序，
 * VWorld 啟用中活動已逾 1000 筆被截斷；本接口改正規分頁，起訖時間／滿足條件亦可由後端排序。
 *
 * php artisan test tests/Feature/Agent/Event/EventListTest.php
 */
class EventListTest extends TestCase
{
    private string $apiPath = 'agent/api/event/list';

    private Agent $agent;

    /** @var array<string, Event> 依情境命名的活動 */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        // 繞過 AES 解密與日誌中間件，保留 AuthAgent + MergeAgentData 讓 agent context 正確注入
        $this->withoutMiddleware([DecryptRequest::class, RequestLogger::class]);

        $this->agent = $this->createAgentAndDomain();
        $token = [REDACTED];
        AgentAccessToken:[REDACTED]
            'agent_id' => $this->agent->id,
            'token' => $token,
            'expire_date' => now()->addHours(2)->toDateTimeString(),
            'admin' => 1,
        ]);
        $this->addHeader('Authorization', 'Bearer ' . $token);

        // 三個一般活動：起訖時間與條件互相錯開，供排序斷言
        $this->events['early'] = $this->createRegularEvent(
            createdAt: '2026-01-01 00:00:00',
            actionId: EventActionIdEnum::DEPOSIT->value,
            point: 50,
            start: '2026-03-01 00:00:00',
            end: '2026-03-10 00:00:00',
        );
        $this->events['middle'] = $this->createRegularEvent(
            createdAt: '2026-01-02 00:00:00',
            actionId: EventActionIdEnum::DEPOSIT->value,
            point: 10,
            start: '2026-05-01 00:00:00',
            end: '2026-05-10 00:00:00',
        );
        $this->events['late'] = $this->createRegularEvent(
            createdAt: '2026-01-03 00:00:00',
            actionId: EventActionIdEnum::BET->value,
            point: 100,
            start: '2026-07-01 00:00:00',
            end: '2026-07-10 00:00:00',
        );

        // Jackpot 活動：detail 來自 event_jackpots，行為固定為 JACKPOT
        $jackpot = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'type_id' => EventTypeEnum::JACKPOT->value,
            'created_at' => '2026-01-04 00:00:00',
        ]);
        $this->createPrize($jackpot);
        EventJackpot::create([
            'event_id' => $jackpot->id,
            'type' => 1,
            'start_time' => '2026-09-01 00:00:00',
            'end_time' => '2026-09-10 00:00:00',
            'expire_date' => '2026-09-10 00:00:00',
            'expire_hour' => 0,
            'achieve_point' => 888.7,
            'member_number' => 0,
            'agent_number' => 0,
            'pay_number' => 0,
            'number_of_member_in_agent' => 0,
            'point' => 0,
            'flow_limit_multiple' => 0,
            'image' => '',
            'sort' => 0,
            'admin' => 0,
        ]);
        $this->events['jackpot'] = $jackpot;

        // 無獎勵設定的活動：與舊接口一致（INNER JOIN prizes）不列出
        Event::factory()->create(['agent_id' => $this->agent->id]);

        // 別的代理體系的活動：不在本代理 hierarchy 之下，不可見
        $originalDomain = $this->domain;
        $this->domain = Str::random(12) . '.test.local';
        $other = $this->createAgentAndDomain();
        $this->domain = $originalDomain;
        $this->events['other_agent'] = $this->createRegularEvent(
            createdAt: '2026-01-05 00:00:00',
            actionId: EventActionIdEnum::DEPOSIT->value,
            point: 1,
            start: '2026-02-01 00:00:00',
            end: '2026-02-10 00:00:00',
            agent: $other,
        );
    }

    private function createRegularEvent(
        string $createdAt,
        int $actionId,
        int $point,
        string $start,
        string $end,
        ?Agent $agent = null,
    ): Event {
        $event = Event::factory()->create([
            'agent_id' => ($agent ?? $this->agent)->id,
            'type_id' => EventTypeEnum::REGULAR->value,
            'created_at' => $createdAt,
        ]);
        $this->createPrize($event);
        EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => $actionId,
            'point' => $point,
            'start_time' => $start,
            'end_time' => $end,
            'status' => 1,
        ]);

        return $event;
    }

    private function createPrize(Event $event): Prize
    {
        return Prize::create([
            'event_id' => $event->id,
            'type_id' => PrizeTypeEnum::DIRECT->value,
            'name' => '',
            'background' => '',
            'number' => 1,
            'is_repeatedly' => 0,
            'flow_limit_multiple' => 0,
            'max_point' => 0,
            'withdrawal_cancel_reward' => 0,
            'cancel_other_reward' => 0,
            'info' => '',
            'expire_date' => '2026-12-31 15:59:59',
            'draw_date' => '2026-12-31 15:59:59',
            'status' => 1,
            'admin' => 0,
        ]);
    }

    /** @param  array<string, mixed>  $params */
    private function list(array $params = []): TestResponse
    {
        // 陣列 union 以左側為準，參數要放左邊才能覆寫預設
        return $this->requestApi($this->apiPath, $params + ['page' => 1, 'per_page' => 20]);
    }

    /** @return list<int> */
    private function ids(TestResponse $response): array
    {
        return array_map('intval', array_column($response->json('data.items'), 'id'));
    }

    public function test_list_is_scoped_to_agent_hierarchy_and_paginated(): void
    {
        $response = $this->list(['per_page' => 2]);

        $response->assertJson(['code' => 200]);
        $this->assertSame(4, $response->json('data.pagination.total'), '本代理 3 一般 + 1 Jackpot；無獎勵者與他人體系者不計');
        $this->assertCount(2, $response->json('data.items'));
        // 預設排序：建立時間新到舊
        $this->assertSame(
            [$this->events['jackpot']->id, $this->events['late']->id],
            $this->ids($response),
        );
    }

    public function test_sort_by_start_time_desc_uses_detail_and_jackpot_times(): void
    {
        $response = $this->list(['sort_field' => 'start_time', 'sort_direction' => 'desc']);

        $this->assertSame(
            [
                $this->events['jackpot']->id,   // 2026-09-01（event_jackpots）
                $this->events['late']->id,      // 2026-07-01
                $this->events['middle']->id,    // 2026-05-01
                $this->events['early']->id,     // 2026-03-01
            ],
            $this->ids($response),
        );
    }

    public function test_sort_by_end_time_asc(): void
    {
        $response = $this->list(['sort_field' => 'end_time', 'sort_direction' => 'asc']);

        $this->assertSame(
            [
                $this->events['early']->id,
                $this->events['middle']->id,
                $this->events['late']->id,
                $this->events['jackpot']->id,
            ],
            $this->ids($response),
        );
    }

    public function test_sort_by_condition_orders_by_action_then_point(): void
    {
        $response = $this->list(['sort_field' => 'condition', 'sort_direction' => 'asc']);

        // DEPOSIT(1) point 10 → DEPOSIT(1) point 50 → BET(2) → JACKPOT(10)
        $this->assertSame(
            [
                $this->events['middle']->id,
                $this->events['early']->id,
                $this->events['late']->id,
                $this->events['jackpot']->id,
            ],
            $this->ids($response),
        );
    }

    public function test_detail_shape_matches_legacy_contract(): void
    {
        $response = $this->list(['sort_field' => 'id', 'sort_direction' => 'asc']);
        $items = collect($response->json('data.items'))->keyBy('id');

        $regular = $items->get($this->events['early']->id);
        $this->assertSame($this->agent->username, $regular['agent_username']);
        $this->assertCount(1, $regular['detail']);
        $this->assertSame(EventActionIdEnum::DEPOSIT->value, $regular['detail'][0]['action_id']);
        $this->assertEquals(50, $regular['detail'][0]['point']);
        $this->assertSame(
            Carbon::parse('2026-03-01 00:00:00')->toBackendTime()->toDateTimeString(),
            $regular['detail'][0]['start_time'],
            'detail 時間須轉後台時區（舊接口寫死 +8 小時）',
        );

        $jackpot = $items->get($this->events['jackpot']->id);
        $this->assertCount(1, $jackpot['detail']);
        $this->assertSame(EventActionIdEnum::JACKPOT->value, $jackpot['detail'][0]['action_id']);
        $this->assertSame(888, $jackpot['detail'][0]['point'], 'Jackpot 門檻取 floor(achieve_point)');
    }

    public function test_filters_by_status_title_and_username(): void
    {
        $this->events['early']->update(['status' => 0, 'title' => 'ZZZ-only-me']);

        $this->assertSame(3, $this->list(['status' => 1])->json('data.pagination.total'));
        $this->assertSame([$this->events['early']->id], $this->ids($this->list(['status' => 0])));
        $this->assertSame([$this->events['early']->id], $this->ids($this->list(['title' => 'only-me'])));
        $this->assertSame(0, $this->list(['username' => 'no_such_agent'])->json('data.pagination.total'));

        // 當前語系（未帶語系標頭預設 EN）的翻譯名稱也要被 like 到，且列表顯示翻譯名稱
        EventName::create([
            'event_id' => $this->events['late']->id,
            'language_id' => LanguageEnum::EN->value,
            'title' => 'Translated-Late-Name',
            'content' => '',
            'admin' => 0,
        ]);
        $response = $this->list(['title' => 'translated-late']);
        $this->assertSame([$this->events['late']->id], $this->ids($response));
        $this->assertSame('Translated-Late-Name', $response->json('data.items.0.title'));
    }

    /**
     * id 精確比對（target/tickets#2983：「帶入既有活動設定」下拉以 ID 搜尋）；
     * 疊加在 hierarchy_path 範疇限制之上，不能用 id 查到別代理體系的活動。
     */
    public function test_filters_by_id_and_cannot_escape_hierarchy_scope(): void
    {
        $this->assertSame(
            [$this->events['early']->id],
            $this->ids($this->list(['id' => $this->events['early']->id])),
        );
        $this->assertSame(
            0,
            $this->list(['id' => $this->events['other_agent']->id])->json('data.pagination.total'),
            '別代理體系的活動即使帶對 id 也不可見',
        );
    }

    public function test_rejects_unknown_sort_field_and_oversized_page(): void
    {
        $this->list(['sort_field' => 'created_at'])->assertStatus(400);
        $this->list(['per_page' => 1000])->assertStatus(400);
    }
}
