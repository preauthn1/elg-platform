<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Enums\Event\PrizeMemberStatusEnum;
use App\Enums\EventV2\EventV2FieldEnum;
use App\Enums\EventV2\EventV2FieldParamKindEnum;
use App\Enums\EventV2\EventV2RejectJoinStatusEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\EventV2\EventV2ValidateCodeEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Enums\FlowLimitStatusEnum;
use App\Enums\MemberRestrictionTargetEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Helpers\Common;
use App\Models\EventV2BonusCode;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailTarget;
use App\Models\EventV2RejectJoin;
use App\Models\EventV2UserJoin;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\NewbieChallengeProgress;
use App\Models\Order;
use App\Models\PrizeMember;
use App\Models\TransactionLog;
use App\Services\EventV2\Traits\ChecksDepositAndPeriodQualification;
use App\Services\EventV2\Traits\CollectsQualificationIssues;
use App\Services\EventV2\Traits\EnforcesParticipationBlocks;
use App\Services\MemberScoreService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use NXP\MathExecutor;

/**
 * @method void insertInviteMember(int $userJoinId)
 * @method bool listValidate(EventV2Detail $eventDetail, Collection $participation, Member $member)
 */
abstract class AbstractEventV2
{
    use ChecksDepositAndPeriodQualification;
    use CollectsQualificationIssues;
    use EnforcesParticipationBlocks;

    protected ?Carbon $systemTime = null;

    protected ?Carbon $frontendTime = null;

    protected ?float $reward = null;

    protected ?Order $deposit = null;

    /** target/tickets#4352：合格儲值來源為後台開分（非真實 orders 儲值）時設定，與 $deposit 互斥 */
    protected ?TransactionLog $backendDeposit = null;

    protected ?float $mainWalletDeduction = null; // condition_config['main_wallet_deduction'] 設定的固定扣除金額；null 代表不扣

    protected bool $isInvite = false;

    /**
     * 儲值類 handler 設為 true：條件公式讀 deposit、條件配置讀 main_wallet_deduction、
     * 獎勵與三個限制公式可用 deposit 變數（非儲值類的 deposit 恆為 0，列出來只會誤導運營）。
     */
    protected const bool READS_DEPOSIT = false;

    /**
     * 各 handler 覆寫：與基底宣告不同的格，整格取代不合併。key 為 EventV2FieldEnum 的 value。
     *
     * @var array<string, array{kind: EventV2FieldParamKindEnum, items: list<string>}>
     */
    protected const array HANDLER_FIELD_PARAMS = [];

