<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2PeriodEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Language;
use App\Models\Member;
use App\Models\MemberScoreLog;
use App\Models\MemberWallet;
use App\Services\MemberScoreService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 紅包參加（AngpowService，target/tickets#4234）：花積分抽一次亂數現金。
 *
 * 必須成立：
 * 1. 參加當下扣門票積分（deduct，參照參加紀錄 id）；勾參加即領獎則獎金直接進主錢包、紀錄 COMPLETE，可再抽。
 * 2. 積分不足、未設 score、不在時段、間隔未到、每人次數／金額、全站總金額各自擋下並回對應驗證碼，不留紀錄、不扣分。
 * 3. 總金額「到了才擋」：未達上限可抽且照抽到的給，最後一包可讓總額超過。
 * 4. 上限只排除後台操作放棄（OPERATOR_QUIT）；會員自己放棄照算，積分不退。
 * 5. 不勾參加即領獎：獎金進活動錢包、紀錄停在 JOIN、主錢包不動——就是主錢包型的標準流程。
 *
 * php artisan test tests/Feature/Frontend/EventV2/AngpowJoinTest.php
 */
class AngpowJoinTest extends TestCase
{
    private const string JOIN = 'frontend/api/event-v2/join';

    private const string REWARD = '20';

    private Agent $agent;

    private Member $member;

    private Language $language;

