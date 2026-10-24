<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\EventV2;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2Recycle;
use App\Models\EventV2UserJoin;
use App\Models\EventV2WalletTransactionLog;
use App\Models\Language;
use App\Models\Member;
use App\Models\MemberScoreBatch;
use App\Models\MemberWallet;
use App\Models\Order;
use App\Models\SummaryEventRecord;
use App\Models\TransactionLog;
use App\Observers\EventV2UserJoinObserver;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * 活動 V2 領取去向＝積分（withdraw_type = 2，target/tickets#4231）。
 *
 * 積分與活動錢包無關：join() 直接依「提款限制公式」算出積分數量（把提款限制公式／配置當成該類型的
 * 獎勵公式／配置來算，deposit／rand／limit 全沿用）、經 MemberScoreService::grant() 發成一批、紀錄即 COMPLETE。
 * 不算三道門、不扣主錢包、不進活動錢包、不呼叫 withdraw()／initEventV2()、不記 SR11 成本。
 *
 * 另放兩條主錢包型錨點鎖住既有路徑，一條「凍結」測試證明運營中途改活動去向不回溯已參加者。
 *
 * php artisan test tests/Feature/Frontend/EventV2/ScoreJoinTest.php
 */
class ScoreJoinTest extends TestCase
{
    private const string JOIN = 'frontend/api/event-v2/join';

    private const string WITHDRAW = 'frontend/api/event-v2/withdraw';

    private Agent $agent;

    private Member $member;

    private Language $language;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->withoutMiddleware(DecryptRequest::class);

