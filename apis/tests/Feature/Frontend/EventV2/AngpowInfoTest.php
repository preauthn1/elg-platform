<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2PeriodEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\StatusEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\Member;
use App\Services\MemberScoreService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 紅包前台畫面資訊 event-v2/angpow/info 與 join 回抽到的金額（target/tickets#4234）。
 *
 * 必須成立：
 * 1. info 把每個限制的「上限／已用／剩餘」拆開回，沒設的回 null；抽一次之後數字跟著動（個人、全站、積分、間隔倒數）。
 * 2. time_window 以前台時區回給前端（DB 存 UTC）。
 * 3. 非紅包活動打 info 回 400 not_angpow；join 回 reward 與 join_withdraw；event/list 多回 handler_class。
 *
 * php artisan test tests/Feature/Frontend/EventV2/AngpowInfoTest.php
 */
class AngpowInfoTest extends TestCase
{
    private const string INFO = 'frontend/api/event-v2/angpow/info';

    private const string JOIN = 'frontend/api/event-v2/join';

    private const string LIST = 'frontend/api/event/list';

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        // 同 ListTest：語系走 seeder，活動語系只建 id 1（英文），info／list 找不到會員語系都會退回它
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->seed(LanguageSeeder::class);
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent, ['mobile_validate' => 1, 'mobile' => '0912345678']);
    }

    /** 只回畫面要的欄位（Max 2026-10-01 收斂），多一個少一個都算壞：前端照這份接 */
    private const array FIELDS = [
        'is_open', 'score_cost', 'score_balance',
        'member_times_limit', 'member_times_remaining',
        'site_times_limit', 'site_times_remaining',
        'site_amount_limit', 'site_amount_remaining',
        'interval', 'next_available_in', 'time_window', 'in_time_window',
    ];

    public function test_info_returns_only_screen_fields_and_moves_after_a_draw(): void
    {
        config(['custom.frontend.timezone' => 'Asia/Taipei']);
        // 可抽時段只到台灣 23:00，時間固定在台灣中午，才不會因為跑測試的時刻落在時段外而判成未開啟
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'Asia/Taipei')->setTimezone('UTC'));
        app(MemberScoreService::class)->grant($this->member, '35', null, ScoreSourceTypeEnum::MANUAL);

        $detail = $this->createDetail(
            ['condition_config' => [
                'score' => 10,
                'max_join' => 5,
                'max_amount' => 100,
                'member_times' => 3,
                'member_amount' => 50,
                'interval' => 300,
                'time_window' => ['00:00', '15:00'], // UTC，＝台灣 08:00～23:00
            ]],
            ['member_period' => EventV2PeriodEnum::DAILY->value, 'total_period' => EventV2PeriodEnum::WEEKLY->value],
        );

        $before = $this->info($detail)->assertOk()->assertJsonPath('code', 200)->json('data');
        $this->assertSame(self::FIELDS, array_keys($before), '欄位集合要跟給前端的清單一模一樣');
        $this->assertTrue($before['is_open']);
        $this->assertSame('10.0000', $before['score_cost']);
        $this->assertSame('35.0000', $before['score_balance']);
        $this->assertSame([3, 3], [$before['member_times_limit'], $before['member_times_remaining']]);
        $this->assertSame([5, 5], [$before['site_times_limit'], $before['site_times_remaining']]);
        $this->assertSame(['100.0000', '100.0000'], [$before['site_amount_limit'], $before['site_amount_remaining']]);
        $this->assertSame(300, $before['interval'], '前端拿它決定要不要做間隔處理');
        $this->assertSame(0, $before['next_available_in'], '沒抽過不用等');
        $this->assertSame(['08:00', '23:00'], $before['time_window'], 'UTC 00:00～15:00 轉回台灣時間');
        $this->assertTrue($before['in_time_window']);

        // 抽一次（每包 20、扣 10 分）：積分、個人剩餘、全站剩餘都要動
        $this->join($detail)->assertOk()->assertJsonPath('code', 200)
            ->assertJsonPath('data.reward', '20.0000')
            ->assertJsonPath('data.join_withdraw', true);

        $after = $this->info($detail)->assertOk()->json('data');
        $this->assertSame('25.0000', $after['score_balance']);
        $this->assertSame(2, $after['member_times_remaining']);
        $this->assertSame(4, $after['site_times_remaining']);
        $this->assertSame('80.0000', $after['site_amount_remaining']);
        $this->assertGreaterThan(0, $after['next_available_in'], '剛抽完，間隔倒數中');
        $this->assertLessThanOrEqual(300, $after['next_available_in']);
    }

    public function test_info_returns_null_for_limits_not_set_and_zero_floor_for_remaining(): void
    {
        app(MemberScoreService::class)->grant($this->member, '100', null, ScoreSourceTypeEnum::MANUAL);
        // 每包 20、總金額 30：抽兩次已發 40 > 30，剩餘顯示 0 不顯示負數
        $detail = $this->createDetail(['condition_config' => ['score' => 10, 'max_amount' => 30]]);
        $this->join($detail)->assertOk();
        $this->join($detail)->assertOk();

        $data = $this->info($detail)->assertOk()->json('data');
        $this->assertNull($data['member_times_limit']);
        $this->assertNull($data['member_times_remaining']);
        $this->assertNull($data['site_times_limit']);
        $this->assertNull($data['site_times_remaining']);
        $this->assertNull($data['interval'], '沒設＝不限');
        $this->assertSame(0, $data['next_available_in'], 'interval 沒設一律 0');
        $this->assertNull($data['time_window'], '沒設＝全天');
        $this->assertTrue($data['in_time_window']);
        $this->assertSame('0.0000', $data['site_amount_remaining'], '超發後剩餘顯示 0');
    }

    /** is_open＝活動期間內且在可抽時段內（Max 定義），停用也算未開啟；未開啟時數字照回，前端只是不展示 */
    public function test_is_open_false_when_disabled_or_outside_period_or_outside_time_window(): void
    {
        $disabled = $this->createDetail([], ['status' => StatusEnum::DISABLE->value]);
        // 可抽時段設成「5 分鐘後起、2 分鐘長」，現在一定不在時段內（UTC 存）
        $outsideWindow = $this->createDetail(['condition_config' => ['score' => 10, 'time_window' => [now()->addMinutes(5)->format('H:i'), now()->addMinutes(7)->format('H:i')]]]);
        $ended = $this->createDetail([], ['start_time' => now()->subDays(10)->toDateTimeString(), 'end_time' => now()->subDay()->toDateTimeString()]);
        $notStarted = $this->createDetail([], ['start_time' => now()->addDay()->toDateTimeString(), 'end_time' => now()->addDays(10)->toDateTimeString()]);
        $open = $this->createDetail();

        foreach ([$disabled, $ended, $notStarted, $outsideWindow] as $detail) {
            $data = $this->info($detail)->assertOk()->json('data');
            $this->assertFalse($data['is_open'], "detail {$detail->id} 不該是開啟中");
            $this->assertSame('10.0000', $data['score_cost'], '關閉時數字照回');
        }
        $this->assertTrue($this->info($open)->assertOk()->json('data.is_open'));
    }

    public function test_info_rejects_non_angpow_event(): void
    {
        $dailyFree = $this->createDetail(['condition_config' => null], [], 'DailyFreeService', '每日登入');

        $this->info($dailyFree)
            ->assertStatus(400)
            ->assertJsonPath('message', __('event_v2.validation.not_angpow'));
    }

    public function test_event_list_marks_handler_class(): void
    {
        $detail = $this->createDetail();

        $items = $this->requestApi(self::LIST, ['domain' => $this->domain])->assertOk()->json('data');
        $row = collect($items)->firstWhere('event_v2_id', $detail->id);
        $this->assertNotNull($row, '紅包活動要出現在列表');
        $this->assertSame('AngpowService', $row['handler_class']);
    }

    // -------------------------------------------------------

    private function createDetail(array $overrides = [], array $columns = [], string $handler = 'AngpowService', string $typeName = '紅包'): EventV2Detail
    {
        $typeId = EventV2::query()->firstOrCreate(['handler_class' => $handler], ['name' => $typeName, 'schedule' => null])->id;

        $detail = EventV2Detail::factory()->create(array_merge([
            'event_v2_id' => $typeId,
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => ['score' => 10],
            'reward_formula' => '20',
            'reward_config' => null,
            'balance_formula' => null,
            'flow_formula' => null,
            'withdraw_formula' => null,
            'withdraw_config' => null,
            'withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value,
            'member_period' => EventV2PeriodEnum::NONE->value,
            'total_period' => EventV2PeriodEnum::NONE->value,
            'join_withdraw' => 1,
            'is_recycle' => 1,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ], $overrides, $columns));

        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => 1,
            'name' => '紅包測試活動',
            'image' => 'image',
            'reward_name' => '紅包',
            'content' => '紅包測試活動',
        ]);

        return $detail;
    }

    private function info(EventV2Detail $detail): TestResponse
    {
        return $this->requestApi(self::INFO, ['event_detail_id' => $detail->id]);
    }

    private function join(EventV2Detail $detail): TestResponse
    {
        return $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id]);
    }
}