    private MemberScoreService $score;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMemberAndToken($this->agent, ['mobile_validate' => 1, 'mobile' => '0912345678']);
        $this->language = Language::create(['name' => '繁體中文', 'code' => 'zh-TW', 'admin' => 0]);
        $this->score = app(MemberScoreService::class);
    }

    // -------------------------------------------------------
    // 勾參加即領獎：扣積分、獎金入主錢包、可重複抽
    // -------------------------------------------------------

    /** 積分只收 2 位小數：score 填 10.559 扣 10.55（bcmath 無條件捨去），不會在扣分那步丟例外 */
    public function test_score_beyond_two_decimals_is_truncated_when_deducting(): void
    {
        $detail = $this->createDetail(['condition_config' => ['score' => 10.559]]);
        $this->grantScore('100');

        $this->join($detail)->assertOk()->assertJsonPath('code', 200);
        $this->assertSame('89.4500', $this->scoreBalance(), '扣 10.55 不是 10.56');
    }

    public function test_join_deducts_score_and_pays_reward_to_main_wallet(): void
    {
        $detail = $this->createDetail();
        $this->grantScore('100');
        $mainBefore = $this->mainBalance();

        $this->join($detail)->assertOk()->assertJsonPath('code', 200);

        $join = EventV2UserJoin::query()->where('member_id', $this->member->id)->sole();
        $this->assertSame(EventV2UserJoinStatusEnum::COMPLETE->value, $join->status, '勾參加即領獎：紀錄直接完成');
        $this->assertSame(Common::numAdd($mainBefore, self::REWARD, 4), $this->mainBalance(), '獎金直接進主錢包');
        $this->assertSame('90.0000', $this->scoreBalance(), '門票 10 分已扣');

        $consume = MemberScoreLog::query()
            ->where('member_id', $this->member->id)
            ->where('type', ScoreLogTypeEnum::CONSUME->value)
            ->where('reference_type', ScoreSourceTypeEnum::EVENT_V2_USER_JOIN->value)
            ->where('reference_id', $join->id)
            ->count();
        $this->assertSame(1, $consume, '扣分紀錄參照參加紀錄 id');

        // 可以再抽
        $this->join($detail)->assertOk()->assertJsonPath('code', 200);
        $this->assertSame(2, EventV2UserJoin::query()->where('member_id', $this->member->id)->count());
        $this->assertSame('80.0000', $this->scoreBalance());
    }

    public function test_join_blocked_when_score_insufficient(): void
    {
        $detail = $this->createDetail();
        $this->grantScore('5');

        $this->assertBlocked($this->join($detail), EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_ENOUGH);
        $this->assertSame(0, EventV2UserJoin::query()->where('member_id', $this->member->id)->count());
        $this->assertSame('5.0000', $this->scoreBalance(), '沒扣分');
    }

    public function test_join_blocked_when_score_not_configured(): void
    {
        $detail = $this->createDetail(['condition_config' => ['max_join' => 10]]);
        $this->grantScore('100');

        $this->assertBlocked($this->join($detail), EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_SET);
    }

    public function test_time_window_blocks_outside_and_allows_inside(): void
    {
        $this->grantScore('100');
        // 時段存系統時間、以系統時間判斷：前台時區設成 +8 也不能影響判斷（測試環境預設 UTC 會看不出差別）
        config(['custom.frontend.timezone' => 'Asia/Taipei']);
        $now = now();

        $outside = $this->createDetail(['condition_config' => [
            'score' => 10,
            'time_window' => [$now->copy()->addHours(2)->format('H:i'), $now->copy()->addHours(3)->format('H:i')],
        ]]);
        $this->assertBlocked($this->join($outside), EventV2ValidateCodeEnum::ANGPOW_OUT_OF_TIME_WINDOW);

        $inside = $this->createDetail(['condition_config' => [
            'score' => 10,
            'time_window' => [$now->copy()->subHour()->format('H:i'), $now->copy()->addHour()->format('H:i')],
        ]]);
        $this->join($inside)->assertOk()->assertJsonPath('code', 200);
    }

    public function test_interval_blocks_second_claim_too_soon(): void
    {
        $detail = $this->createDetail(['condition_config' => ['score' => 10, 'interval' => 300]]);
        $this->grantScore('100');

        $this->join($detail)->assertOk()->assertJsonPath('code', 200);
        $this->assertBlocked($this->join($detail), EventV2ValidateCodeEnum::ANGPOW_INTERVAL_NOT_REACHED);
        $this->assertSame('90.0000', $this->scoreBalance(), '被擋的那次沒扣分');
    }

    public function test_member_times_limit_within_period(): void
    {
        $detail = $this->createDetail(
            ['condition_config' => ['score' => 10, 'member_times' => 2]],
            ['member_period' => EventV2PeriodEnum::DAILY->value],
        );
        $this->grantScore('100');

        $this->join($detail)->assertOk()->assertJsonPath('code', 200);
        $this->join($detail)->assertOk()->assertJsonPath('code', 200);
        $this->assertBlocked($this->join($detail), EventV2ValidateCodeEnum::ANGPOW_MEMBER_TIMES_REACHED);
    }

    public function test_member_amount_limit_blocks_once_reached(): void
    {
        // 每包 20：0 → 可抽；20 < 30 → 可抽；40 ≥ 30 → 擋
        $detail = $this->createDetail(['condition_config' => ['score' => 10, 'member_amount' => 30]]);
        $this->grantScore('100');

        $this->join($detail)->assertOk()->assertJsonPath('code', 200);
        $this->join($detail)->assertOk()->assertJsonPath('code', 200);
        $this->assertBlocked($this->join($detail), EventV2ValidateCodeEnum::ANGPOW_MEMBER_AMOUNT_REACHED);
    }

    public function test_max_amount_blocks_only_after_reached_and_last_packet_pays_in_full(): void
    {
        $detail = $this->createDetail(['condition_config' => ['score' => 10, 'max_amount' => 30]]);
        $this->grantScore('100');
        $mainBefore = $this->mainBalance();

        $this->join($detail)->assertOk()->assertJsonPath('code', 200); // 已發 20 < 30
        $this->join($detail)->assertOk()->assertJsonPath('code', 200); // 抽之前已發 20 < 30，照給 20 → 40
        $this->assertSame(Common::numAdd($mainBefore, '40', 4), $this->mainBalance(), '最後一包照抽到的給，總額超過上限');
        $this->assertBlocked($this->join($detail), EventV2ValidateCodeEnum::ANGPOW_MAX_AMOUNT_REACHED);
    }

    /**
     * 全站上限跟「包括期間」total_period 走（Frank 2026-09-30）：上一期發掉的不算這一期。
     * 期依前台時區 00:00 切，這裡把前台時區設成 +8，讓「上一期」不會剛好跟 UTC 換日重疊。
     */
    public function test_max_join_and_max_amount_reset_by_total_period(): void
    {
        config(['custom.frontend.timezone' => 'Asia/Taipei']);
        $lastPeriod = now()->toFrontendTime()->startOfDay()->subMinute()->toSystemTime();
        $this->grantScore('100');

        // 每日一池：昨天已發 2 筆／40 元，今天不算 → 今天還能抽
        $daily = $this->createDetail(
            ['condition_config' => ['score' => 10, 'max_join' => 2, 'max_amount' => 30]],
            ['total_period' => EventV2PeriodEnum::DAILY->value],
        );
        $this->createJoinRecord($daily, EventV2UserJoinStatusEnum::COMPLETE, $lastPeriod);
        $this->createJoinRecord($daily, EventV2UserJoinStatusEnum::COMPLETE, $lastPeriod);
        $this->join($daily)->assertOk()->assertJsonPath('code', 200);

        // 不分週期：整檔活動累計，昨天那 2 筆照算 → 擋 max_join
        $whole = $this->createDetail(['condition_config' => ['score' => 10, 'max_join' => 2]]);
        $this->createJoinRecord($whole, EventV2UserJoinStatusEnum::COMPLETE, $lastPeriod);
        $this->createJoinRecord($whole, EventV2UserJoinStatusEnum::COMPLETE, $lastPeriod);
        $this->assertBlocked($this->join($whole), EventV2ValidateCodeEnum::MAX_JOIN_REACHED);

        // 不分週期的 max_amount 同理：昨天已發 40 ≥ 30 → 擋
        $wholeAmount = $this->createDetail(['condition_config' => ['score' => 10, 'max_amount' => 30]]);
        $this->createJoinRecord($wholeAmount, EventV2UserJoinStatusEnum::COMPLETE, $lastPeriod);
        $this->createJoinRecord($wholeAmount, EventV2UserJoinStatusEnum::COMPLETE, $lastPeriod);
        $this->assertBlocked($this->join($wholeAmount), EventV2ValidateCodeEnum::ANGPOW_MAX_AMOUNT_REACHED);
    }

    public function test_member_period_and_total_period_are_independent(): void
    {
        // 每日可重複參加 1 次、包括期間每週：昨天抽過（會員週期已過）今天還能抽；全站這週已 2 筆（含昨天）→ 擋
        config(['custom.frontend.timezone' => 'Asia/Taipei']);
        // 「昨天」必須仍在這週內：週一沒有這種昨天，所以把時間固定在週三台灣中午，不隨跑測試的星期幾變（2026-10-05 週一曾因此紅）
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00', 'Asia/Taipei')->setTimezone('UTC'));

        try {
            $yesterday = Carbon::now()->toFrontendTime()->startOfDay()->subMinute(); // 週二 23:59
            $this->grantScore('100');

            $detail = $this->createDetail(
                ['condition_config' => ['score' => 10, 'member_times' => 1, 'max_join' => 2]],
                ['member_period' => EventV2PeriodEnum::DAILY->value, 'total_period' => EventV2PeriodEnum::WEEKLY->value],
            );
            $this->createJoinRecord($detail, EventV2UserJoinStatusEnum::COMPLETE, $yesterday->copy()->toSystemTime());
            $this->join($detail)->assertOk()->assertJsonPath('code', 200);
            $this->assertBlocked($this->join($detail), EventV2ValidateCodeEnum::MAX_JOIN_REACHED);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_operator_quit_not_counted_but_member_quit_counted(): void
    {
        $detail = $this->createDetail(['condition_config' => ['score' => 10, 'member_times' => 1]]);
        $this->grantScore('100');

        $this->createJoinRecord($detail, EventV2UserJoinStatusEnum::OPERATOR_QUIT);
        $this->join($detail)->assertOk()->assertJsonPath('code', 200);

        $other = $this->createDetail(['condition_config' => ['score' => 10, 'member_times' => 1]]);
        $this->createJoinRecord($other, EventV2UserJoinStatusEnum::QUIT);
        $this->assertBlocked($this->join($other), EventV2ValidateCodeEnum::ANGPOW_MEMBER_TIMES_REACHED);
    }

    // -------------------------------------------------------
    // 不勾參加即領獎：標準主錢包型流程
    // -------------------------------------------------------

    public function test_without_claim_on_join_reward_goes_to_event_wallet_and_stays_join(): void
    {
        $detail = $this->createDetail([], ['join_withdraw' => 0]);
        $this->grantScore('100');
        $mainBefore = $this->mainBalance();

        $this->join($detail)->assertOk()->assertJsonPath('code', 200);

        $join = EventV2UserJoin::query()->where('member_id', $this->member->id)->sole();
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, $join->status);
        $this->assertSame($mainBefore, $this->mainBalance(), '主錢包不動');
        $this->assertSame(Common::numAdd('0', self::REWARD, 4), $this->eventWalletBalance(), '獎金進活動錢包');
        $this->assertSame('90.0000', $this->scoreBalance(), '門票照扣');
    }

    // -------------------------------------------------------

    /** 測試庫的 event_v2 類型 id 與 dev／正式不同，一律以 handler_class 反查，沒有就補一列 */
    private function eventV2Id(): int
    {
        return EventV2::query()->firstOrCreate(
            ['handler_class' => 'AngpowService'],
            ['name' => '紅包', 'schedule' => null],
        )->id;
    }

    /**
     * 預設：勾參加即領獎、每包固定 20、消耗 10 分、不分週期。
     *
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $columns  非 JSON 的欄位（member_period／total_period／join_withdraw…）
     */
    private function createDetail(array $overrides = [], array $columns = []): EventV2Detail
    {
        $detail = EventV2Detail::factory()->create(array_merge([
            'event_v2_id' => $this->eventV2Id(),
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => ['score' => 10],
            'reward_formula' => self::REWARD,
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
            'language_id' => $this->language->id,
            'name' => '紅包測試活動',
            'image' => 'image',
            'reward_name' => '紅包',
            'content' => '紅包測試活動',
        ]);

        return $detail;
    }

    /**
     * 直接塞一筆歷史參加紀錄（不走 join），給上限計算用；Observer 讀前台 user context，關掉 events 建。
     * created_at 在 Model 的 $guarded 裡，create() 會丟掉，要指定時間就建好再 forceFill 回填。
     */
    private function createJoinRecord(EventV2Detail $detail, EventV2UserJoinStatusEnum $status, ?Carbon $createdAt = null): EventV2UserJoin
    {
        return EventV2UserJoin::withoutEvents(function () use ($detail, $status, $createdAt) {
            $join = EventV2UserJoin::create([
                'event_v2_detail_id' => $detail->id,
                'member_id' => $this->member->id,
                'reward' => self::REWARD,
                'withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value,
                'status' => $status->value,
            ]);
            if ($createdAt !== null) {
                $join->forceFill(['created_at' => $createdAt->toDateTimeString()])->save();
            }

            return $join;
        });
    }

    private function grantScore(string $score): void
    {
        $this->score->grant($this->member, $score, null, ScoreSourceTypeEnum::MANUAL);
    }

    private function scoreBalance(): string
    {
        return Common::numAdd('0', $this->score->balance($this->member->id), 4);
    }

    private function mainBalance(): string
    {
        return Common::numAdd('0', (string) $this->member->fresh()->balance, 4);
    }

    private function eventWalletBalance(): string
    {
        $balance = MemberWallet::query()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_EVENT_WALLET->value)
            ->value('balance');

        return Common::numAdd('0', (string) ($balance ?? '0'), 4);
    }

    private function join(EventV2Detail $detail): TestResponse
    {
        return $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id]);
    }

    private function assertBlocked(TestResponse $response, EventV2ValidateCodeEnum $code): void
    {
        $this->assertNotSame(200, $response->json('code'), '應被擋下');
        $this->assertSame($code->translate(), $response->json('message'));
    }
}