        $this->agent = $this->createAgentAndDomain();
        // withdraw 端點在代理開「洗分需電話認證」時會擋未綁手機的會員，一律先綁好
        $this->member = $this->createMemberAndToken($this->agent, ['mobile_validate' => 1, 'mobile' => '0912345678']);
        $this->language = Language::create(['name' => '繁體中文', 'code' => 'zh-TW', 'admin' => 0]);
    }

    /** 測試庫的 event_v2 類型 id 與 dev／正式不同，一律以 handler_class 反查，沒有就補一列 */
    private function eventV2Id(string $handlerClass, string $name): int
    {
        return EventV2::query()->firstOrCreate(
            ['handler_class' => $handlerClass],
            ['name' => $name, 'schedule' => null],
        )->id;
    }

    /**
     * 預設：每日登入（DailyFreeService，無儲值條件）、積分型、積分數量 10、有效期 720 小時。
     * 積分型的獎勵公式／配置一律空（後台會擋）。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createDetail(array $overrides = []): EventV2Detail
    {
        $detail = EventV2Detail::factory()->create(array_merge([
            'event_v2_id' => $this->eventV2Id('DailyFreeService', '每日登入'),
            'agent_id' => $this->agent->id,
            'condition_formula' => null,
            'condition_config' => null,
            'reward_formula' => null,
            'reward_config' => null,
            'balance_formula' => null,
            'flow_formula' => null,
            'withdraw_formula' => '10',
            'withdraw_config' => ['expire' => 720],
            'withdraw_type' => EventV2WithdrawTypeEnum::SCORE->value,
            'join_withdraw' => 1,
            'is_recycle' => 1,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ], $overrides));

        EventV2DetailLang::create([
            'event_v2_detail_id' => $detail->id,
            'language_id' => $this->language->id,
            'name' => '積分測試活動',
            'image' => 'image',
            'reward_name' => '積分',
            'content' => '積分測試活動',
        ]);

        return $detail;
    }

    /**
     * 主錢包型（既有行為）：獎勵公式 19、參加即領獎。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createMainWalletDetail(array $overrides = []): EventV2Detail
    {
        return $this->createDetail(array_merge([
            'withdraw_type' => EventV2WithdrawTypeEnum::MAIN_WALLET->value,
            'reward_formula' => '19',
            'withdraw_formula' => null,
            'withdraw_config' => null,
        ], $overrides));
    }

    private function createFinishedDeposit(float $amount): Order
    {
        return Order::factory()->create([
            'member_id' => $this->member->id,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => $amount,
            'pay_time' => now(),
            'order_type_id' => 1,
            'admin' => 0,
        ]);
    }

    private function soleJoin(): EventV2UserJoin
    {
        return EventV2UserJoin::query()->where('member_id', $this->member->id)->sole();
    }

    private function num(mixed $value): string
    {
        return Common::numAdd('0', (string) ($value ?? '0'), 4);
    }

    private function walletBalance(WalletEnum $type): string
    {
        return $this->num(MemberWallet::query()
            ->where('member_id', $this->member->id)
            ->where('wallet_type_id', $type->value)
            ->value('balance'));
    }

    private function eventWalletLogCount(int $joinId): int
    {
        return EventV2WalletTransactionLog::query()
            ->join('wallet_transaction_logs', 'wallet_transaction_logs.id', '=', 'event_v2_wallet_transaction_logs.wallet_transaction_log_id')
            ->where('event_v2_wallet_transaction_logs.event_v2_user_join_id', $joinId)
            ->whereIn('wallet_transaction_logs.type', [WalletTransactionEnum::EVENT_WALLET_JOIN->value, WalletTransactionEnum::EVENT_WALLET_INIT->value])
            ->count();
    }

    private function transactionLogCount(TransactionEnum $type): int
    {
        return TransactionLog::query()->where('member_id', $this->member->id)->where('type', $type->value)->count();
    }

    private function scoreBatchCount(): int
    {
        return MemberScoreBatch::query()->where('member_id', $this->member->id)->count();
    }

    private function sr11CostCount(): int
    {
        return SummaryEventRecord::query()
            ->where('event_id', EventV2UserJoinObserver::COST_ID)
            ->where('member_id', $this->member->id)
            ->count();
    }

    /** 到期時間必須落在「發放＋N 小時」到「再進位一格 10 分鐘」之間 */
    private function assertExpiresAboutHoursLater(MemberScoreBatch $batch, int $hours): void
    {
        $this->assertNotNull($batch->expired_at);
        $floor = now()->addHours($hours)->subSecond();
        $ceil = now()->addHours($hours)->addMinutes(10)->addSecond();
        $this->assertTrue(
            $batch->expired_at->between($floor, $ceil),
            "expired_at {$batch->expired_at} 應在 {$floor} ～ {$ceil}（{$hours} 小時後進位到 10 分鐘刻度）",
        );
    }

    // -------------------------------------------------------
    // 積分型：join 直接發放、不碰活動錢包
    // -------------------------------------------------------

    public function test_score_join_grants_batch_and_completes_without_touching_any_wallet(): void
    {
        $detail = $this->createDetail();
        $mainBefore = $this->member->balance;

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])
            ->assertOk()
            ->assertJsonPath('code', 200);

        // 參加紀錄：有，而且直接 COMPLETE——去重、對帳、後台參加紀錄都靠它
        $join = $this->soleJoin();
        $this->assertSame(EventV2WithdrawTypeEnum::SCORE, $join->withdraw_type, '參加當下把去向凍結進紀錄');
        $this->assertSame(EventV2UserJoinStatusEnum::COMPLETE->value, (int) $join->status, '參加即完成，沒有 JOIN 狀態');
        $this->assertSame('10.0000', $this->num($join->reward), 'reward 欄＝積分數量（來自提款限制公式）');
        $this->assertNull($join->withdraw, 'withdraw 留 null：積分沒轉進主錢包（target/tickets#4235），實發積分看 reward');
        $this->assertNotNull($join->end_time);
        $this->assertNull($join->balance_limit, '三道門不算');
        $this->assertNull($join->flow_limit);
        $this->assertNull($join->withdraw_limit);

        // 積分側：一批、對回參加紀錄、720 小時後到期、投影 +10
        $batch = MemberScoreBatch::query()->where('member_id', $this->member->id)->sole();
        $this->assertSame('10.0000', $this->num($batch->score));
        $this->assertSame(ScoreSourceTypeEnum::EVENT_V2_USER_JOIN, $batch->source_type);
        $this->assertSame($join->id, $batch->source_id);
        $this->assertExpiresAboutHoursLater($batch, 720);
        $this->assertSame('10.0000', $this->walletBalance(WalletEnum::CODE_SCORE));

        // 錢包路徑一筆都不能有
        $this->assertSame('0.0000', $this->walletBalance(WalletEnum::CODE_EVENT_WALLET), '活動錢包不動');
        $this->assertSame(0, $this->eventWalletLogCount($join->id), '無 type 11／14 活動錢包流水');
        $this->assertSame(0, EventV2Recycle::query()->where('event_v2_user_join_id', $join->id)->count(), '無回收紀錄');
        $this->assertSame($this->num($mainBefore), $this->num($this->member->fresh()->balance), '主錢包不變');
        $this->assertSame(0, $this->transactionLogCount(TransactionEnum::EVENT_V2_BONUS), '不寫 type 71');
        $this->assertSame(0, $this->transactionLogCount(TransactionEnum::TO_EVENT_V2_WALLET), '不寫 type 70');
        $this->assertSame(0, $this->sr11CostCount(), '不記 SR11 活動成本');
        $this->assertFalse($this->member->fresh()->is_event_v2, '沒有 JOIN 紀錄，主錢包不被掉包、不觸發互斥');
    }

    public function test_score_amount_caps_at_withdraw_config_limit(): void
    {
        $detail = $this->createDetail(['withdraw_config' => ['expire' => 720, 'limit' => 6]]);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame('6.0000', $this->num($this->soleJoin()->reward), 'limit 沿用 convertRewardFormula 的 min() 封頂');
        $this->assertSame('6.0000', $this->walletBalance(WalletEnum::CODE_SCORE));
    }

    public function test_score_amount_supports_rand_formula(): void
    {
        $detail = $this->createDetail(['withdraw_formula' => 'rand(10, 20)']);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $score = (float) MemberScoreBatch::query()->where('member_id', $this->member->id)->sole()->score;
        $this->assertGreaterThanOrEqual(10, $score, 'rand() 在提款限制公式也會被展開');
        $this->assertLessThanOrEqual(20, $score);
    }

    public function test_score_join_with_zero_amount_completes_without_batch(): void
    {
        $detail = $this->createDetail(['withdraw_formula' => '0']);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame(EventV2UserJoinStatusEnum::COMPLETE->value, (int) $this->soleJoin()->status, '0 分照樣 COMPLETE');
        $this->assertSame(0, $this->scoreBatchCount(), '0 分不呼叫 grant()（normalizeScore 對 ≤0 會拋）');
    }

    public function test_score_without_expire_grants_permanent_batch(): void
    {
        $detail = $this->createDetail(['withdraw_config' => null]);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertNull(MemberScoreBatch::query()->where('member_id', $this->member->id)->sole()->expired_at, '沒設 expire ＝ 永久');
    }

    public function test_score_type_skips_sr11_cost_even_when_cost_type_is_join(): void
    {
        $detail = $this->createDetail(['cost_type' => EventV2DetailCostTypeEnum::JOIN->value]);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame(0, $this->sr11CostCount(), '積分無貨幣價值，cost_type=JOIN 也不記成本');
    }

    // -------------------------------------------------------
    // 積分型：tiers 階梯——由上往下第一個成立者
    // -------------------------------------------------------

    /**
     * 每月儲值＋tiers。三組門檻由高到低，score 可為數字或公式。
     *
     * @param  array<int, array{condition: string, score: int|string}>  $tiers
     * @param  array<string, mixed>  $extraConfig
     */
    private function createTierDetail(array $tiers, float $depositAmount, array $extraConfig = []): EventV2Detail
    {
        $detail = $this->createDetail([
            'event_v2_id' => $this->eventV2Id('MonthlyDepositService', '每月儲值'),
            'condition_formula' => 'deposit >= 20',
            'withdraw_formula' => null,
            'withdraw_config' => array_merge(['expire' => 720, 'tiers' => $tiers], $extraConfig),
        ]);
        $this->createFinishedDeposit($depositAmount);

        return $detail;
    }

    public function test_score_tiers_take_first_matching_tier_from_top(): void
    {
        $detail = $this->createTierDetail([
            ['condition' => 'deposit >= 300', 'score' => 30],
            ['condition' => 'deposit >= 200', 'score' => 20],
            ['condition' => 'deposit >= 100', 'score' => 10],
        ], 250);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame('20.0000', $this->num(MemberScoreBatch::query()->where('member_id', $this->member->id)->sole()->score), '存 250 → 第二組（>=200）成立，不是 >=100 那組');
    }

    public function test_score_tiers_score_may_be_formula_and_limit_still_caps(): void
    {
        $detail = $this->createTierDetail([
            ['condition' => 'deposit >= 100', 'score' => 'deposit * 0.5'],
        ], 400, ['limit' => 150]);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame('150.0000', $this->num(MemberScoreBatch::query()->where('member_id', $this->member->id)->sole()->score), 'deposit*0.5=200 → limit 150 封頂');
    }

    public function test_score_tiers_with_no_match_completes_without_batch(): void
    {
        $detail = $this->createTierDetail([
            ['condition' => 'deposit >= 300', 'score' => 30],
        ], 50);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame(EventV2UserJoinStatusEnum::COMPLETE->value, (int) $this->soleJoin()->status, '都不成立 → 0 分照樣 COMPLETE');
        $this->assertSame(0, $this->scoreBatchCount());
    }

    // -------------------------------------------------------
    // 積分型：儲值類——deposit 變數可用、不扣主錢包、不用主錢包餘額擋人
    // -------------------------------------------------------

    public function test_score_deposit_event_uses_deposit_variable_and_ignores_main_wallet_deduction(): void
    {
        // 每月儲值 MonthlyDepositService 走 checkDeposit()：主錢包型會依 main_wallet_deduction 扣主錢包並檢查餘額
        $detail = $this->createDetail([
            'event_v2_id' => $this->eventV2Id('MonthlyDepositService', '每月儲值'),
            'condition_formula' => 'deposit >= 100',
            'condition_config' => ['main_wallet_deduction' => 50],
            'withdraw_formula' => 'deposit * 0.3',
        ]);
        $this->createFinishedDeposit(100);
        $this->member->update(['balance' => 0]); // 主錢包 0，主錢包型會被 BALANCE_NOT_ENOUGH 擋下

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $this->assertSame('30.0000', $this->num(MemberScoreBatch::query()->where('member_id', $this->member->id)->sole()->score), 'deposit * 0.3 = 30');
        $this->assertSame('0.0000', $this->num($this->member->fresh()->balance), '主錢包一毛未動');
        $this->assertSame(0, $this->transactionLogCount(TransactionEnum::TO_EVENT_V2_WALLET), '無 type 70 扣款');
    }

    // -------------------------------------------------------
    // 凍結：既有主錢包型參加者，活動事後改成積分也照舊領現金
    // -------------------------------------------------------

    public function test_frozen_main_wallet_type_still_pays_main_wallet_after_detail_switched_to_score(): void
    {
        $detail = $this->createMainWalletDetail(['join_withdraw' => 0, 'reward_formula' => '10']);
        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);
        $this->assertSame(EventV2WithdrawTypeEnum::MAIN_WALLET, $this->soleJoin()->withdraw_type);
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, (int) $this->soleJoin()->status);

        // 運營把活動改成積分型
        $detail->update([
            'withdraw_type' => EventV2WithdrawTypeEnum::SCORE->value,
            'reward_formula' => null,
            'withdraw_formula' => '10',
            'withdraw_config' => ['expire' => 24],
        ]);
        $mainBefore = $this->member->fresh()->balance;

        $this->requestApi(self::WITHDRAW)->assertOk()->assertJsonPath('code', 200);

        $this->assertSame(0, $this->scoreBatchCount(), '不發積分');
        $this->assertSame(Common::numAdd((string) $mainBefore, '10', 4), $this->num($this->member->fresh()->balance), '主錢包 +10');
        $this->assertSame(1, $this->transactionLogCount(TransactionEnum::EVENT_V2_BONUS), 'type 71 一筆');
    }

    // -------------------------------------------------------
    // 主錢包型錨點：既有路徑一字不變
    // -------------------------------------------------------

    public function test_main_wallet_join_withdraw_still_pays_main_wallet(): void
    {
        $detail = $this->createMainWalletDetail();
        $mainBefore = $this->member->balance;

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $join = $this->soleJoin();
        $this->assertSame(EventV2WithdrawTypeEnum::MAIN_WALLET, $join->withdraw_type, '主錢包型也凍結去向');
        $this->assertSame(EventV2UserJoinStatusEnum::COMPLETE->value, (int) $join->status);
        $this->assertSame(Common::numAdd((string) $mainBefore, '19', 4), $this->num($this->member->fresh()->balance), '主錢包 +19');
        $this->assertSame(1, $this->transactionLogCount(TransactionEnum::EVENT_V2_BONUS), 'type 71 一筆');
        $this->assertSame(2, $this->eventWalletLogCount($join->id), '活動錢包 type 11 進、type 14 歸零');
        $this->assertSame(1, EventV2Recycle::query()->where('event_v2_user_join_id', $join->id)->count(), '回收一筆');
        $this->assertSame(0, $this->scoreBatchCount(), '不發積分');
        $this->assertSame(1, $this->sr11CostCount(), '主錢包型照記 SR11 活動成本');
    }

    public function test_main_wallet_join_without_join_withdraw_stays_in_event_wallet(): void
    {
        $detail = $this->createMainWalletDetail([
            'join_withdraw' => 0,
            'balance_formula' => '300',
            'withdraw_formula' => '10',
        ]);

        $this->requestApi(self::JOIN, ['event_detail_id' => $detail->id])->assertOk()->assertJsonPath('code', 200);

        $join = $this->soleJoin();
        $this->assertSame(EventV2UserJoinStatusEnum::JOIN->value, (int) $join->status, '停在 JOIN 等領取');
        $this->assertNull($join->end_time);
        $this->assertSame('300.0000', $this->num($join->balance_limit), '三道門照算');
        $this->assertSame('10.0000', $this->num($join->withdraw_limit));
        $this->assertSame('19.0000', $this->walletBalance(WalletEnum::CODE_EVENT_WALLET), '獎金進活動錢包');
        $this->assertSame(1, $this->eventWalletLogCount($join->id), 'type 11 一筆');
        $this->assertSame(0, $this->scoreBatchCount());
        $this->assertTrue($this->member->fresh()->is_event_v2, 'JOIN 中 → 互斥生效');
    }
}
