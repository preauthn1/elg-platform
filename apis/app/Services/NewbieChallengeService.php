<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\EventV2ListItemData;
use App\Data\NewbieChallengeQualifyingDepositData;
use App\Data\UserData;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\FlowLimitStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeBlockJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeBlockTypeEnum;
use App\Enums\NewbieChallenge\NewbieChallengeOperatorTypeEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\StatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Helpers\Traits\DistributedLock;
use App\Models\Agent;
use App\Models\FlowLimit;
use App\Models\FlowLimitRestriction;
use App\Models\Game;
use App\Models\Member;
use App\Models\NewbieChallengeBlockJoin;
use App\Models\NewbieChallengeBlockRecord;
use App\Models\NewbieChallengeConfig;
use App\Models\NewbieChallengeOperationLog;
use App\Models\NewbieChallengeProgress;
use App\Models\Order;
use App\Models\TransactionLog;
use App\Models\Vendor;
use App\Models\WalletTransactionLog;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NewbieChallengeService
{
    use DistributedLock;

    /** event/list 的 tp_event 固定代號 */
    private const int TP_EVENT_CODE = 1;

    public function __construct(
        private WalletService $walletService,
    ) {}

    public function join(Member $member): NewbieChallengeProgress
    {
        $config = $this->getActiveConfig($member->parent);
        if ($config === null) {
            throw new ApiException(__('newbie_challenge.validation.not_available')); // 新人挑戰活動目前未開放
        }

        // restrict_new_member=1（預設）時，只有活動開始後才註冊的玩家能參加；start_time 未設定
        // 代表活動沒有明確開始時間可比較，視同不限制
        if ($config->restrict_new_member === StatusEnum::ENABLE->value
            && $config->start_time !== null
            && $member->created_at->lessThan($config->start_time)
        ) {
            throw new ApiException(__('newbie_challenge.validation.registered_before_start')); // 僅活動開始後註冊的玩家可參加
        }

        if ((int) $member->realname_validate === 0) {
            throw new ApiException(__('newbie_challenge.validation.kyc_not_verified')); // 尚未完成實名認證
        }

        if ($member->is_event_v2) {
            throw new ApiException(__('newbie_challenge.validation.participating_in_another_event')); // 已參加其他活動，無法同時參加
        }

        if (NewbieChallengeProgress::where('member_id', $member->id)->exists()) {
            throw new ApiException(__('newbie_challenge.validation.already_joined')); // 已經參加過了
        }

        return $this->withDuplicateBlockLock($member, $config, function () use ($member, $config) {
            $this->checkDuplicateBlock($member, $config);

            return $this->createProgress($member, $config);
        });
    }

    /**
     * 依 config 啟用的封鎖維度(IP/手機/真實姓名)組出分散式鎖鍵，依固定順序(IP→手機→姓名)逐一取鎖後
     * 才執行 $callback，避免共用同一 IP/手機/姓名的不同會員同時 join() 併發繞過防重複機制；
     * 固定取鎖順序避免不同請求以相反順序取鎖造成死鎖。
     */
    private function withDuplicateBlockLock(Member $member, NewbieChallengeConfig $config, callable $callback): mixed
    {
        $keys = [];

        if ($config->ip_block === StatusEnum::ENABLE->value) {
            $keys[] = 'newbie_challenge_join_ip:' . ip2long(Common::getIp());
        }

        if ($config->phone_block === StatusEnum::ENABLE->value && !empty($member->mobile)) {
            $keys[] = 'newbie_challenge_join_phone:' . $member->mobile;
        }

        if ($config->account_block === StatusEnum::ENABLE->value && !empty($member->first_name)) {
            $keys[] = 'newbie_challenge_join_account:' . $member->first_name;
        }

        $wrapped = array_reduce(
            array_reverse($keys),
            fn (callable $next, string $key) => fn () => $this->withLock($key, 10, $next, 5, 'System busy, please try again'),
            $callback
        );

        return $wrapped();
    }

    private function createProgress(Member $member, NewbieChallengeConfig $config): NewbieChallengeProgress
    {
        return DB::transaction(function () use ($member, $config) {
            $lockedMember = Member::lockForUpdate()->find($member->id);

            if ($lockedMember->is_event_v2) {
                throw new ApiException(__('newbie_challenge.validation.participating_in_another_event')); // 已參加其他活動，無法同時參加
            }

            if (NewbieChallengeProgress::where('member_id', $lockedMember->id)->lockForUpdate()->exists()) {
                throw new ApiException(__('newbie_challenge.validation.already_joined')); // 已經參加過了
            }

            // 活動金入活動錢包（WalletEnum::CODE_EVENT_WALLET），不動主錢包
            // 直接設成應得金額，不用累加：避免退出前一個活動後若有殘留餘額，被誤算進這次的活動金
            $wallet = $this->walletService->getLockedMemberWallet($lockedMember->id, WalletEnum::CODE_EVENT_WALLET);
            $walletBalance = $config->activity_amount;
            $wallet->balance = $walletBalance;
            $wallet->save();

            $walletTransactionLog = WalletTransactionLog::create([
                'transaction_no' => Common::getTransactionNo(true),
                'agent_id' => $lockedMember->parent,
                'member_id' => $lockedMember->id,
                'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
                'vendor_id' => 0,
                'order_id' => 0,
                'point' => $config->activity_amount,
                'tax' => 0,
                'vendor_point' => 0,
                'rate' => 0,
                'before_balance' => 0,
                'balance' => $walletBalance,
                'type' => WalletTransactionEnum::EVENT_WALLET_JOIN->value,
                'operator' => 'system',
                'remark' => '新人專享挑戰-活動金',
                'checksum' => '',
                'result' => '',
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'admin' => 0,
            ]);

            $challengeFlowTarget = Common::numMul($config->activity_amount, $config->challenge_flow_multiple, 4);

            $flowLimit = FlowLimit::create([
                'member_id' => $lockedMember->id,
                'type' => TransactionEnum::NEWBIE_CHALLENGE_CHALLENGE_FLOW->value,
                'event_id' => 0, // 保留給活動 v1 使用，不可挪用；優先權判斷改走 type
                'reference_id' => 0,
                'wallet_transaction_log_id' => $walletTransactionLog->id,
                'flow_limit' => $challengeFlowTarget,
                'admin' => 0,
            ]);

            FlowLimitRestriction::insert(
                $this->buildFlowLimitRestrictions(
                    $flowLimit->id,
                    $this->resolveEffectiveVendorIds($config->allowed_vendor_ids, $config->excluded_vendor_ids, $this->activeVendorIds(isFreeOnly: true)),
                    $config->allowed_game_type_ids,
                )
            );

            $progress = NewbieChallengeProgress::create([
                'config_id' => $config->id,
                'member_id' => $lockedMember->id,
                'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
                'activity_reward' => $config->activity_amount,
                'challenge_flow_limit_id' => $flowLimit->id,
                'joined_at' => now(),
            ]);

            NewbieChallengeOperationLog::create([
                'progress_id' => $progress->id,
                'operator_type' => NewbieChallengeOperatorTypeEnum::MEMBER->value,
                'operator_id' => $lockedMember->id,
                'action' => 'join',
                'from_stage' => null,
                'to_stage' => NewbieChallengeStageEnum::CHALLENGING->value,
                'remark' => null,
            ]);

            // 消耗掉後台放行的重複阻擋紀錄（若有）：回填 progress_id 讓下次重複阻擋事件另開新的
            // block_join，而不是誤沿用這筆已經放行過的舊紀錄
            NewbieChallengeBlockJoin::where('config_id', $config->id)
                ->where('member_id', $lockedMember->id)
                ->whereNull('progress_id')
                ->update([
                    'progress_id' => $progress->id,
                    'status' => NewbieChallengeBlockJoinStatusEnum::JOINED->value,
                ]);

            return $progress;
        });
    }

    /**
     * 限制／排除遊戲商互斥：限制有值直接採用；限制空、排除有值則換算成「候選池扣掉排除清單」的等效
     * 限制清單餵給既有邏輯（flow_limit_restrictions／SP_calc_flow_limit 完全不用改，天生只認「包含」
     * 語意）；兩者皆空則不限制(null)。互斥由 Request 層擋，這裡不重複檢查。
     *
     * @param  list<int>  $vendorPool  排除模式換算補集時的候選池；第一階段後台只能從免盤費遊戲商挑選
     *                                 （isFreeOnly=true），第二階段為全部啟用中遊戲商
     * @return list<int>|null
     */
    private function resolveEffectiveVendorIds(?array $allowedVendorIds, ?array $excludedVendorIds, array $vendorPool): ?array
    {
        if (filled($allowedVendorIds)) {
            return $allowedVendorIds;
        }

        if (filled($excludedVendorIds)) {
            return array_values(array_diff($vendorPool, $excludedVendorIds));
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function activeVendorIds(bool $isFreeOnly = false): array
    {
        return Vendor::where('status', StatusEnum::ENABLE->value)
            ->when($isFreeOnly, fn ($query) => $query->where('is_free', 1))
            ->pluck('id')
            ->all();
    }

    /**
     * 遊戲商、遊戲類型是兩個獨立多選（皆可為null=不限制），但 flow_limit_restrictions 一列是
     * (vendor_id, type_id) 一組、SP_calc_flow_limit 逐列AND比對，所以要展開成笛卡兒積：
     * 「這些遊戲商 x 這些遊戲類型」任一組合都算流水。任一維度未設定就補一個0(萬用)，
     * 維持「不限制」的既有語意不變。
     *
     * @return list<array{flow_limit_id: int, vendor_id: int, type_id: int}>
     */
    private function buildFlowLimitRestrictions(int $flowLimitId, ?array $vendorIds, ?array $gameTypeIds): array
    {
        $vendorIds = !empty($vendorIds) ? $vendorIds : [0];
        $gameTypeIds = !empty($gameTypeIds) ? $gameTypeIds : [0];

        $restrictions = [];
        foreach ($vendorIds as $vendorId) {
            foreach ($gameTypeIds as $gameTypeId) {
                $restrictions[] = [
                    'flow_limit_id' => $flowLimitId,
                    'vendor_id' => (int) $vendorId,
                    'type_id' => (int) $gameTypeId,
                ];
            }
        }

        return $restrictions;
    }

    /**
     * event/list 用：組出新人挑戰的列表項目。訪客（無 member）一樣可見，供未登入時的行銷曝光；
     * 會員只有終態（已完成/已放棄）才不再出現在列表中，進行中的各階段（含待領取彩金/彩金任務中）
     * 仍要顯示，讓會員可以隨時回到列表點回活動頁繼續。排序直接吃後台可設定的 config->sort，
     * 跟活動 v1/v2（events.sort/event_v2_details.sort）同一套欄位、同一個排序空間，
     * 不再寫死永遠排最前面。
     */
    public function getEventListItems(UserData $userData): Collection
    {
        $agent = $userData->member?->agent ?? $userData->agent;
        if ($agent === null) {
            return collect();
        }

        $config = $this->getActiveConfig($agent->id);
        if ($config === null) {
            return collect();
        }

        if ($userData->member !== null
            && NewbieChallengeProgress::where('member_id', $userData->member->id)
                ->whereIn('stage', [
                    NewbieChallengeStageEnum::BONUS_COMPLETED->value,
                    NewbieChallengeStageEnum::QUIT->value,
                    NewbieChallengeStageEnum::OPERATOR_QUIT->value,
                ])
                ->exists()
        ) {
            return collect();
        }

        $configLangs = $config->configLangs()
            ->whereIn('language_id', [$userData->languageId, 1])
            ->get();
        $lang = $configLangs->firstWhere('language_id', $userData->languageId)
            ?? $configLangs->firstWhere('language_id', 1);

        if ($lang === null) {
            return collect();
        }

        return collect([
            EventV2ListItemData::from([
                'event_v1_id' => null,
                'event_v2_id' => null,
                'name' => $lang->name,
                'image' => $lang->image,
                'params' => null,
                'game_type_ids' => [],
                'sort' => $config->sort,
                'created_at' => $config->created_at?->toDateTimeString(),
                'end_time' => $config->end_time?->toDateTimeString(),
                'tp_event' => self::TP_EVENT_CODE,
            ]),
        ]);
    }

    /**
     * 參加頁的活動介紹內容（不含會員參加狀態，狀態由 getProgress() 另外查）。
     */
    public function getDetail(int $agentId): NewbieChallengeConfig
    {
        $config = $this->getActiveConfig($agentId);
        if ($config === null) {
            throw new ApiException(__('newbie_challenge.validation.not_available')); // 新人挑戰活動目前未開放
        }

        return $config->load('configLangs');
    }

    /**
     * 參加頁「可參與遊戲商」顯示用：與 resolveEffectiveVendorIds() 同一套換算，但限制與排除皆空
     * （不限制）時攤開成完整候選池清單回傳，而不是 null——這裡是顯示給會員看的名單，不是餵給
     * flow_limit_restrictions 的限制條件，null 在這裡沒有「不限制」的語意可以表達。
     *
     * @return list<int>
     */
    public function resolveAllowedVendorIds(NewbieChallengeConfig $config): array
    {
        $pool = $this->activeVendorIds(isFreeOnly: true);

        return $this->resolveEffectiveVendorIds($config->allowed_vendor_ids, $config->excluded_vendor_ids, $pool) ?? $pool;
    }

    /**
     * 大廳遊戲商列表限制用：會員正在第二階段（挑戰流水）用 allowed_vendor_ids、第四階段（彩金流水）
     * 用 bonus_flow_vendor_ids（兩者皆經 resolveEffectiveVendorIds() 換算排除清單），只回傳當下
     * 階段計算範圍內的遊戲商，導流玩家玩到有算進該階段流水的遊戲；限制與排除皆空，或其他階段
     * （含未參加）都不限制，回傳 null。
     */
    public function getLobbyVendorRestriction(Member $member): ?array
    {
        $progress = NewbieChallengeProgress::with('config')
            ->where('member_id', $member->id)
            ->whereIn('stage', [NewbieChallengeStageEnum::CHALLENGING->value, NewbieChallengeStageEnum::BONUS_WAGERING->value])
            ->first();

        if ($progress === null) {
            return null;
        }

        return $progress->stage === NewbieChallengeStageEnum::CHALLENGING
            ? $this->resolveEffectiveVendorIds($progress->config->allowed_vendor_ids, $progress->config->excluded_vendor_ids, $this->activeVendorIds(isFreeOnly: true))
            : $this->resolveEffectiveVendorIds($progress->config->bonus_flow_vendor_ids, $progress->config->excluded_bonus_flow_vendor_ids, $this->activeVendorIds());
    }

    /**
     * 大廳遊戲清單灰化用：跟 getLobbyVendorRestriction() 同樣只在第二階段(CHALLENGING)/
     * 第四階段(BONUS_WAGERING)期間生效，直接讀當下這筆流水鎖實際寫入的 flow_limit_restrictions
     * （即 SP_calc_flow_limit 真正拿去算流水的那份資料，不是重新推一次 config 的
     * allowed_vendor_ids/allowed_game_type_ids，避免跟實際限制的資料來源不一致）。
     *
     * 獨立於 FlowLimitService::isGameDisabled()（該服務只認 EventV2/活動v1鎖分模式），
     * 新人挑戰不同的判斷邏輯歸自己這支 service，呼叫端 OR 起來合併判斷即可。
     */
    public function isGameDisabled(Game $game, ?Member $member): bool
    {
        if ($member === null) {
            return false;
        }

        $progress = NewbieChallengeProgress::where('member_id', $member->id)
            ->whereIn('stage', [NewbieChallengeStageEnum::CHALLENGING->value, NewbieChallengeStageEnum::BONUS_WAGERING->value])
            ->first();

        if ($progress === null) {
            return false;
        }

        $flowLimitId = $progress->stage === NewbieChallengeStageEnum::CHALLENGING
            ? $progress->challenge_flow_limit_id
            : $progress->bonus_flow_limit_id;

        if ($flowLimitId === null) {
            return false;
        }

        $restrictions = FlowLimitRestriction::where('flow_limit_id', $flowLimitId)->get(['vendor_id', 'type_id']);

        if ($restrictions->isEmpty()) {
            return false;
        }

        $matched = $restrictions->contains(
            fn ($restriction) => in_array((int) $restriction->vendor_id, [0, $game->vendor_id], true)
                && in_array((int) $restriction->type_id, [0, $game->type_id], true)
        );

        return !$matched;
    }

    public function getProgress(Member $member): NewbieChallengeProgress
    {
        $progress = NewbieChallengeProgress::with(['challengeFlowLimit', 'bonusFlowLimit', 'config'])
            ->where('member_id', $member->id)
            ->first();

        if ($progress === null) {
            throw new ApiException(__('newbie_challenge.validation.not_joined')); // 尚未參加新人挑戰活動
        }

        $progress->principal_total = $this->calculatePrincipalTotal($member->id, $progress->challenge_completed_at);

        // 前端「下一步」按鈕是否可按：只在待領取彩金階段判斷，跟 confirmDeposit() 共用同一套查詢邏輯
        $progress->canConfirmDeposit = $progress->stage === NewbieChallengeStageEnum::BONUS_PENDING
            && $this->findQualifyingDeposit($member, $progress) !== null;

        // 流水鎖是否已滿足放行條件：達標，或後台已取消稽核（見 isFlowLimitSatisfied()），
        // 跟 unlockBonus()/claimBonus() 共用同一套判斷，避免這裡顯示「未達標」但按鈕點下去卻成功的不一致
        $progress->isChallengeFlowSatisfied = $this->isFlowLimitSatisfied($progress->challengeFlowLimit);
        $progress->isBonusFlowSatisfied = $this->isFlowLimitSatisfied($progress->bonusFlowLimit);

        $progress->eventWalletBalance = $this->walletService->queryMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET)?->balance ?? '0';
        $progress->mainWalletBalance = $member->balance;

        return $progress;
    }

    /**
     * 即時算「挑戰完成後累積入金」，不維護累加欄位、不需要任何背景觸發——本金總額本來就等於這段期間
     * 已上分訂單金額的總和，直接查表加總即可，比事件驅動增量累加簡單也不會有漏算風險。
     * getProgress() 拿來即時顯示；claimBonus() 拿來算本金鎖流水目標，同時把算出來的值存回
     * principal_total 當作撥款當下的歷史快照；Agent 後台的 progress-list/progress-detail
     * 也共用這個方法即時顯示，不能只讀 DB 的 principal_total 欄位（BONUS_COMPLETED 前該欄位固定是 0）。
     */
    public function calculatePrincipalTotal(int $memberId, ?Carbon $challengeCompletedAt): string
    {
        if ($challengeCompletedAt === null) {
            return '0';
        }

        $sum = Order::where('member_id', $memberId)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->where('pay_time', '>=', $challengeCompletedAt)
            ->sum('point');

        return Common::numAdd('0', (string) $sum, 4);
    }

    /**
     * 待領取彩金階段，會員在倒數頁按「下一步」主動觸發：查「挑戰完成後、彩金視窗內、達最低解鎖門檻」
     * 的第一筆已上分訂單，有則核銷彩金、開彩金流水、轉入彩金任務中；查無符合資格的儲值則明確回錯誤。
     * 不掛三方回調 hook：自助上分等非三方回調管道是走舊版 API_Agent 自己的 CashFlowService，
     * 跟 apis 完全是兩條不相干的程式碼路徑，PaymentService::updateMemberBalance() 永遠不會被呼叫到，
     * 沒有統一的 hook 點可以掛；orders.status=DEPOSIT_FINISHED 是新舊兩套系統共用同一張表、同一個
     * 數值，直接查表判斷「錢是否真的到帳」，讓所有儲值管道用同一套邏輯、同一個時機（會員主動確認）
     * 處理，不會有玩家沒感知到的背景狀態變化。只適用 BONUS_PENDING，其他階段回錯誤。
     */
    public function confirmDeposit(Member $member): NewbieChallengeProgress
    {
        return DB::transaction(function () use ($member) {
            $progress = NewbieChallengeProgress::with(['config', 'challengeFlowLimit', 'bonusFlowLimit'])
                ->where('member_id', $member->id)
                ->lockForUpdate()
                ->first();

            if ($progress === null) {
                throw new ApiException(__('newbie_challenge.validation.not_joined')); // 尚未參加新人挑戰活動
            }

            if ($progress->stage !== NewbieChallengeStageEnum::BONUS_PENDING) {
                throw new ApiException(__('newbie_challenge.validation.bonus_not_pending')); // 目前不是待領取彩金階段
            }

            $this->claimQualifyingBonus($progress, $member);

            return $progress;
        });
    }

    /**
     * BONUS_PENDING 階段的 confirmDeposit()：查無符合資格的儲值則明確回錯誤，讓前端可以提示玩家。
     */
    private function claimQualifyingBonus(NewbieChallengeProgress $progress, Member $member): void
    {
        if ($progress->bonus_window_expire_at !== null && now()->greaterThan($progress->bonus_window_expire_at)) {
            throw new ApiException(__('newbie_challenge.validation.bonus_window_expired')); // 彩金領取時間已過期
        }

        $deposit = $this->findQualifyingDeposit($member, $progress);

        if ($deposit === null) {
            throw new ApiException(__('newbie_challenge.validation.no_qualifying_deposit')); // 查無符合資格的儲值紀錄
        }

        $this->claimBonusFromDeposit($progress, $member, $deposit);
    }

    /**
     * 查「彩金視窗內、達最低解鎖門檻」的第一筆已上分訂單，視窗已過期或查無符合資格訂單一律回 null。
     * confirmDeposit() 用來實際核銷；getProgress() 用來算 can_confirm_deposit 旗標（前端按鈕是否可按），
     * 共用同一套判斷邏輯避免「按鈕顯示可按、一按卻失敗」的兩套邏輯不同步問題。
     *
     * target/tickets#4352：真實訂單全不合格時，若代理組別設置開啟
     * AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT，退而求其次看同一視窗內的
     * 後台開分（transaction_logs type=DEPOSIT，無對應 orders 列），跟活動 V2 同一套開關共用。
     */
    private function findQualifyingDeposit(Member $member, NewbieChallengeProgress $progress): ?NewbieChallengeQualifyingDepositData
    {
        if ($progress->bonus_window_expire_at !== null && now()->greaterThan($progress->bonus_window_expire_at)) {
            return null;
        }

        $config = $progress->config;

        $order = Order::where('member_id', $member->id)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->whereBetween('pay_time', [$progress->challenge_completed_at, $progress->bonus_window_expire_at])
            ->where('point', '>=', $config->min_unlock_amount)
            ->orderBy('pay_time')
            ->first();

        if ($order !== null) {
            return new NewbieChallengeQualifyingDepositData(id: $order->id, point: (string) $order->point, is_backend_deposit: false);
        }

        if (!$member->agent->getSettingValue(AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT)) {
            return null;
        }

        $backendDeposit = TransactionLog::where('member_id', $member->id)
            ->where('type', TransactionEnum::DEPOSIT->value)
            ->whereBetween('created_at', [$progress->challenge_completed_at, $progress->bonus_window_expire_at])
            ->where('point', '>=', $config->min_unlock_amount)
            ->orderBy('created_at')
            ->first();

        if ($backendDeposit === null) {
            return null;
        }

        return new NewbieChallengeQualifyingDepositData(id: $backendDeposit->id, point: (string) $backendDeposit->point, is_backend_deposit: true);
    }

    /**
     * 挑戰流水達標後，會員按按鈕觸發：結算可解鎖彩金、切換到待領取彩金階段。
     * 若當下活動錢包餘額仍低於最低門檻，維持 CHALLENGING、不清零、不記錄失敗，
     * 讓會員可以繼續遊玩，餘額回到門檻以上時再呼叫本方法即可解鎖（放棄活動走 quit() 另計）。
     */
    public function unlockBonus(Member $member): NewbieChallengeProgress
    {
        return DB::transaction(function () use ($member) {
            $progress = NewbieChallengeProgress::with(['config', 'challengeFlowLimit'])
                ->where('member_id', $member->id)
                ->lockForUpdate()
                ->first();

            if ($progress === null) {
                throw new ApiException(__('newbie_challenge.validation.not_joined')); // 尚未參加新人挑戰活動
            }

            if ($progress->stage !== NewbieChallengeStageEnum::CHALLENGING) {
                throw new ApiException(__('newbie_challenge.validation.challenge_not_in_progress')); // 目前不是挑戰進行中階段
            }

            $flowLimit = FlowLimit::where('id', $progress->challenge_flow_limit_id)->lockForUpdate()->first();
            if (!$this->isFlowLimitSatisfied($flowLimit)) {
                throw new ApiException(__('newbie_challenge.validation.challenge_flow_limit_not_reached')); // 挑戰流水尚未達標
            }

            $config = $progress->config;

            // 可解鎖彩金 = 活動錢包剩餘餘額（規格明訂，不用注單反推）
            $wallet = $this->walletService->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);
            $unlockedBonusAmount = $wallet->balance;

            // 餘額未達門檻：維持 CHALLENGING、活動錢包不動，讓會員可以繼續遊玩，之後再呼叫本方法重新判斷
            if (Common::numCompare($unlockedBonusAmount, $config->min_unlock_amount) < 0) {
                throw new ApiException(__('newbie_challenge.validation.unlocked_bonus_below_minimum')); // 可解鎖彩金尚未達最低門檻，請繼續遊玩
            }

            if (Common::numCompare($unlockedBonusAmount, $config->max_unlock_amount) > 0) {
                $unlockedBonusAmount = (string) $config->max_unlock_amount;
            }

            // 活動錢包歸零：這筆餘額已轉換成 unlocked_bonus_amount 額度憑證，錢包本身的任務結束
            $beforeBalance = $wallet->balance;
            $wallet->balance = 0;
            $wallet->save();

            WalletTransactionLog::create([
                'transaction_no' => Common::getTransactionNo(true),
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
                'vendor_id' => 0,
                'order_id' => 0,
                'point' => $beforeBalance,
                'tax' => 0,
                'vendor_point' => 0,
                'rate' => 0,
                'before_balance' => $beforeBalance,
                'balance' => 0,
                'type' => WalletTransactionEnum::EVENT_WALLET_INIT->value,
                'operator' => 'system',
                'remark' => '新人專享挑戰-挑戰完成活動錢包歸零',
                'checksum' => '',
                'result' => '',
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'admin' => 0,
            ]);

            $fromStage = $progress->stage->value;

            $progress->stage = NewbieChallengeStageEnum::BONUS_PENDING;
            $progress->unlocked_bonus_amount = $unlockedBonusAmount;
            $progress->bonus_window_expire_at = now()->addHours((int) $config->bonus_window_hours);
            $progress->challenge_completed_at = now();
            $progress->save();

            NewbieChallengeOperationLog::create([
                'progress_id' => $progress->id,
                'operator_type' => NewbieChallengeOperatorTypeEnum::MEMBER->value,
                'operator_id' => $member->id,
                'action' => 'challenge_completed',
                'from_stage' => $fromStage,
                'to_stage' => NewbieChallengeStageEnum::BONUS_PENDING->value,
                'remark' => '可解鎖彩金 ' . $unlockedBonusAmount,
            ]);

            return $progress;
        });
    }

    /**
     * 彩金流水達標後，會員按按鈕觸發：把核銷的彩金撥入真金錢包(主錢包)，轉入彩金任務完成階段。
     */
    public function claimBonus(Member $member): NewbieChallengeProgress
    {
        return DB::transaction(function () use ($member) {
            $progress = NewbieChallengeProgress::with(['bonusFlowLimit', 'config'])
                ->where('member_id', $member->id)
                ->lockForUpdate()
                ->first();

            if ($progress === null) {
                throw new ApiException(__('newbie_challenge.validation.not_joined')); // 尚未參加新人挑戰活動
            }

            if ($progress->stage !== NewbieChallengeStageEnum::BONUS_WAGERING) {
                throw new ApiException(__('newbie_challenge.validation.bonus_flow_not_in_progress')); // 目前不是彩金流水任務階段
            }

            $flowLimit = FlowLimit::where('id', $progress->bonus_flow_limit_id)->lockForUpdate()->first();
            if (!$this->isFlowLimitSatisfied($flowLimit)) {
                throw new ApiException(__('newbie_challenge.validation.bonus_flow_limit_not_reached')); // 彩金流水尚未達標
            }

            if ($progress->claimed_bonus_amount === null) {
                throw new ApiException(__('newbie_challenge.validation.bonus_claim_not_found')); // 查無彩金核銷紀錄
            }

            $claimedBonusAmount = (string) $progress->claimed_bonus_amount;

            $lockedMember = Member::lockForUpdate()->find($member->id);
            $beforeBalance = $lockedMember->balance;
            $afterBalance = Common::numAdd($beforeBalance, $claimedBonusAmount, 4);
            $lockedMember->balance = $afterBalance;
            $lockedMember->save();

            TransactionLog::create([
                'transaction_no' => Common::getTransactionNo(),
                'agent_id' => $lockedMember->parent,
                'member_id' => $lockedMember->id,
                'order_id' => $progress->deposit_order_id,
                'point' => $claimedBonusAmount,
                'before_balance' => $beforeBalance,
                'balance' => $afterBalance,
                'type' => TransactionEnum::NEWBIE_CHALLENGE_BONUS_PAYOUT->value,
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'operator' => 'system',
                'remark' => '新人專享挑戰-彩金撥入真金錢包',
                'admin' => 0,
            ]);

            // 活動不建立本金流水鎖：儲值流水風控是平台層 DEPOSIT_FLOW_LIMIT_RATE 機制的職責
            // （見 PaymentService::updateMemberBalance()），活動這邊不重複處理，避免跟平台層疊加成雙倍鎖。
            // principal_total 純粹即時算出這段期間累積入金，存回當作撥款當下的歷史快照，供顯示用。
            $progress->principal_total = $this->calculatePrincipalTotal($member->id, $progress->challenge_completed_at);

            $fromStage = $progress->stage->value;

            $progress->stage = NewbieChallengeStageEnum::BONUS_COMPLETED;
            $progress->bonus_completed_at = now();
            $progress->save();

            NewbieChallengeOperationLog::create([
                'progress_id' => $progress->id,
                'operator_type' => NewbieChallengeOperatorTypeEnum::MEMBER->value,
                'operator_id' => $member->id,
                'action' => 'bonus_flow_completed',
                'from_stage' => $fromStage,
                'to_stage' => NewbieChallengeStageEnum::BONUS_COMPLETED->value,
                'remark' => '彩金 ' . $claimedBonusAmount . ' 撥入真金錢包',
            ]);

            return $progress;
        });
    }

    /**
     * 放棄活動（會員主動、或代理後台強制取消）。彩金撥款(claimBonus)只在彩金流水跑完後才會發生，
     * 所以放棄當下錢一定還沒真的入主錢包——不是活動金還留在活動錢包(CHALLENGING/BONUS_PENDING)，
     * 就是已核銷但未撥款、流水還沒跑完的彩金(BONUS_WAGERING)。回收＝活動錢包歸零＋未完成的流水鎖
     * 取消稽核，不涉及扣會員主錢包餘額。一旦進到 BONUS_COMPLETED(彩金已撥款)，本金流水規則已經跟
     * 正常完成路徑一致，不再允許放棄。
     */
    public function quit(
        Member $member,
        NewbieChallengeOperatorTypeEnum $operatorType = NewbieChallengeOperatorTypeEnum::MEMBER,
        ?int $operatorId = null,
    ): NewbieChallengeProgress {
        return DB::transaction(function () use ($member, $operatorType, $operatorId) {
            $progress = NewbieChallengeProgress::with(['config'])->where('member_id', $member->id)->lockForUpdate()->first();

            if ($progress === null) {
                throw new ApiException(__('newbie_challenge.validation.not_joined')); // 尚未參加新人挑戰活動
            }

            if ($progress->stage === NewbieChallengeStageEnum::BONUS_COMPLETED
                || $progress->stage === NewbieChallengeStageEnum::QUIT
                || $progress->stage === NewbieChallengeStageEnum::OPERATOR_QUIT
            ) {
                throw new ApiException(__('newbie_challenge.validation.cannot_quit_at_current_stage')); // 目前階段無法放棄活動
            }

            $fromStage = $progress->stage->value;

            // 尚未完成的挑戰流水鎖／彩金流水鎖：取消稽核，避免永久卡住提款
            if ($progress->challenge_flow_limit_id !== null) {
                FlowLimit::where('id', $progress->challenge_flow_limit_id)
                    ->where('status', FlowLimitStatusEnum::PENDING->value)
                    ->update(['status' => FlowLimitStatusEnum::CANCELLED->value]);
            }

            if ($progress->bonus_flow_limit_id !== null) {
                FlowLimit::where('id', $progress->bonus_flow_limit_id)
                    ->where('status', FlowLimitStatusEnum::PENDING->value)
                    ->update(['status' => FlowLimitStatusEnum::CANCELLED->value]);
            }

            $this->voidEventWallet($member);

            // 會員自己放棄記 QUIT，代理後台強制取消記 OPERATOR_QUIT——兩者業務效果完全一樣（回收＋
            // 終態不可逆），差別只在誰觸發的，讓後台列表/監控不用另外查操作日誌就看得出來
            $toStage = $operatorType === NewbieChallengeOperatorTypeEnum::MEMBER
                ? NewbieChallengeStageEnum::QUIT
                : NewbieChallengeStageEnum::OPERATOR_QUIT;

            $progress->stage = $toStage;
            $progress->quit_at = now();
            $progress->save();

            NewbieChallengeOperationLog::create([
                'progress_id' => $progress->id,
                'operator_type' => $operatorType->value,
                'operator_id' => $operatorId ?? $member->id,
                'action' => 'quit',
                'from_stage' => $fromStage,
                'to_stage' => $toStage->value,
                'remark' => null,
            ]);

            return $progress;
        });
    }

    /**
     * 活動錢包若還有餘額（尚未經 unlockBonus 轉換成額度憑證），作廢歸零；已是 0 則不動作、不留多餘的歸零紀錄。
     */
    private function voidEventWallet(Member $member): void
    {
        $wallet = $this->walletService->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);

        if (Common::numCompare($wallet->balance, '0') <= 0) {
            return;
        }

        $beforeBalance = $wallet->balance;
        $wallet->balance = 0;
        $wallet->save();

        WalletTransactionLog::create([
            'transaction_no' => Common::getTransactionNo(true),
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'vendor_id' => 0,
            'order_id' => 0,
            'point' => $beforeBalance,
            'tax' => 0,
            'vendor_point' => 0,
            'rate' => 0,
            'before_balance' => $beforeBalance,
            'balance' => 0,
            'type' => WalletTransactionEnum::EVENT_WALLET_INIT->value,
            'operator' => 'system',
            'remark' => '新人專享挑戰-主動放棄活動錢包歸零',
            'checksum' => '',
            'result' => '',
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'admin' => 0,
        ]);
    }

    /**
     * 核銷彩金、開彩金流水、轉入彩金任務中。呼叫端（confirmDeposit()）已篩過最低門檻與視窗未過期，
     * 這裡的門檻檢查是防禦性重複驗證，非唯一把關點。
     */
    private function claimBonusFromDeposit(NewbieChallengeProgress $progress, Member $member, NewbieChallengeQualifyingDepositData $deposit): void
    {
        if ($progress->stage !== NewbieChallengeStageEnum::BONUS_PENDING) {
            return;
        }

        $config = $progress->config;
        $depositAmount = $deposit->point;

        if (Common::numCompare($depositAmount, $config->min_unlock_amount) < 0) {
            return;
        }

        if ($progress->bonus_window_expire_at !== null && now()->greaterThan($progress->bonus_window_expire_at)) {
            return;
        }

        $claimedBonusAmount = Common::numCompare($depositAmount, $progress->unlocked_bonus_amount) > 0
            ? $progress->unlocked_bonus_amount
            : $depositAmount;

        // target/tickets#4352：來源互斥，只有一個會被寫入，另一個維持 null
        if ($deposit->is_backend_deposit) {
            $progress->deposit_transaction_log_id = $deposit->id;
        } else {
            $progress->deposit_order_id = $deposit->id;
        }
        $progress->deposit_amount = $depositAmount;
        $progress->claimed_bonus_amount = $claimedBonusAmount;

        // 彩金流水目標 = 儲值後的主錢包總餘額 × 彩金流水倍數（規格定案，不是只算這筆儲值金額）
        $bonusFlowTarget = Common::numMul((string) $member->balance, $config->bonus_flow_multiple, 4);

        $bonusFlowLimit = FlowLimit::create([
            'member_id' => $member->id,
            'type' => TransactionEnum::NEWBIE_CHALLENGE_BONUS_FLOW->value,
            'event_id' => 0, // 保留給活動 v1 使用，不可挪用；優先權判斷改走 type
            'reference_id' => $deposit->id,
            'flow_limit' => $bonusFlowTarget,
            'admin' => 0,
        ]);

        FlowLimitRestriction::insert(
            $this->buildFlowLimitRestrictions(
                $bonusFlowLimit->id,
                $this->resolveEffectiveVendorIds($config->bonus_flow_vendor_ids, $config->excluded_bonus_flow_vendor_ids, $this->activeVendorIds()),
                $config->bonus_flow_game_type_ids,
            )
        );

        $fromStage = $progress->stage->value;

        $progress->stage = NewbieChallengeStageEnum::BONUS_WAGERING;
        $progress->bonus_flow_limit_id = $bonusFlowLimit->id;
        // principal_total 不在這裡累加：getProgress()／claimBonus() 都是即時查 orders 表算總額，
        // 不維護累加欄位，見 calculatePrincipalTotal()
        $progress->save();

        NewbieChallengeOperationLog::create([
            'progress_id' => $progress->id,
            'operator_type' => NewbieChallengeOperatorTypeEnum::SYSTEM->value,
            'operator_id' => null,
            'action' => 'bonus_claimed',
            'from_stage' => $fromStage,
            'to_stage' => NewbieChallengeStageEnum::BONUS_WAGERING->value,
            'remark' => '核銷彩金 ' . $claimedBonusAmount . '，' . ($deposit->is_backend_deposit ? '後台開分#' : '儲值訂單#') . $deposit->id,
        ]);
    }

    /**
     * 流水鎖是否已滿足放行條件：達標，或後台已將這筆流水鎖「取消稽核」（代理後台的「刪除」按鈕實際是
     * status 改 CANCELLED，不是真的刪除該筆 flow_limits 紀錄，見 API_Agent::MemberController::
     * cancel_flow_limit()）。取消稽核視同流水條件已通過，不應該繼續卡住玩家。
     */
    private function isFlowLimitSatisfied(?FlowLimit $flowLimit): bool
    {
        if ($flowLimit === null) {
            return false;
        }

        return $flowLimit->status === FlowLimitStatusEnum::CANCELLED->value
            || Common::numCompare($flowLimit->reach, $flowLimit->flow_limit) >= 0;
    }

    /**
     * 重複阻擋檢查（IP/手機/真實姓名）。這個會員在這個 config 下若已有後台放行過、還沒被消耗
     * （progress_id 尚未回填）的 block_join，直接跳過本次所有阻擋檢查——後台放行是一次性的，
     * 讓玩家這次一定能 join 過去，不是永久白名單；join 成功後 createProgress() 會回填消耗掉。
     * 手機/姓名為空值時不查，避免多個空值會員互相誤判重複（IP 一定有值不用guard）。
     *
     * 每個維度各自比對兩層（任一命中就擋，比照 EventV2 EnforcesParticipationBlocks 的
     * ALL_MEMBER 分層邏輯，但不加代理階層範疇——這裡刻意維持跟原本一致的全平台範疇）：
     * 1. 已有人用這個 IP/手機/姓名參加過新人挑戰（原本就有的邏輯）
     * 2. 全平台任何「更早註冊」的會員，註冊資料就跟這個 IP/手機/姓名相同（新增）
     */
    private function checkDuplicateBlock(Member $member, NewbieChallengeConfig $config): void
    {
        $blockJoin = NewbieChallengeBlockJoin::where('config_id', $config->id)
            ->where('member_id', $member->id)
            ->whereNull('progress_id')
            ->first();

        if ($blockJoin !== null && $blockJoin->status === NewbieChallengeBlockJoinStatusEnum::ALLOW_JOIN) {
            return;
        }

        if ($config->ip_block === StatusEnum::ENABLE->value && $this->hasIpBlock($member)) {
            $message = __('newbie_challenge.validation.ip_already_joined'); // 此 IP 已有人參加過
            $this->recordBlockAttempt($config, $member, NewbieChallengeBlockTypeEnum::IP, $message);
            throw new ApiException($message);
        }

        if ($config->phone_block === StatusEnum::ENABLE->value && !empty($member->mobile) && $this->hasPhoneBlock($member)) {
            $message = __('newbie_challenge.validation.phone_already_joined'); // 此手機號碼已有人參加過
            $this->recordBlockAttempt($config, $member, NewbieChallengeBlockTypeEnum::PHONE, $message);
            throw new ApiException($message);
        }

        if ($config->account_block === StatusEnum::ENABLE->value && !empty($member->first_name) && $this->hasAccountBlock($member)) {
            $message = __('newbie_challenge.validation.account_already_joined'); // 此姓名已有人參加過
            $this->recordBlockAttempt($config, $member, NewbieChallengeBlockTypeEnum::ACCOUNT, $message);
            throw new ApiException($message);
        }
    }

    /**
     * 記一次重複阻擋事件。同一位會員在同一個 config 下、還沒被後台放行消耗掉的期間內只維護一筆
     * block_join（find-or-create），阻擋原因(block_type)跟上一筆相同時只更新 IP/訊息，不重複
     * 新增紀錄，避免玩家狂 retry 洗記錄；原因換了才新增一筆 block_record 並回填 last_block_record_id。
     */
    private function recordBlockAttempt(
        NewbieChallengeConfig $config,
        Member $member,
        NewbieChallengeBlockTypeEnum $blockType,
        string $message,
    ): void {
        $blockJoin = NewbieChallengeBlockJoin::firstOrCreate(
            ['config_id' => $config->id, 'member_id' => $member->id, 'progress_id' => null],
            ['status' => NewbieChallengeBlockJoinStatusEnum::BLOCKED->value],
        );

        $ip = Common::getIp();
        $lastRecord = $blockJoin->lastBlockRecord;
        if ($lastRecord !== null && $lastRecord->block_type === $blockType) {
            $lastRecord->update(['ip' => $ip, 'message' => $message]);

            return;
        }

        $record = NewbieChallengeBlockRecord::create([
            'block_join_id' => $blockJoin->id,
            'block_type' => $blockType->value,
            'message' => $message,
            'ip' => $ip,
        ]);

        $blockJoin->update(['last_block_record_id' => $record->id]);
    }

    /**
     * IP 重複阻擋，兩層任一命中：(1) 已有人用這個 IP 參加過新人挑戰；(2) 全平台任何更早註冊的
     * 會員，註冊 IP(`register_ip`) 跟這個 IP 相同——比照 EventV2
     * EnforcesParticipationBlocks::detectIpBlockCode() 的 ALL_MEMBER 分層，`register_ip`
     * 同時比對當下請求 IP 跟這位會員自己的註冊 IP（IP 若跳號，用自己的註冊 IP 還是比對得到）。
     */
    private function hasIpBlock(Member $member): bool
    {
        $longIp = ip2long(Common::getIp());

        $joinedBefore = NewbieChallengeProgress::query()
            ->leftJoin('members', 'newbie_challenge_progress.member_id', '=', 'members.id')
            ->where('members.last_login_ip', $longIp)
            ->exists();

        if ($joinedBefore) {
            return true;
        }

        return Member::where('id', '!=', $member->id)
            ->whereIn('register_ip', [$longIp, $member->register_ip])
            ->where('created_at', '<', $member->created_at)
            ->exists();
    }

    /**
     * 手機重複阻擋，兩層任一命中：(1) 已有人用這個手機號參加過新人挑戰；(2) 全平台任何更早綁定
     * 這個手機號、且手機已驗證的會員——比照 EventV2 detectPhoneBlockCode() 的 ALL_MEMBER 分層，
     * 走 phoneBindings 綁定紀錄而不是直接比對 members.mobile 目前值。
     */
    private function hasPhoneBlock(Member $member): bool
    {
        $joinedBefore = NewbieChallengeProgress::query()
            ->leftJoin('members', 'newbie_challenge_progress.member_id', '=', 'members.id')
            ->where('members.mobile', $member->mobile)
            ->exists();

        if ($joinedBefore) {
            return true;
        }

        $phoneBindTime = $member->phoneBindings->max('created_at') ?? $member->created_at;

        return Member::where('id', '!=', $member->id)
            ->where('mobile_validate', 1)
            ->whereHas('phoneBindings', function ($query) use ($member, $phoneBindTime) {
                $query->where('value', $member->mobile)
                    ->where('created_at', '<', $phoneBindTime);
            })
            ->exists();
    }

    /**
     * 真實姓名重複阻擋，兩層任一命中：(1) 已有人用這個姓名參加過新人挑戰；(2) 全平台任何其他
     * 會員的 first_name 相同——比照 EventV2 detectAccountBlockCode() 的 ALL_MEMBER 分層。
     */
    private function hasAccountBlock(Member $member): bool
    {
        $joinedBefore = NewbieChallengeProgress::query()
            ->leftJoin('members', 'newbie_challenge_progress.member_id', '=', 'members.id')
            ->where('members.first_name', $member->first_name)
            ->exists();

        if ($joinedBefore) {
            return true;
        }

        return Member::where('id', '!=', $member->id)
            ->where('first_name', $member->first_name)
            ->exists();
    }

    private function getActiveConfig(int $agentId): ?NewbieChallengeConfig
    {
        $now = now();
        // 排除代理（含下線）：跟 EventV2Service::getEventV2List() 同一套判斷方式，用查詢方（會員/代理）
        // 自己的上級鏈 hierarchy_array 比對是否命中任一排除設定，不用在排除表上額外做 hierarchy_path 比對
        $hierarchyArray = Agent::find($agentId)?->hierarchy_array ?? [$agentId];

        return NewbieChallengeConfig::query()
            ->where('status', StatusEnum::ENABLE->value)
            ->where(function ($query) use ($agentId) {
                $query->where('agent_id', $agentId)->orWhereNull('agent_id');
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('start_time')->orWhere('start_time', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('end_time')->orWhere('end_time', '>=', $now);
            })
            ->whereDoesntHave('configExcludeAgents', function ($query) use ($hierarchyArray) {
                $query->whereIn('agent_id', $hierarchyArray);
            })
            ->orderByRaw('agent_id IS NULL')
            ->first();
    }
}