    /**
     * 本類型「設定條件」頁籤十格各自會讀的參數，給後台參數說明彈窗用（target/tickets#4231 需求 7）。
     *
     * 一律以「程式實際讀取」為準，不是「畫面上有沒有那格」：列出來的變數／key 填了才有作用，沒列的填了也不會被讀。
     * items 是 resources/lang/{locale}/event_v2.php 的 params 目錄 id（名稱、型別、說明、範例都在那裡維護），
     * 本地化由 EventV2FieldParamService 做。
     *
     * 基底＝走 convertRewardFormula() 的公式型：條件配置 max_join、獎勵公式 rand()、獎勵配置 limit、
     * 三個限制公式 reward（儲值類再加 deposit）、提款限制配置 balance_multiple；餘額／稽核流水配置沒有類型會讀。
     * rand() 只在 convertRewardFormula() 內展開，邀請好友／下線傭金自己執行獎勵公式、限制公式也不經過它，所以那些格不列 rand。
     * 領取去向＝積分時提款限制那一對改讀積分數量（變數同獎勵公式）與 expire／limit／tiers，以 score 子項另列，
     * 只給 EventV2WithdrawTypeEnum 允許的類型。
     *
     * 必須：handler 讀到新的 key／變數時同步宣告在 HANDLER_FIELD_PARAMS 並補四語系目錄，
     * tests/Unit/Services/EventV2/FieldParamsCatalogTest 會擋漏。
     *
     * @return array<string, array{kind: EventV2FieldParamKindEnum, items: list<string>, score?: array{kind: EventV2FieldParamKindEnum, items: list<string>}}>
     */
    public static function fieldParams(): array
    {
        $limitVariables = static::READS_DEPOSIT ? ['deposit', 'reward'] : ['reward'];

        $base = [
            EventV2FieldEnum::CONDITION_FORMULA->value => static::READS_DEPOSIT
                ? ['kind' => EventV2FieldParamKindEnum::VARIABLES, 'items' => ['deposit']]
                : ['kind' => EventV2FieldParamKindEnum::NONE, 'items' => []],
            EventV2FieldEnum::CONDITION_CONFIG->value => [
                'kind' => EventV2FieldParamKindEnum::KEYS,
                'items' => static::READS_DEPOSIT ? ['max_join', 'main_wallet_deduction'] : ['max_join'],
            ],
            EventV2FieldEnum::REWARD_FORMULA->value => [
                'kind' => EventV2FieldParamKindEnum::VARIABLES,
                'items' => static::READS_DEPOSIT ? ['deposit', 'rand'] : ['rand'],
            ],
            EventV2FieldEnum::REWARD_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::KEYS, 'items' => ['limit']],
            EventV2FieldEnum::BALANCE_FORMULA->value => ['kind' => EventV2FieldParamKindEnum::VARIABLES, 'items' => $limitVariables],
            EventV2FieldEnum::BALANCE_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::NONE, 'items' => []],
            EventV2FieldEnum::WITHDRAW_FORMULA->value => ['kind' => EventV2FieldParamKindEnum::VARIABLES, 'items' => $limitVariables],
            EventV2FieldEnum::WITHDRAW_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::KEYS, 'items' => ['balance_multiple']],
            EventV2FieldEnum::FLOW_FORMULA->value => ['kind' => EventV2FieldParamKindEnum::VARIABLES, 'items' => $limitVariables],
            EventV2FieldEnum::FLOW_CONFIG->value => ['kind' => EventV2FieldParamKindEnum::NONE, 'items' => []],
        ];

        $params = array_replace($base, static::HANDLER_FIELD_PARAMS);

        // 積分型：getScoreAmount() 把提款限制公式／配置當獎勵公式／配置交給 getReward()，變數與獎勵公式相同
        if (EventV2WithdrawTypeEnum::SCORE->supportsHandler(class_basename(static::class))) {
            $params[EventV2FieldEnum::WITHDRAW_FORMULA->value]['score'] = [
                'kind' => EventV2FieldParamKindEnum::VARIABLES,
                'items' => $params[EventV2FieldEnum::REWARD_FORMULA->value]['items'],
            ];
            $params[EventV2FieldEnum::WITHDRAW_CONFIG->value]['score'] = [
                'kind' => EventV2FieldParamKindEnum::KEYS,
                'items' => ['expire', 'score_limit', 'score_tiers'],
            ];
        }

        return $params;
    }

    public function __construct(
        protected MathExecutor $executor,
        protected EventV2Service $eventV2Service,
    ) {
        $this->systemTime = Carbon::now();
        $this->frontendTime = $this->systemTime->copy()->toFrontendTime();
    }

    /** $deposit（真實 orders 儲值）與 $backendDeposit（後台開分）互斥，公式與流水比對只需要金額 */
    protected function currentDepositAmount(): float
    {
        return (float) ($this->deposit?->amount ?? $this->backendDeposit?->amount ?? 0);
    }

    /**
     * 純檢查活動是否能參加，不取鎖。安全用於唯讀情境（如 detail() 顯示、驚嘆號判斷）。
     * 若檢查通過後還要接著 join()/withdraw()，呼叫端必須自行用同一把
     * "event_v2:validate:{member_id}" 鎖包住整段流程，否則會有併發重複參加的風險，
     * 詳見 EventV2Controller::join()。
     */
    public function validate(int $eventDetailId, Member $member): EventV2ValidateResult
    {
        $time = $this->systemTime->toDateTimeString();
        $eventDetail = EventV2Detail::select('event_v2.id AS event_v2_id', 'event_v2_details.id', 'event_v2_details.condition_formula', 'event_v2_details.condition_config', 'event_v2_details.ip_block', 'event_v2_details.phone_block', 'event_v2_details.account_block', 'event_v2_details.start_time', 'event_v2_details.end_time', 'event_v2_details.status', 'event_v2_details.created_at', 'event_v2_details.withdraw_type', 'event_v2_details.member_period', 'event_v2_details.total_period')
            ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
            ->where('event_v2_details.id', $eventDetailId)
            ->where('event_v2_details.start_time', '<=', $time)
            ->where('event_v2_details.end_time', '>=', $time)
            ->where('event_v2_details.status', 1)
            ->lockForUpdate()
            ->first();

        if ($eventDetail === null) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::EVENT_NOT_EXIST); // 活動不存在
        }

        if (!$this->isTargetMember($eventDetailId, $member)) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::TARGET_NOT_MATCHED); // 不在活動開放的會員／群組內
        }

        $crossActivityBlock = $this->checkCrossActivityParticipationBlock($member);
        if ($crossActivityBlock !== null) {
            return $crossActivityBlock;
        }

        $timeOffset = $this->frontendTime->offsetHours;
        // reward／created_at／year 給紅包算週期內的次數、金額與領取間隔（target/tickets#4234）；其他類型不讀
        $participations = EventV2UserJoin::select('event_v2.id AS event_v2_id', 'event_v2_user_joins.event_v2_detail_id', 'event_v2_user_joins.status', 'event_v2_user_joins.reward', 'event_v2_user_joins.created_at')
            ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS date', [$timeOffset, '%Y-%m-%d'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS week', [$timeOffset, '%x-%v'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS month', [$timeOffset, '%Y-%m'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS year', [$timeOffset, '%Y'])
            ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
            ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
            ->where('event_v2_user_joins.member_id', $member->id)
            ->get();

        if ($participations->where('status', EventV2UserJoinStatusEnum::JOIN->value)->where('event_v2_detail_id', '!=', $eventDetailId)->isNotEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ONLY_ONE_EVENT); // 一次只能參加一個活動
        }

        if ($participations->where('status', EventV2UserJoinStatusEnum::JOIN->value)->where('event_v2_detail_id', $eventDetailId)->isNotEmpty()) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::HAS_JOIN); // 您已經參加過這個活動，無法再次參加
        }

        $maxJoin = $eventDetail->condition_config['max_join'] ?? null;
        if ($maxJoin !== null && $this->maxJoinCount($eventDetail) >= (int) $maxJoin) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::MAX_JOIN_REACHED); // 活動人數已達上限
        }

        if ($eventDetail->phone_block !== null) {
            if ($member->mobile_validate === 0) {
                return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::PHONE_NOT_VALIDATE); // 請先綁定手機驗證
            }
        }

        $result = $this->getValidate(
            eventDetail: $eventDetail,
            member: $member,
            participations: $participations);

        if ($result->isFailed()) {
            return $result;
        }

        // 拒絕紀錄
        $rejectRecord = EventV2RejectJoin::where('event_v2_detail_id', $eventDetail->id)
            ->where('member_id', $member->id)
            ->whereNull('event_v2_user_join_id')
            ->first();

        if ($rejectRecord !== null && $rejectRecord->status === EventV2RejectJoinStatusEnum::ALLOW_JOIN->value) {
            return EventV2ValidateResult::success()->setEventV2RejectJoin($rejectRecord); // 後台手動同意參加
        }

        $blocked = $this->checkParticipationBlocks($eventDetail, $member);
        if ($blocked !== null) {
            return $blocked;
        }

        $success = EventV2ValidateResult::success();
        if ($rejectRecord !== null) {
            $success->setEventV2RejectJoin($rejectRecord);
        }

        return $success;
    }

    /**
     * 依序檢查 IP／手機／實名重複參加阻擋，有設才檢查；回傳第一個失敗的結果，全過回 null。
     * 自 validate() 抽出以壓低圈複雜度（target/tickets#4234 加目標用戶判斷時觸頂），檢查順序與原本相同。
     */
    private function checkParticipationBlocks(EventV2Detail $eventDetail, Member $member): ?EventV2ValidateResult
    {
        $checks = [
            [$eventDetail->ip_block, fn () => $this->checkIpBlock($eventDetail, $member)],
            [$eventDetail->phone_block, fn () => $this->checkPhoneBlock($eventDetail, $member)],
            [$eventDetail->account_block, fn () => $this->checkAccountBlock($eventDetail, $member)],
        ];

        foreach ($checks as [$setting, $check]) {
            if ($setting === null) {
                continue;
            }
            $result = $check();
            if ($result->isFailed()) {
                return $result;
            }
        }

        return null;
    }

    /**
     * 檢查是否有其他活動系統的參加狀態擋下本次參加：活動v1流水未打完，或新人專享挑戰活動進行中。
     * 回傳 null 代表可以繼續參加。
     */
    private function checkCrossActivityParticipationBlock(Member $member): ?EventV2ValidateResult
    {
        $hasPendingFlowLimit = FlowLimit::where('member_id', $member->id)
            ->whereNotIn('type', [
                TransactionEnum::ORDER_DEPOSIT->value,
                TransactionEnum::DEPOSIT->value,
                TransactionEnum::COUPON_CODE->value,
            ])
            ->where('event_id', '!=', 0)
            ->whereColumn('flow_limit', '>', 'reach')
            ->where('status', FlowLimitStatusEnum::PENDING->value)
            ->exists();

        if ($hasPendingFlowLimit) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::EVENT_MODE); // 活動v1流水沒有打完，不能參加活動v2
        }

        $hasActiveNewbieChallenge = NewbieChallengeProgress::where('member_id', $member->id)
            ->whereNotIn('stage', [
                NewbieChallengeStageEnum::BONUS_COMPLETED->value,
                NewbieChallengeStageEnum::QUIT->value,
                NewbieChallengeStageEnum::OPERATOR_QUIT->value,
            ])
            ->where(function ($query) {
                // BONUS_PENDING 的 48hr 彩金領取窗口一旦過期，視同不再進行中；
                // 不特別存一個「已失效」狀態，直接用時間判斷，避免狀態欄位跟實際時間不同步
                $query->where('stage', '!=', NewbieChallengeStageEnum::BONUS_PENDING->value)
                    ->orWhere('bonus_window_expire_at', '>=', now());
            })
            ->exists();

        if ($hasActiveNewbieChallenge) {
            return EventV2ValidateResult::fail(EventV2ValidateCodeEnum::ONLY_ONE_EVENT); // 新人專享挑戰活動進行中，不能同時參加活動v2
        }

        return null;
    }

    public function join(EventV2Detail $eventDetail, Member $member, MemberWallet $wallet, EventV2ValidateResult $validateResult): EventV2UserJoin
    {
        // 領取去向＝積分（target/tickets#4231）：積分與活動錢包無關——數量由提款限制公式算、有效期讀提款限制配置，
        // 參加當下直接 grant()、紀錄即 COMPLETE；不算三道門、不扣主錢包、不進活動錢包、不呼叫 initEventV2()。
        $isScore = $this->isScoreWithdraw($eventDetail);
        if ($isScore) {
            $reward = $this->getScoreAmount($eventDetail, $member);
            $balanceLimit = $FlowLimit = $withdrawLimit = null;
        } else {
            $reward = $this->getReward(eventDetail: $eventDetail, member: $member);
            $balanceLimit = $this->getBalanceLimit(eventDetail: $eventDetail, member: $member);
            $FlowLimit = $this->getFlowLimit(eventDetail: $eventDetail, member: $member);
            $withdrawLimit = $this->getWithdrawLimit(eventDetail: $eventDetail, member: $member);
        }

        // 取得活動優惠碼，注意::因為要使用一般的遊戲帳號，所以要寫在 EventV2UserJoin create 之前
        if ($eventDetail->bonus_vendor_id !== null) {
            $bonusCode = $this->eventV2Service->getVendorBonusCode($eventDetail->bonus_vendor_id, $member);
        }

        $userJoin = new EventV2UserJoin;
        $userJoin->event_v2_detail_id = $eventDetail->id;
        $userJoin->member_id = $member->id;
        $userJoin->reward = $reward;
        $userJoin->balance_limit = $balanceLimit;
        $userJoin->flow_limit = $FlowLimit;
        $userJoin->withdraw_limit = $withdrawLimit;
        $userJoin->order_id = $this->deposit?->id ?? 0;
        $userJoin->deposit_transaction_log_id = $this->backendDeposit?->id;
        $userJoin->phone = $member->mobile;
        // 凍結領取去向：Observer 與領取都以紀錄上的值為準，運營中途改活動設定不影響已參加者
        $userJoin->withdraw_type = $eventDetail->withdraw_type ?? EventV2WithdrawTypeEnum::MAIN_WALLET;
        if ($isScore) {
            // 沒有領取步驟：連交易內都不存在 JOIN 瞬間，Member::isEventV2() 不會為真、主錢包不會被掉包。
            // withdraw 留 null（target/tickets#4235）：積分沒轉進主錢包、也不經 initEventV2()，跟 final_balance 一樣不寫；實發積分看 reward
            $userJoin->status = EventV2UserJoinStatusEnum::COMPLETE->value;
            $userJoin->end_time = now()->toDateTimeString();
        } else {
            $userJoin->status = EventV2UserJoinStatusEnum::JOIN->value;
        }
        $userJoin->ip = Common::getIp();
        $userJoin->save();

        $this->afterJoinRecorded($eventDetail, $userJoin, $member);

        $allowJoin = $validateResult->getEventV2RejectJoin();
        if ($allowJoin !== null) {
            $allowJoin->event_v2_user_join_id = $userJoin->id;
            $allowJoin->status = EventV2RejectJoinStatusEnum::JOIN->value;
            $allowJoin->save();
        }

        if ($userJoin->order_id !== 0) {
            PrizeMember::where('member_id', $member->id)
                ->where('lottery_number', $userJoin->order_id)
                ->where('status', PrizeMemberStatusEnum::UNAWARD->value)
                ->update(['status' => PrizeMemberStatusEnum::CANCEL->value]);
        }

        // 活動優惠碼紀錄
        if ($eventDetail->bonus_vendor_id !== null) {
            EventV2BonusCode::create([
                'event_v2_user_join_id' => $userJoin->id,
                'vendor_id' => $eventDetail->bonus_vendor_id,
                'bonus_code' => $bonusCode,
            ]);
        }

        if (!$isScore && $this->mainWalletDeduction !== null) {
            if (Common::numCompare($member->balance, $this->mainWalletDeduction) < 0) {
                throw new \Exception(__('event_v2.validation.balance'), 504); // 錢包餘額不足
            }

            $reward = Common::numAdd($reward, $this->mainWalletDeduction);

            $beforeBalance = $member->balance;
            $afterBalance = Common::numSub($member->balance, $this->mainWalletDeduction);
            $member->balance = $afterBalance;
            $member->save();

            $detailName = $eventDetail->detailLang()->where('language_id', 2)->value('name') ?? '';
            $this->eventV2Service->insertTransactionLog($member, [
                'type' => TransactionEnum::TO_EVENT_V2_WALLET->value,
                'point' => $this->mainWalletDeduction,
                'before_balance' => $beforeBalance,
                'balance' => $afterBalance,
                'remark' => json_encode([
                    'event_v2_detail_id' => $eventDetail->id,
                    'event_v2_name' => $eventDetail->eventV2?->name ?? '',
                    'name' => $detailName,
                ]),
            ]);

            // 扣除玩家稽核流水（對應儲值產生的 flow_limits 紀錄）：
            // 真實儲值訂單要多一步從 order_id 反查建單當下寫的 TransactionLog；
            // target/tickets#4352 的後台開分沒有 orders 列，$backendDeposit 本身就是那筆 TransactionLog，不必再查
            $depositTransactionLog = $this->backendDeposit
                ?? TransactionLog::where('order_id', $this->deposit->id)
                    ->where('type', TransactionEnum::ORDER_DEPOSIT->value)
                    ->first();

            if ($depositTransactionLog !== null) {
                $flowLimit = FlowLimit::where('member_id', $member->id)
                    ->where('status', FlowLimitStatusEnum::PENDING->value)
                    ->where(function ($q) use ($depositTransactionLog) {
                        $q->where('reference_id', $depositTransactionLog->id)
                            ->orWhere('wallet_transaction_log_id', $depositTransactionLog->id);
                    })
                    ->first();

                if ($flowLimit !== null) {
                    $newReach = (float) Common::numAdd($flowLimit->reach, $this->mainWalletDeduction);
                    $flowLimit->reach = min($newReach, (float) $flowLimit->flow_limit);
                    if (Common::numCompare($flowLimit->reach, $flowLimit->flow_limit) >= 0) {
                        $flowLimit->status = FlowLimitStatusEnum::PASSED->value;
                    }
                    $flowLimit->admin = 0;
                    $flowLimit->save();
                }
            }
        }

        // 只有邀請好友活動才會執行
        if ($this->isInvite === true && method_exists($this, 'insertInviteMember')) { // @phpstan-ignore function.alreadyNarrowedType (@method PHPDoc 宣告使 PHPStan 認為方法恆存在，但實際只在 InviteFriendService 實作)
            $this->insertInviteMember($userJoin->id);
        }

        if ($isScore) {
            $this->grantScore($eventDetail, $userJoin, $member, (string) $reward);

            return $userJoin;
        }

        $wallet->balance = $reward;
        $wallet->save();

        $this->eventV2Service->insertWalletTransactionLog($member, $userJoin, [
            'type' => WalletTransactionEnum::EVENT_WALLET_JOIN->value,
            'point' => $reward,
            'before_balance' => 0,
            'balance' => $wallet->balance,
        ]);

        return $userJoin;
    }

    /**
     * 領取去向是否為積分（target/tickets#4231）。
     * 參加時看活動設定（EventV2Detail）；建好紀錄後（Observer）一律看 event_v2_user_joins 上的凍結值。
     * 欄位沒被 select 進來時視為主錢包，部分欄位查詢不會在這裡炸。
     */
    protected function isScoreWithdraw(EventV2Detail|EventV2UserJoin $model): bool
    {
        return $model->withdraw_type instanceof EventV2WithdrawTypeEnum && $model->withdraw_type->isScore();
    }

    /**
     * 積分數量：積分型把「提款限制公式／提款限制配置」當成自己的獎勵公式／獎勵配置來算（target/tickets#4231 定案：
     * 積分與活動錢包無關，數量與有效期配在同一對欄位；獎勵公式／配置對積分型必為空，後台已擋）。
     * 借 handler 自己的 getReward() 算——deposit／invite／win_lose 變數、rand()、limit 封頂全部沿用，handler 零改動。
     * 提款限制配置對積分型是 {"expire": 小時, "limit": 上限}，兩個 key 都選填。
     */
    protected function getScoreAmount(EventV2Detail $eventDetail, Member $member): float
    {
        $formula = self::compileScoreTiers($eventDetail->withdraw_config) ?? $eventDetail->withdraw_formula;
        if ($formula === null || $formula === '') {
            return 0.0;
        }

        $view = clone $eventDetail;
        $view->reward_formula = $formula;
        $view->reward_config = $eventDetail->withdraw_config;

        // getReward() 有快取（$this->reward），確保是以提款側公式重算
        $this->reward = null;

        return round($this->getReward($view, $member), 2);
    }

    /**
     * 階梯配法 withdraw_config.tiers：[{"condition":"deposit >= 300","score":30}, …]，由上往下第一個成立的那組就是積分數，
     * 都不成立為 0。編譯成巢狀 if(c1, s1, if(c2, s2, … 0)) 再交給 getReward()，所以 deposit／invite／win_lose、rand()、limit
     * 全部沿用；運營不必手寫巢狀 if（後台驗證已保證每組有 condition 與 score）。沒有 tiers 回 null，改走提款限制公式。
     *
     * @param  array<array-key, mixed>|null  $config
     */
    protected static function compileScoreTiers(?array $config): ?string
    {
        $tiers = $config['tiers'] ?? null;
        if (!is_array($tiers) || $tiers === [] || !array_is_list($tiers)) {
            return null;
        }

        $formula = '0';
        foreach (array_reverse($tiers) as $tier) {
            $condition = trim((string) ($tier['condition'] ?? ''));
            $score = trim((string) ($tier['score'] ?? ''));
            if ($condition === '' || $score === '') {
                continue;
            }
            $formula = 'if(' . $condition . ', ' . $score . ', ' . $formula . ')';
        }

        return $formula === '0' ? null : $formula;
    }

    /**
     * 積分型發放：參加當下經 MemberScoreService::grant() 發成一批，有效期讀 withdraw_config.expire。
     * 數量 ≤ 0 不發、紀錄照樣 COMPLETE——對應主錢包型「加 0 元、流程繼續」的既有行為；
     * normalizeScore() 對 ≤ 0 會拋例外，不能直接丟進去。
     * grant() 不自開交易，join() 由 Controller 的 DB::transaction 包住。
     */
    protected function grantScore(EventV2Detail $eventDetail, EventV2UserJoin $userJoin, Member $member, string $score): void
    {
        $score = Common::numAdd('0', $score, 2);
        if (Common::numCompare($score, '0') <= 0) {
            // 留線索：運營把階梯／公式配到全部不成立時，玩家會拿到一筆 COMPLETE 卻零積分的紀錄，客訴時靠這行排查
            Log::info('活動 V2 積分型算出 0 分，不發批次', [
                'event_v2_detail_id' => $eventDetail->id,
                'event_v2_user_join_id' => $userJoin->id,
                'member_id' => $member->id,
                'withdraw_formula' => $eventDetail->withdraw_formula,
                'withdraw_config' => $eventDetail->withdraw_config,
            ]);

            return;
        }

        $detailName = $eventDetail->detailLang()->where('language_id', 2)->value('name') ?? '';

        app(MemberScoreService::class)->grant(
            member: $member,
            score: $score,
            hours: self::scoreExpireHours($eventDetail->withdraw_config),
            source: ScoreSourceTypeEnum::EVENT_V2_USER_JOIN,
            sourceId: $userJoin->id,
            remark: json_encode([
                'event_v2_detail_id' => $eventDetail->id,
                'event_v2_name' => $eventDetail->eventV2?->name ?? '',
                'name' => $detailName,
            ], JSON_UNESCAPED_UNICODE),
        );
    }

    /**
     * 讀 withdraw_config.expire：正整數（小時）才算；缺省／空＝永久（null）。
     * 非法值（負數、小數、非數字）後台驗證已擋新寫入，執行端只會在 SQL 直改／跨站搬資料時碰到——
     * 一樣退成永久但留 warning，不讓「設定錯誤」默默變成「永不到期的積分」。
     *
     * @param  array<array-key, mixed>|null  $config
     */
    protected static function scoreExpireHours(?array $config): ?int
    {
        $expire = $config['expire'] ?? null;
        if ($expire === null || $expire === '') {
            return null;
        }

        if ((is_int($expire) || (is_string($expire) && ctype_digit($expire))) && (int) $expire >= 1) {
            return (int) $expire;
        }

        Log::warning('活動 V2 積分型 withdraw_config.expire 非法，視為永久', ['expire' => $expire]);

        return null;
    }

    public function withdraw(EventV2UserJoin $userJoin, Member $member, MemberWallet $wallet): float
    {
        $balance = (isset($userJoin->eventV2Detail->withdraw_config['balance_multiple'])) ?
            (float) Common::numMul($wallet->balance, $userJoin->eventV2Detail->withdraw_config['balance_multiple'], 2) :
            $wallet->balance;

        $withdraw = $userJoin->withdraw_limit === null ? $balance : min($wallet->balance, $userJoin->withdraw_limit);
        if ($withdraw === null) {
            throw new \Exception('detail_id: ' . $userJoin->event_v2_detail_id . ' Withdraw not found');
        }

        $beforeBalance = $member->balance;
        $afterBalance = Common::numAdd($member->balance, $withdraw);

        // 更新會員餘額
        $member->update([
            'balance' => $afterBalance,
        ]);

        $detailName = $userJoin->eventV2Detail?->detailLang()->where('language_id', 2)->value('name') ?? '';
        $this->eventV2Service->insertTransactionLog($member, [
            'type' => TransactionEnum::EVENT_V2_BONUS->value,
            'point' => $withdraw,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'remark' => json_encode([
                'event_v2_detail_id' => $userJoin->event_v2_detail_id,
                'event_v2_name' => $userJoin->eventV2Detail?->eventV2?->name ?? '',
                'name' => $detailName,
            ], JSON_UNESCAPED_UNICODE),
        ]);

        // 重置活動
        $userJoin->withdraw = $withdraw;
        $userJoin->status = EventV2UserJoinStatusEnum::COMPLETE->value;
        $this->eventV2Service->initEventV2($member, $userJoin);

        return (float) $withdraw;
    }

    protected function convertRandFormula(string $formula): string
    {
        $pattern = '/rand\s*\(\s*([\d.]+)\s*,\s*([\d.]+)\s*\)/i';

        return preg_replace_callback($pattern, function (array $matches): string {
            $min = (float) $matches[1];
            $max = (float) $matches[2];

            $randomFloat = $min + mt_rand() / mt_getrandmax() * ($max - $min);

            return (string) round($randomFloat, 2);
        }, $formula) ?? $formula;
    }

    protected function convertRewardFormula(EventV2Detail $eventDetail, Member $member): float
    {

        $formula = $eventDetail->reward_formula;
        $config = $eventDetail->reward_config;

        if ($formula === null) {
            return 0.0;
        }

        if (str_contains($formula, 'rand')) {
            $formula = $this->convertRandFormula($formula);
        }

        if (str_contains($formula, 'deposit')) {
            $this->executor->setVar('deposit', $this->currentDepositAmount());
        }

        $reward = (float) $this->executor->execute($formula);

        // reward_config 除了 limit 之外還可能帶其他 key（例如積分有效期 expire），
        // 只在真的有 limit 時封頂；沒有 limit 就照公式結果發，不能因為 config 非 null 就去讀
        if (isset($config['limit'])) {
            $reward = min($reward, (float) $config['limit']);
        }

        return $reward;
    }

    protected function convertLimitFormula(?string $formula, EventV2Detail $eventDetail, Member $member): ?float
    {
        if ($formula === null) {
            return null;
        }

        if (str_contains($formula, 'deposit')) {
            $this->executor->setVar('deposit', $this->currentDepositAmount());
        }

        if (str_contains($formula, 'reward')) {
            $reward = $this->getReward($eventDetail, $member);

            $this->executor->setVar('reward', $reward);
        }

        return (float) $this->executor->execute($formula);
    }

    /**
     * 目標用戶（target/tickets#4234，所有類型共用）：活動沒有任何目標列＝不限制；
     * 有的話，會員本人被指定、或他目前所屬的會員群組被指定才算命中。參加當下才比對，群組調整即時生效。
     * 判斷方式與風控 MemberRestrictionGuard 同一套（target_type morph alias member／member_group）。
     */
    protected function isTargetMember(int $eventDetailId, Member $member): bool
    {
        $targets = EventV2DetailTarget::query()->where('event_v2_detail_id', $eventDetailId);
        if (!(clone $targets)->exists()) {
            return true;
        }

        return $targets->where(function ($query) use ($member) {
            $query->where(function ($q) use ($member) {
                $q->where('target_type', MemberRestrictionTargetEnum::MEMBER->morphAlias())
                    ->where('target_id', $member->id);
            });
            if ($member->member_group_id) {
                $query->orWhere(function ($q) use ($member) {
                    $q->where('target_type', MemberRestrictionTargetEnum::MEMBER_GROUP->morphAlias())
                        ->where('target_id', $member->member_group_id);
                });
            }
        })->exists();
    }

    /**
     * 參加紀錄剛建立（已有 id）、任何資金動作之前的掛鉤；預設不做事。
     * 紅包在這裡扣門票積分（target/tickets#4234），要拿 event_v2_user_joins.id 當扣除的參照。
     * 整段在 Controller 的 DB::transaction 內：拋例外即整筆回滾，參加紀錄不留。
     */
    protected function afterJoinRecorded(EventV2Detail $eventDetail, EventV2UserJoin $userJoin, Member $member): void {}

    /**
     * condition_config.max_join 拿來比的筆數：預設整檔活動累計、只排除後台操作放棄（OPERATOR_QUIT）。
     * 紅包覆寫成只算全站週期（total_period）內的筆數（target/tickets#4234）；其他類型不要動這裡。
     */
    protected function maxJoinCount(EventV2Detail $eventDetail): int
    {
        return EventV2UserJoin::where('event_v2_detail_id', $eventDetail->id)
            ->where('status', '!=', EventV2UserJoinStatusEnum::OPERATOR_QUIT->value)
            ->count();
    }

    abstract public function getValidate(EventV2Detail $eventDetail, Member $member, Collection $participations): EventV2ValidateResult;

    abstract public function getReward(EventV2Detail $eventDetail, Member $member): float;

    abstract public function getBalanceLimit(EventV2Detail $eventDetail, Member $member): ?float;

    abstract public function getFlowLimit(EventV2Detail $eventDetail, Member $member): ?float;

    abstract public function getWithdrawLimit(EventV2Detail $eventDetail, Member $member): ?float;
}
