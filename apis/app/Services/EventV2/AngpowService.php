<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\EventV2\EventV2FieldEnum;
use App\Enums\EventV2\EventV2FieldParamKindEnum;
use App\Enums\EventV2\EventV2PeriodEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\StatusEnum;
use App\Helpers\Common;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Services\MemberScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 紅包（target/tickets#4234）：花積分抽一次亂數現金，進主錢包，可重複抽到碰上限為止。
 *
 * 積分在這裡是門票不是獎品：每抽一次先扣 condition_config.score（MemberScoreService::deduct()，
 * 參照 event_v2_user_joins.id），獎金照主錢包型走——勾參加即領獎當下入主錢包，不勾則進活動錢包打流水。
 * 領取去向只能主錢包（EventV2WithdrawTypeEnum::SCORE_UNSUPPORTED_HANDLERS）。
 *
 * 所有規則都在條件配置（沒有專屬頁籤），只有 score 必填，其他不填＝不限制：
 * - score：每抽一次扣幾分（正數）。
 * - max_join：全站總次數（基底 AbstractEventV2::validate() 處理，這裡覆寫 maxJoinCount() 只算全站週期內；不含後台操作放棄）。
 * - max_amount：全站總金額，同樣只算全站週期內。已發出達到就擋；沒到就能抽、抽到多少給多少，所以最後一包可能讓總額超過（Max 2026-09-29）。
 * - member_times／member_amount：每人在會員週期內的次數／金額上限，到了直接擋。
 * - interval：同一人兩次抽之間至少隔幾秒。
 * - time_window：每天可抽的時段，資料庫存系統時間（UTC）、以系統時間判斷（Frank 2026-09-30「以系統時間」）；
 *   後台照慣例以後台時區輸入與顯示，寫入／讀出由 timeWindowToSystem()／timeWindowToBackend() 轉換，
 *   比照 start_time／end_time。後台填 ["08:00", "23:00"]（+8）存成 ["00:00", "15:00"]；起 > 迄視為跨夜。
 * 兩個週期都是欄位不在 JSON（EventV2PeriodEnum，Frank 2026-09-30 定義）：
 * - event_v2_details.member_period 會員週期（工單「是否可重複參加」）：member_times／member_amount 的累計區間，也是不勾時的作廢週期；
 * - event_v2_details.total_period 全站週期（工單「包括期間」）：max_join／max_amount 的累計區間，過了重新開一池，不作廢。
 * 累計區間都依前台時區 00:00 切（與其他類型「今天參加過沒」同一條換日線）。
 *
 * 必須：上限一律以參加當下抽到的 reward 計入、只排除 OPERATOR_QUIT（與 max_join 同一把尺）；
 * 放棄不吐回額度、已扣積分不退（Frank 2026-09-24）。
 * 必須：積分餘額在 getValidate() 先擋一次給乾淨的錯誤碼；真正扣在 afterJoinRecorded()，
 * 與參加紀錄同一交易，撞到併發餘額不足由 deduct() 拋例外整筆回滾。
 */
class AngpowService extends AbstractEventV2
{
    public const string KEY_SCORE = 'score';

    public const string KEY_MAX_AMOUNT = 'max_amount';

    public const string KEY_MEMBER_TIMES = 'member_times';

    public const string KEY_MEMBER_AMOUNT = 'member_amount';

    public const string KEY_INTERVAL = 'interval';

    public const string KEY_TIME_WINDOW = 'time_window';

    // 參數說明彈窗：條件配置七個 key；不看儲值（deposit 恆為 0）故條件公式與各限制公式沿用基底非儲值型宣告
    protected const array HANDLER_FIELD_PARAMS = [
        EventV2FieldEnum::CONDITION_CONFIG->value => [
            'kind' => EventV2FieldParamKindEnum::KEYS,
            'items' => ['angpow_score', 'angpow_max_join', 'angpow_max_amount', 'angpow_member_times', 'angpow_member_amount', 'angpow_interval', 'angpow_time_window'],
        ],
    ];

    public function listValidate(EventV2Detail $eventDetail, Collection $participation, Member $member): bool
    {
        return $this->getValidate($eventDetail, $member, $participation)->isSuccess();
    }

    public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult
    {
        $config = is_array($eventDetail->condition_config) ? $eventDetail->condition_config : [];

        $score = self::scoreCost($config);
        if ($score === null) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_SET); // 活動未設定消耗積分
        }

        if (!$this->inTimeWindow($config[self::KEY_TIME_WINDOW] ?? null)) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ANGPOW_OUT_OF_TIME_WINDOW); // 不在可領取時段
        }

        // 只看本活動、排除後台操作放棄（與 max_join 同一把尺）
        $mine = $participations
            ->where('event_v2_detail_id', $eventDetail->id)
            ->where('status', '!=', EventV2UserJoinStatusEnum::OPERATOR_QUIT->value);

        $interval = self::positiveInt($config[self::KEY_INTERVAL] ?? null);
        if ($interval !== null && $this->withinInterval($mine, $interval)) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ANGPOW_INTERVAL_NOT_REACHED); // 領取間隔未到
        }

        $memberPeriod = $eventDetail->member_period ?? EventV2PeriodEnum::NONE;
        $bucket = $memberPeriod->bucket($this->frontendTime);
        $inPeriod = $bucket === null ? $mine : $mine->where($bucket[0], $bucket[1]);

        $memberTimes = self::positiveInt($config[self::KEY_MEMBER_TIMES] ?? null);
        if ($memberTimes !== null && $inPeriod->count() >= $memberTimes) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ANGPOW_MEMBER_TIMES_REACHED); // 已達每人次數上限
        }

        $memberAmount = self::positiveNumber($config[self::KEY_MEMBER_AMOUNT] ?? null);
        if ($memberAmount !== null && Common::numCompare(self::sumReward($inPeriod), $memberAmount) >= 0) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ANGPOW_MEMBER_AMOUNT_REACHED); // 已達每人金額上限
        }

        $maxAmount = self::positiveNumber($config[self::KEY_MAX_AMOUNT] ?? null);
        if ($maxAmount !== null && Common::numCompare($this->issuedAmount($eventDetail), $maxAmount) >= 0) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ANGPOW_MAX_AMOUNT_REACHED); // 本期總金額已發完
        }

        $balance = app(MemberScoreService::class)->balance($member->id);
        if (Common::numCompare($balance, $score) < 0) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ANGPOW_SCORE_NOT_ENOUGH); // 積分不足
        }

        return EventV2ValidateResult::success();
    }

    public function getReward(EventV2Detail $eventDetail, Member $member): float
    {
        if ($this->reward === null) {
            $this->reward = $this->convertRewardFormula($eventDetail, $member);
        }

        return $this->reward;
    }

    public function getBalanceLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->balance_formula, $eventDetail, $member);
    }

    public function getFlowLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->flow_formula, $eventDetail, $member);
    }

    public function getWithdrawLimit(EventV2Detail $eventDetail, Member $member): ?float
    {
        return $this->convertLimitFormula($eventDetail->withdraw_formula, $eventDetail, $member);
    }

    /**
     * 參加紀錄建好後扣門票積分。getValidate() 已擋過餘額，這裡是併發下的最後防線：
     * deduct() 餘額不足拋 InsufficientScoreException，Controller 的交易整筆回滾、參加紀錄不留。
     */
    protected function afterJoinRecorded(EventV2Detail $eventDetail, EventV2UserJoin $userJoin, Member $member): void
    {
        $score = self::scoreCost(is_array($eventDetail->condition_config) ? $eventDetail->condition_config : []);
        if ($score === null) {
            // validate 已擋，理論上到不了；留線索而不是默默不扣
            Log::warning('Angpow join without score cost', ['event_v2_detail_id' => $eventDetail->id, 'user_join_id' => $userJoin->id]);

            return;
        }

        app(MemberScoreService::class)->deduct(
            member: $member,
            score: $score,
            type: ScoreLogTypeEnum::CONSUME,
            referenceType: ScoreSourceTypeEnum::EVENT_V2_USER_JOIN,
            referenceId: $userJoin->id,
            remark: json_encode([
                'event_v2_detail_id' => $eventDetail->id,
                'event_v2_name' => $eventDetail->eventV2?->name ?? '',
                'name' => $eventDetail->detailLang()->where('language_id', 2)->value('name') ?? '',
            ], JSON_UNESCAPED_UNICODE),
        );
    }

    /** 每抽一次扣幾分；缺、非數字或 ≤ 0 回 null（活動配錯，不能參加） */
    public static function scoreCost(array $config): ?string
    {
        $score = self::positiveNumber($config[self::KEY_SCORE] ?? null);
        if ($score === null) {
            return null;
        }

        // 積分只收 2 位小數（MemberScoreService 扣分超過 2 位會丟例外）：第 3 位起 bcmath 無條件捨去（10.559 → 10.55），
        // 驗證、扣分、前台 info 都吃這個值；截完為 0（0.001）視同沒設。輸出維持 4 位小數字串
        $truncated = Common::numAdd('0', Common::numAdd('0', $score, 2), 4);

        return Common::numCompare($truncated, '0') > 0 ? $truncated : null;
    }

    /**
     * 可抽時段（系統時間 H:i，存庫時已由後台時區轉過）。沒設＝全天；起 > 迄＝跨夜（22:00～02:00）。
     *
     * @param  mixed  $window  期望 ["HH:MM", "HH:MM"]
     */
    private function inTimeWindow(mixed $window): bool
    {
        if (!is_array($window) || count($window) !== 2) {
            return true;
        }
        [$start, $end] = array_values($window);
        if (!is_string($start) || !is_string($end)) {
            return true;
        }

        $now = $this->systemTime->format('H:i');

        return $start <= $end
            ? ($now >= $start && $now <= $end)
            : ($now >= $start || $now <= $end);
    }

    /**
     * 條件配置寫入前：time_window 由後台時區轉成系統時間（UTC）。Create／Update 共用。
     * 形狀不合（驗證層已擋）或沒設就原樣回傳；不分類型都轉，讀回時 timeWindowToBackend() 對稱轉回。
     */
    public static function timeWindowToSystem(?array $config): ?array
    {
        return self::convertTimeWindow($config, (string) config('custom.backend.timezone'), (string) config('app.timezone'));
    }

    /** 條件配置讀出給後台：time_window 由系統時間轉回後台時區，編輯時看到的就是當初填的值 */
    public static function timeWindowToBackend(?array $config): ?array
    {
        return self::convertTimeWindow($config, (string) config('app.timezone'), (string) config('custom.backend.timezone'));
    }

    /** ["HH:MM", "HH:MM"]，兩端都是合法時刻；後台驗證與存取轉換共用這把尺 */
    public static function isTimeWindow(mixed $window): bool
    {
        if (!is_array($window) || !array_is_list($window) || count($window) !== 2) {
            return false;
        }
        foreach ($window as $time) {
            if (!is_string($time) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function convertTimeWindow(?array $config, string $from, string $to): ?array
    {
        if ($config === null || !self::isTimeWindow($config[self::KEY_TIME_WINDOW] ?? null)) {
            return $config;
        }

        // 取當天日期換算：現有時區都沒有日光節約，只差固定小時；跨日由 H:i 自然繞回（+8 的 06:00 → 22:00）
        $config[self::KEY_TIME_WINDOW] = array_map(
            static fn (string $time): string => Carbon::createFromFormat('H:i', $time, $from)->setTimezone($to)->format('H:i'),
            $config[self::KEY_TIME_WINDOW],
        );

        return $config;
    }

    /**
     * 前台「抽紅包」畫面要的數字（frontend event-v2/angpow/info，target/tickets#4234）。
     * 只給畫面會用到的：活動開啟中、每抽扣幾分、可用積分、個人／全站次數的上限與剩餘、全站金額的上限與剩餘、
     * 領取間隔秒數與倒數、可抽時段（Max 2026-10-01 收斂，已用數／週期／參加即領獎不再回，要加再從這裡加）。
     * null＝那個上限沒設（不限制）。「本期」各看各的週期：個人看 member_period（可重複參加）、全站看 total_period
     * （包括期間），都依前台時區 00:00 切，與 getValidate() 擋人的算法同一把尺；上限只排除 OPERATOR_QUIT。
     * 金額一律 4 位小數字串。
     *
     * @return array<string, mixed>
     */
    public function frontendInfo(EventV2Detail $eventDetail, Member $member): array
    {
        $config = is_array($eventDetail->condition_config) ? $eventDetail->condition_config : [];

        $score = self::scoreCost($config);
        $balance = Common::numAdd('0', app(MemberScoreService::class)->balance($member->id), 4);

        $memberTimes = self::positiveInt($config[self::KEY_MEMBER_TIMES] ?? null);
        $memberUsedTimes = $memberTimes === null ? 0 : $this->memberPeriodJoins($eventDetail, $member)->count();

        $siteTimes = self::positiveInt($config['max_join'] ?? null);
        $siteAmount = self::positiveNumber($config[self::KEY_MAX_AMOUNT] ?? null);
        $siteUsedTimes = $siteTimes === null ? 0 : $this->maxJoinCount($eventDetail);
        $siteIssued = $siteAmount === null ? '0' : $this->issuedAmount($eventDetail);

        $interval = self::positiveInt($config[self::KEY_INTERVAL] ?? null);
        $lastJoinAt = $interval === null ? null : $this->lastJoinTimestamp($eventDetail, $member);
        $nextAvailableIn = ($interval === null || $lastJoinAt === null)
            ? 0
            : max(0, $interval - ($this->systemTime->getTimestamp() - $lastJoinAt));

        $window = $config[self::KEY_TIME_WINDOW] ?? null;
        $windowFrontend = self::isTimeWindow($window)
            ? self::convertTimeWindow([self::KEY_TIME_WINDOW => $window], (string) config('app.timezone'), (string) config('custom.frontend.timezone'))[self::KEY_TIME_WINDOW]
            : null;

        return [
            'is_open' => $this->isOpen($eventDetail, $window),
            'score_cost' => $score,
            'score_balance' => $balance,
            'member_times_limit' => $memberTimes,
            'member_times_remaining' => $memberTimes === null ? null : max(0, $memberTimes - $memberUsedTimes),
            'site_times_limit' => $siteTimes,
            'site_times_remaining' => $siteTimes === null ? null : max(0, $siteTimes - $siteUsedTimes),
            'site_amount_limit' => $siteAmount,
            'site_amount_remaining' => $siteAmount === null ? null : self::nonNegative(Common::numSub($siteAmount, $siteIssued, 4)),
            'interval' => $interval,
            'next_available_in' => $nextAvailableIn,
            'time_window' => $windowFrontend,
            'in_time_window' => $this->inTimeWindow($window),
        ];
    }

    /**
     * 活動是否開啟中（Max 2026-10-01 定義）：現在落在 start_time～end_time 內 且 在每日可抽時段內（in_time_window）。
     * 另加「活動啟用」：停用的活動 event/list 本來就不列，前端若還拿著舊 id 來問也不該彈。
     * 前端用它決定要不要展示紅包；未開啟時其餘數字照回，畫面可顯示「活動已結束」「不在時段內」。
     */
    public function isOpen(EventV2Detail $eventDetail, ?array $window): bool
    {
        $now = $this->systemTime->toDateTimeString();

        return (int) $eventDetail->status === StatusEnum::ENABLE->value
            && (string) $eventDetail->start_time <= $now
            && (string) $eventDetail->end_time >= $now
            && $this->inTimeWindow($window);
    }

    /** 本會員在本活動最後一次抽的時間戳（不分週期，給領取間隔倒數用）；沒抽過回 null */
    private function lastJoinTimestamp(EventV2Detail $eventDetail, Member $member): ?int
    {
        $last = EventV2UserJoin::query()
            ->where('event_v2_detail_id', $eventDetail->id)
            ->where('member_id', $member->id)
            ->where('status', '!=', EventV2UserJoinStatusEnum::OPERATOR_QUIT->value)
            ->max('created_at');

        return $last === null ? null : Carbon::parse($last)->getTimestamp();
    }

    /** 個人上限的統計母體：本會員、本活動、排除後台操作放棄、落在本期（member_period）內 */
    private function memberPeriodJoins(EventV2Detail $eventDetail, Member $member): Builder
    {
        $period = $eventDetail->member_period ?? EventV2PeriodEnum::NONE;
        $start = $period->periodStart($this->frontendTime);

        return EventV2UserJoin::query()
            ->where('event_v2_detail_id', $eventDetail->id)
            ->where('member_id', $member->id)
            ->where('status', '!=', EventV2UserJoinStatusEnum::OPERATOR_QUIT->value)
            ->when($start !== null, fn (Builder $q) => $q->where('created_at', '>=', $start->copy()->toSystemTime()->toDateTimeString()));
    }

    /** 剩餘額度不給負數：最後一包可能讓已發出超過上限（10.5），畫面上顯示 0 */
    private static function nonNegative(string $amount): string
    {
        return Common::numCompare($amount, '0', 4) < 0 ? Common::numAdd('0', '0', 4) : $amount;
    }

    /** 距上一次抽不足 interval 秒 */
    private function withinInterval(Collection $mine, int $interval): bool
    {
        $last = $mine->max(fn (EventV2UserJoin $join) => $join->created_at?->getTimestamp());
        if ($last === null) {
            return false;
        }

        return ($this->systemTime->getTimestamp() - (int) $last) < $interval;
    }

    /** max_join 只算全站週期內的筆數；不分週期就是基底的整檔累計 */
    protected function maxJoinCount(EventV2Detail $eventDetail): int
    {
        return $this->totalPeriodJoins($eventDetail)->count();
    }

    /** 本期已發出的金額（全站週期內、參加當下的 reward 加總，不含後台操作放棄） */
    private function issuedAmount(EventV2Detail $eventDetail): string
    {
        $sum = $this->totalPeriodJoins($eventDetail)->sum('reward');

        return Common::numAdd('0', (string) $sum, 4);
    }

    /**
     * 全站上限的統計母體：本活動、排除後台操作放棄、落在本期（total_period）內的參加紀錄。
     * 期的起點依前台時區 00:00 切，再換成系統時間比 created_at；不分週期就不加時間條件。
     */
    private function totalPeriodJoins(EventV2Detail $eventDetail): Builder
    {
        $totalPeriod = $eventDetail->total_period ?? EventV2PeriodEnum::NONE;
        $start = $totalPeriod->periodStart($this->frontendTime);

        return EventV2UserJoin::query()
            ->where('event_v2_detail_id', $eventDetail->id)
            ->where('status', '!=', EventV2UserJoinStatusEnum::OPERATOR_QUIT->value)
            ->when($start !== null, fn (Builder $q) => $q->where('created_at', '>=', $start->copy()->toSystemTime()->toDateTimeString()));
    }

    private static function sumReward(Collection $joins): string
    {
        $total = '0';
        foreach ($joins as $join) {
            $total = Common::numAdd($total, (string) ($join->reward ?? 0), 4);
        }

        return $total;
    }

    private static function positiveNumber(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }
        $normalized = Common::numAdd('0', (string) $value, 4);

        return Common::numCompare($normalized, '0') > 0 ? $normalized : null;
    }

    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value >= 1 ? (int) $value : null;
        }

        return null;
    }
}
