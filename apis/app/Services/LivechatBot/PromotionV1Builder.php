<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\Event\PrizeMemberStatusEnum;
use App\Enums\LivechatBotPromotionSourceEnum;
use App\Enums\OrderStatusEnum;
use App\Helpers\Common;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventInviteRecord;
use App\Models\EventName;
use App\Models\EventProcess;
use App\Models\Member;
use App\Models\Order;
use App\Models\PrizeMember;
use App\Models\VendorHourRecord;
use App\Services\LivechatBot\Concerns\ResolvesV1InviteProgress;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;

/**
 * Livechat Bot 活動 V1 builder。
 *
 * 從 events / event_details / event_processes / prize_members 產生兩大分類：
 * - claimable：PrizeMember 待領取（status = UNAWARD）
 * - conditioned：以「會員當期可參加的 EventDetail」為起手，逐筆計算進度
 *
 * 進度計算 (resolveProgress)：依 action_id 以 match 直接分派到專屬方法，
 * 各 action 從業務語意對應的 source-of-truth 表查詢，不再共用 event_processes：
 * - Deposit 系列 (1, 4, 51-58)：orders / members.first_deposit / prize_members
 * - Bet 系列 (2, 3, 9, 14)：vendor_hour_records
 * - Invite 系列 (15, 16, 60)：event_invite_records / members.first_deposit
 * - Ticket 系列 (5, 7)：lucky_draw_tickets / w_jackpot_tickets
 * - 排行榜 (8, 13)：event_slot_competition_records / event_refresh_ranking_records
 * - INVITE_PLAYER_OFFERS (31)：例外，仍以 event_processes 為準（PHP 層主動寫）
 * - 不適用 conditioned 的 action (10, 11, 12)：明示返回 null
 * - 未知 action：返回 null（不顯示）
 */
class PromotionV1Builder
{
    use ResolvesV1InviteProgress;

    /**
     * @param  string|null  $keyword  以 events.title 或 event_names.title（多語系）進行 LIKE 過濾（預設 null = 不過濾）
     * @return array<int, array<string, mixed>>
     */
    public function buildClaimable(Member $member, ?string $keyword = null): array
    {
        $now = Carbon::now()->toDateTimeString();

        /** @var Collection<int, PrizeMember> $records */
        $records = PrizeMember::query()
            ->where('member_id', $member->id)
            ->where('status', PrizeMemberStatusEnum::UNAWARD->value)
            ->where(function ($query) use ($now) {
                $query->whereNull('expired_time')
                    ->orWhere('expired_time', '>=', $now);
            })
            ->get();

        if ($records->isEmpty()) {
            return [];
        }

        $detailIds = $records->pluck('event_detail_id')->unique()->all();

        if ($this->normalizeKeyword($keyword) !== null) {
            $detailIds = $this->filterDetailIdsByKeyword($detailIds, $keyword);
            if ($detailIds === []) {
                return [];
            }
            $records = $records->whereIn('event_detail_id', $detailIds);
        }

        $eventNameMap = $this->loadEventNameMapByDetailIds($detailIds);

        $result = [];
        foreach ($records as $record) {
            $eventName = $eventNameMap[$record->event_detail_id] ?? '';
            $rewardAmount = Common::numAdd('0', (string) ($record->lottery_point ?? '0'), 4);

            $entry = [
                'promotionSource' => LivechatBotPromotionSourceEnum::V1->value,
                'applyId' => $record->id,
                'promotionName' => $eventName,
                'rewardAmount' => $rewardAmount,
            ];

            $entry['message'] = $this->buildClaimableMessage($entry);
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param  string|null  $keyword  以 events.title 或 event_names.title（多語系）進行 LIKE 過濾（預設 null = 不過濾）
     * @return array<int, array<string, mixed>>
     */
    public function buildConditioned(Member $member, ?string $keyword = null): array
    {
        $details = $this->loadEligibleDetails($member, $keyword);
        if ($details->isEmpty()) {
            return [];
        }

        $eventNameMap = $this->loadEventNameMapByDetailIds(
            $details->pluck('id')->all(),
        );

        $result = [];
        foreach ($details as $detail) {
            // action 15（NEW_INVITE_PLAYER_OFFERS）走專屬組裝路徑：主 progress 為「第一個未達標的
            // 成就維度」（見 resolveInviteProgressSet）——純邀請未達標時純邀請為主、續儲掛
            // additionalProgress；純邀請已滿但續儲未達標時改以續儲為主（活動不消失）；所有存在維度
            // 皆達標則隱藏。其餘 action（含 60 TPOWER_INVITE_PLAYER）維持 generic 路徑。
            if ((int) $detail->action_id === EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value) {
                $entry = $this->buildInviteConditionedEntry($member, $detail, $eventNameMap[$detail->id] ?? '');
                if ($entry !== null) {
                    $result[] = $entry;
                }

                continue;
            }

            $progress = $this->resolveProgress($member, $detail);
            if ($progress === null) {
                continue;
            }

            [$current, $required] = $progress;
            if ($required > 0 && $current >= $required) {
                continue;
            }

            $remaining = max($required - $current, 0);
            $actionEnum = EventActionIdEnum::tryFrom((int) $detail->action_id);
            $actionLabel = $actionEnum?->name ?? ('ACTION_' . $detail->action_id);

            $message = $this->resolveActionMessage(
                actionEnum: $actionEnum,
                current: $current,
                required: $required,
                remaining: $remaining,
            );

            $entry = [
                'promotionSource' => LivechatBotPromotionSourceEnum::V1->value,
                'promotionName' => $eventNameMap[$detail->id] ?? '',
                'nonQualify' => [$message],
                'progress' => [
                    'actionType' => $actionLabel,
                    'actionId' => (int) $detail->action_id,
                    'requiredPoint' => $required,
                    'currentPoint' => $current,
                    'remaining' => $remaining,
                ],
                '__eventId' => (int) $detail->event_id,
            ];

            $entry['message'] = $this->buildConditionedMessage($entry);
            $result[] = $entry;
        }

        // 防禦性 dedup：理論上同 event_id 同時段不應有多筆 active detail，
        // 但若 DB 真有多筆，取 currentPoint 最高的代表。
        $byEvent = [];
        foreach ($result as $entry) {
            $eid = $entry['__eventId'];
            if (
                !isset($byEvent[$eid])
                || $entry['progress']['currentPoint'] > $byEvent[$eid]['progress']['currentPoint']
            ) {
                $byEvent[$eid] = $entry;
            }
        }

        return array_values(array_map(function (array $entry) {
            unset($entry['__eventId']);

            return $entry;
        }, $byEvent));
    }

    /**
     * 載入會員「當期可參加」的 EventDetail。
     *
     * 對齊 EventService::getEventList 的資格濾鏡：
     * - event.status=1、agent_id 屬於會員代理線、未被 eventExcludeAgents 排除
     * - event_detail.status=1 且 now 落在 start_time/end_time 區間
     * - prize.status=1 且 expire_date 未到
     *
     * @return Collection<int, EventDetail>
     */
    private function loadEligibleDetails(Member $member, ?string $keyword): Collection
    {
        $hierarchy = $member->agent?->hierarchy_array ?? [];
        if ($hierarchy === []) {
            return new Collection;
        }

        $now = Carbon::now();

        $query = EventDetail::query()
            ->with([
                'event.prize' => fn ($q) => $q->where('status', 1)->where('expire_date', '>', $now),
                'event.prize.prizeContents',
            ])
            ->where('event_details.status', 1)
            ->where('event_details.start_time', '<=', $now)
            ->where('event_details.end_time', '>=', $now)
            ->whereHas('event', function ($eventQuery) use ($hierarchy, $now) {
                $eventQuery
                    ->where('status', 1)
                    ->whereIn('agent_id', $hierarchy)
                    ->whereDoesntHave('eventExcludeAgents', fn ($q) => $q->whereIn('agent_id', $hierarchy))
                    ->whereHas('prize', fn ($q) => $q
                        ->where('status', 1)
                        ->where('expire_date', '>', $now)
                    );
            });

        if ($this->normalizeKeyword($keyword) !== null) {
            $detailIds = $query->pluck('id')->all();
            $matchedIds = $this->filterDetailIdsByKeyword($detailIds, $keyword);
            if ($matchedIds === []) {
                return new Collection;
            }
            $query = EventDetail::query()->with([
                'event.prize' => fn ($q) => $q->where('status', 1)->where('expire_date', '>', $now),
                'event.prize.prizeContents',
            ])->whereIn('id', $matchedIds);
        }

        /** @var Collection<int, EventDetail> $details */
        $details = $query->get();

        return $details;
    }

    /**
     * 依 action_id 分派到對應的進度計算 resolver。
     *
     * 回傳 [current, required]；null 表示「此 action 對此會員無進度資料」（不顯示為 conditioned）。
     *
     * 後續 commit 會逐步用專屬 resolver 取代 default 分支（避免 EventProcess 依賴）。
     */
    private function resolveProgress(Member $member, EventDetail $detail): ?array
    {
        return match ((int) $detail->action_id) {
            // 首儲：4, 51, 53
            EventActionIdEnum::FIRST_DEPOSIT->value,
            EventActionIdEnum::FIRST_DEPOSIT_AUTO->value,
            EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value, => $this->resolveFirstDepositProgress($member, $detail),

            // 每日領取：55, 56（UTC+8 當日視窗）
            EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
            EventActionIdEnum::PICK_UP_ONCE_A_DAY_AUTO->value, => $this->resolveDailyDepositProgress($member, $detail),

            // 累計儲值：1, 52, 54 / 週期儲值簡化為同累計：57, 58
            // TODO 週期儲值之 example.invalid 週期定義與級距規則待後續實作
            EventActionIdEnum::DEPOSIT->value,
            EventActionIdEnum::DEPOSIT_AUTO->value,
            EventActionIdEnum::DEPOSIT_MANUAL->value,
            EventActionIdEnum::DEPOSIT_CYCLE_TIME_AUTO->value,
            EventActionIdEnum::DEPOSIT_CYCLE_TIME->value, => $this->resolveCumulativeDepositProgress($member, $detail),

            // Bet 系列：vendor_hour_records 聚合，差在欄位與正負號
            EventActionIdEnum::BET->value => $this->resolveBetSum($member, $detail, 'bet'),
            EventActionIdEnum::WIN->value => $this->resolveBetSum($member, $detail, 'win'),
            EventActionIdEnum::SLOT_LOSE_REBATE->value => $this->resolveBetSum($member, $detail, 'loss'),

            // 每日下注 boolean：今天有任何下注 → 已達標（被排除），否則回 0/1 形成 conditioned
            EventActionIdEnum::BET_ONCE_A_DAY->value => $this->resolveBetTodayExists($member),

            // 邀請計數：15 / 60 共用 — 計 event_invite_records 中 inviter 的有效邀請數。
            // 註：action 15 於 buildConditioned 已改走 buildInviteConditionedEntry 專屬路徑，
            // 不再經此 arm；此處保留供未來其他 caller 與 action 60 使用（無害）。
            EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            EventActionIdEnum::TPOWER_INVITE_PLAYER->value, => $this->resolveInviteCount($member, $detail),

            // 16：邀請的會員須完成首儲（在活動視窗內）才計入
            EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value => $this->resolveInviteFirstDepositCount($member, $detail),

            // 彩券型：未開獎的 draw 上會員持票數為當前進度
            EventActionIdEnum::LOTTERY->value => $this->resolveLotteryTickets($member, $detail),
            EventActionIdEnum::W_JACKPOT->value => $this->resolveWJackpotTickets($member, $detail),

            // 排行榜型：當期未派發排行榜上，會員的累計 bet_amount
            EventActionIdEnum::SLOT_MACHINE_COMPETITION->value => $this->resolveCompetitionBetAmount($member, $detail),
            EventActionIdEnum::REFRESH_RANKING->value => $this->resolveRefreshRankingBetAmount($member, $detail),

            // 以下 action 不適合「進度條」語意 — 註冊獎為 boolean、JACKPOT 與 LOGIN
            // 在 codebase 找不到實作（probably production 也無 EventDetail 配置）→ 一律返回 null
            // 表示「不顯示為 conditioned」。
            EventActionIdEnum::REG_BONUS->value,
            EventActionIdEnum::JACKPOT->value,
            EventActionIdEnum::LOGIN->value, => null,

            // 31 INVITE_PLAYER_OFFERS：PHP 層（EventService::updateEventProcess 與
            // SP_payout_event_invite.php）仍主動 +1 累加 event_processes.point，並依此
            // 派彩。改寫成從 event_invite_records 重新計數可能與後台 received 不一致，
            // 故維持 EventProcess 為 source of truth。
            EventActionIdEnum::INVITE_PLAYER_OFFERS->value => $this->resolveFromEventProcess($member, $detail),

            // 未知或未支援的 action_id → 不顯示為 conditioned（不再讓未明示行為的
            // EventProcess 取值漏出來），避免顯示無法解讀的進度。
            default => null,
        };
    }

    /**
     * 首儲類：
     * - 已在活動開始前首儲過 → 不符合資格（返回 null）
     * - 尚未首儲 → 進度為 0
     * - 首儲落在活動視窗內 → 取該首儲訂單 point 當進度（若未達門檻才會出現於 conditioned）
     */
    private function resolveFirstDepositProgress(Member $member, EventDetail $detail): ?array
    {
        $required = (int) $detail->point;
        $firstDeposit = $member->first_deposit;

        if ($firstDeposit === null) {
            return [0, $required];
        }

        $firstDepositAt = Carbon::parse($firstDeposit);
        $startTime = Carbon::parse($detail->start_time);

        // 首儲早於活動開始 → member 屬於活動定義的「舊用戶」，不應出現在 conditioned
        if ($firstDepositAt->lt($startTime)) {
            return null;
        }

        $firstDepositOrder = Order::query()
            ->where('member_id', $member->id)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->where('pay_time', $firstDeposit)
            ->when(
                $detail->order_type_id !== null && $detail->order_type_id !== 0,
                fn ($q) => $q->where('order_type_id', $detail->order_type_id),
            )
            ->first();

        $current = $firstDepositOrder !== null ? (int) $firstDepositOrder->point : 0;

        return [$current, $required];
    }

    /**
     * 累計儲值類：活動視窗內所有完成儲值單的 point 加總。
     * order_type_id 若 detail 有指定（非 null / 非 0）才限制。
     */
    private function resolveCumulativeDepositProgress(Member $member, EventDetail $detail): ?array
    {
        $required = (int) $detail->point;

        $sum = Order::query()
            ->where('member_id', $member->id)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->where('pay_time', '>=', $detail->start_time)
            ->where('pay_time', '<=', $detail->end_time)
            ->when(
                $detail->order_type_id !== null && $detail->order_type_id !== 0,
                fn ($q) => $q->where('order_type_id', $detail->order_type_id),
            )
            ->sum('point');

        return [(int) $sum, $required];
    }

    /**
     * 每日領取類（55, 56）：以 UTC+8 當日為視窗，與活動視窗取交集。
     * 若會員今日已有對應 prize_member（已領取）→ 不顯示。
     */
    private function resolveDailyDepositProgress(Member $member, EventDetail $detail): ?array
    {
        $required = (int) $detail->point;

        $todayStart = Carbon::now()->toFrontendTime()->startOfDay()->toSystemTime();
        $todayEnd = Carbon::now()->toFrontendTime()->endOfDay()->toSystemTime();

        $windowStart = $todayStart->copy()->max(Carbon::parse($detail->start_time));
        $windowEnd = $todayEnd->copy()->min(Carbon::parse($detail->end_time));

        if ($windowStart->gt($windowEnd)) {
            return null;
        }

        $alreadyClaimedToday = PrizeMember::query()
            ->where('member_id', $member->id)
            ->where('event_detail_id', $detail->id)
            ->whereBetween('created_at', [$windowStart, $windowEnd])
            ->exists();

        if ($alreadyClaimedToday) {
            return null;
        }

        $sum = Order::query()
            ->where('member_id', $member->id)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->whereBetween('pay_time', [$windowStart, $windowEnd])
            ->when(
                $detail->order_type_id !== null && $detail->order_type_id !== 0,
                fn ($q) => $q->where('order_type_id', $detail->order_type_id),
            )
            ->sum('point');

        return [(int) $sum, $required];
    }

    /**
     * Bet 系列共用：以 vendor_hour_records 聚合會員於活動視窗內的數據。
     *
     * - $kind='bet'  → SUM(bet)（action BET=2）
     * - $kind='win'  → SUM(win_lose where >0)（action WIN=3）
     * - $kind='loss' → ABS(SUM(win_lose where <0))（action SLOT_LOSE_REBATE=9，
     *                  使用 VHR 而非 summary_bet_records，意味著 bot 的進度可能
     *                  比後台 SP_payout_slot_lose_rebate 早幾分鐘，可接受誤差）
     *
     * detail 的 vendor_id / game_id 若有指定才限制；game_type_id / game_join_event_used
     * 等更細的篩選暫不支援（TODO 後續可補）。
     */
    private function resolveBetSum(Member $member, EventDetail $detail, string $kind): ?array
    {
        $required = (int) $detail->point;

        $query = VendorHourRecord::query()
            ->where('member_id', $member->id)
            ->where('date_hour', '>=', $detail->start_time)
            ->where('date_hour', '<=', $detail->end_time)
            ->when(
                $detail->vendor_id !== null && $detail->vendor_id !== 0,
                fn ($q) => $q->where('vendor_id', $detail->vendor_id),
            )
            ->when(
                $detail->game_id !== null && $detail->game_id !== 0,
                fn ($q) => $q->where('game_id', $detail->game_id),
            );

        $sum = match ($kind) {
            'bet' => (float) $query->sum('bet'),
            'win' => (float) $query->where('win_lose', '>', 0)->sum('win_lose'),
            'loss' => abs((float) $query->where('win_lose', '<', 0)->sum('win_lose')),
        };

        return [(int) $sum, $required];
    }

    /**
     * BET_ONCE_A_DAY (14)：今天（UTC+8）有任何下注 → 視為已達標。
     * 不依賴 detail.point，固定 required=1（boolean 性質）。
     */
    private function resolveBetTodayExists(Member $member): array
    {
        $todayStart = Carbon::now()->toFrontendTime()->startOfDay()->toSystemTime();
        $todayEnd = Carbon::now()->toFrontendTime()->endOfDay()->toSystemTime();

        $hasBet = VendorHourRecord::query()
            ->where('member_id', $member->id)
            ->whereBetween('date_hour', [$todayStart, $todayEnd])
            ->where('bet', '>', 0)
            ->exists();

        return [$hasBet ? 1 : 0, 1];
    }

    /**
     * 組裝 action 15（NEW_INVITE_PLAYER_OFFERS）的 conditioned entry。
     *
     * 主 progress 為「第一個未達標的成就維度」（見 resolveInviteProgressSet）：純邀請未達標時
     * 純邀請為主、續儲掛 additionalProgress；純邀請已滿但續儲未達標時改以續儲為主，避免活動因
     * 純邀請滿級而連同未達標的續儲維度一起消失；所有存在維度皆達標時 primary 為 null（隱藏）。
     *
     * 相容性：有 level-0 且無 level-1 時與既有回應逐位元組相容；有 level-1 時會新增
     * additionalProgress（刻意，且更貼近舊系統 foreach 同列 level-0/level-1）。
     *
     * @return array<string, mixed>|null
     */
    private function buildInviteConditionedEntry(Member $member, EventDetail $detail, string $promotionName): ?array
    {
        $set = $this->resolveInviteProgressSet($detail, $member);
        $primary = $set['primary'];

        if ($primary === null) {
            // 所有存在的維度都已達標 → 隱藏。
            return null;
        }

        if ($primary['requiredPoint'] > 0 && $primary['currentPoint'] >= $primary['requiredPoint']) {
            // point fallback（無任何 tier）已達標 → 隱藏。
            return null;
        }

        $entry = [
            'promotionSource' => LivechatBotPromotionSourceEnum::V1->value,
            'promotionName' => $promotionName,
            'nonQualify' => [$this->resolveInviteNonQualifyMessage($primary)],
            'progress' => $primary,
            '__eventId' => (int) $detail->event_id,
        ];

        if ($set['additional'] !== []) {
            $entry['additionalProgress'] = $set['additional'];
        }

        $entry['message'] = $this->buildConditionedMessage($entry);

        return $entry;
    }

    /**
     * 解析 action 15 的主 progress 與附加維度集合（方案乙：所有「存在的維度」皆達標才隱藏，
     * primary = 第一個未達標的維度）。
     *
     * 設 isUnmet(dim) = requiredPoint > 0 && currentPoint < requiredPoint：
     * 1. 純邀請存在（有 level-0 tier）且未達標 → primary = 純邀請；additional = 續儲維度（有 level-1 時）。
     *    玩家仍在邀人階段：輸出與既有相同。
     * 2. 續儲維度存在且未達標 → primary = 續儲維度；additional = []
     *    （涵蓋「只有 level-1」與「level-0 已滿級、level-1 未滿」兩種情況，活動不消失）。
     * 3. 完全無 tier（無 level-0 也無 level-1）→ primary = 純邀請 point fallback；additional = []
     *    （交由 buildInviteConditionedEntry 的達標過濾決定是否顯示）。
     * 4. 所有存在的維度都已達標 → primary = null（呼叫端隱藏）。
     *
     * @return array{primary: array<string, mixed>|null, additional: array<int, array<string, mixed>>}
     */
    private function resolveInviteProgressSet(EventDetail $detail, Member $member): array
    {
        $inviteCount = count($this->filteredInvitedMemberIds($detail, $member));
        $pureRequired = $this->resolveInviteRequired($detail, $inviteCount);

        $pure = [
            'actionType' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->name,
            'actionId' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            'requiredPoint' => $pureRequired,
            'currentPoint' => $inviteCount,
            'remaining' => max($pureRequired - $inviteCount, 0),
        ];

        $level0Exists = !$this->inviteTiers($detail, 0)->isEmpty();
        $depositDimension = $this->resolveInviteDepositDimension($detail, $member);

        $isUnmet = static fn (array $dimension): bool => $dimension['requiredPoint'] > 0
            && $dimension['currentPoint'] < $dimension['requiredPoint'];

        // 1) 純邀請存在且未達標 → 純邀請為主、續儲為附（玩家還在邀人階段，輸出不變）。
        if ($level0Exists && $isUnmet($pure)) {
            return [
                'primary' => $pure,
                'additional' => $depositDimension !== null ? [$depositDimension] : [],
            ];
        }

        // 2) 續儲存在且未達標 → 續儲為主（涵蓋 only-level-1 與 level-0 已滿級兩種情況）。
        if ($depositDimension !== null && $isUnmet($depositDimension)) {
            return ['primary' => $depositDimension, 'additional' => []];
        }

        // 3) 完全沒有 tier → 純邀請 point fallback 當 primary，交由達標過濾決定顯示與否。
        if (!$level0Exists && $depositDimension === null) {
            return ['primary' => $pure, 'additional' => []];
        }

        // 4) 所有存在的維度都已達標 → 隱藏。
        return ['primary' => null, 'additional' => []];
    }

    /**
     * 主 progress 的 nonQualify 訊息：依 actionId 取對應模板。
     * 純邀請（15）沿用 NEW_INVITE_PLAYER_OFFERS 模板（與既有逐位元組相同）；
     * 續儲為主（16）改用 INVITE_PLAYER_DEPOSIT 模板，避免誤用純邀請模板。
     *
     * @param  array<string, mixed>  $primary
     */
    private function resolveInviteNonQualifyMessage(array $primary): string
    {
        $actionEnum = EventActionIdEnum::tryFrom((int) $primary['actionId']);

        return $this->resolveActionMessage(
            actionEnum: $actionEnum,
            current: (int) $primary['currentPoint'],
            required: (int) $primary['requiredPoint'],
            remaining: (int) $primary['remaining'],
        );
    }

    /**
     * 邀請有效計數（action 15 / 60）。
     *
     * 計數條件對齊舊系統 EventNewInviteController::getNewInvitePrizeCnt：
     * - 依 event block 旗標**動態**套用重複過濾（旗標=0 時不排除對應 duplicate）：
     *   ip_block→ip_duplicate=0、phone_block→phone_duplicate=0、
     *   account_block→realname_duplicate=0（真實姓名重複）、tgid_block→telegram_id_duplicate=0
     * - realname_block=1 → 要求被邀請人（member_id）realname_validate=1
     * - 限活動時間窗（created_at 落在 start_time/end_time 之間）
     *
     * 注意採用非 global 的 block 旗標（ip_block/phone_block/account_block/tgid_block/realname_block）。
     */
    private function resolveInviteCount(Member $member, EventDetail $detail): array
    {
        $count = count($this->filteredInvitedMemberIds($detail, $member));

        return [$count, $this->resolveInviteRequired($detail, $count)];
    }

    /**
     * 解析 action 15（NEW_INVITE_PLAYER_OFFERS）的 level-1「邀請並充值」附加維度。
     *
     * 演算法對齊舊系統 EventNewInviteController::getNewInvitePrizeCnt 的 `level == 1` 分支：
     * 取「被邀請者」中，其活動窗內首筆完成訂單（status=3、created_at ∈ [start,end)）
     * 達標者的人數。達標門檻比較的是 `orders.point`（平台點數 = amount×rate − tax），
     * 對齊舊系統 `o.point >= event.point` 與 `event_details.point`（達標點數）語意；
     * **不可**用 `orders.amount`（外幣面額，rate≠1 或 tax>0 時與 point 發散）。
     * 門檻沿用 `event_details.point`（即 detail.point，於本維度語意為「最低充值點數」而非邀請人數）。
     *
     * 門檻（requiredPoint）取 level-1 的 achieve_people 級距，規則 A：尚未達成的最低一級
     * （大於 current 的最小級），全達成時回最高級。活動無 level-1 prize 時回 null，
     * 呼叫端據此**不輸出 additionalProgress**，維持與既有回應逐位元組相容。
     *
     * @return array{actionType: string, actionId: int, requiredPoint: int, currentPoint: int, remaining: int, depositThreshold: int}|null
     */
    private function resolveInviteDepositDimension(EventDetail $detail, Member $member): ?array
    {
        $tiers = $this->inviteTiers($detail, 1);
        if ($tiers->isEmpty()) {
            return null;
        }

        // 與 resolveInviteCount 重複的查詢（同一 action-15 detail 對 event_invite_records 查兩次）。
        // 已知 minor：僅在活動有 level-1 prize 時才觸發、查詢走索引、成本低，故不重構共用
        // （抽離會弄髒 resolveProgress / resolveInviteCount 的 level-0 抽象）。
        $invitedIds = $this->filteredInvitedMemberIds($detail, $member);

        $current = 0;
        if ($invitedIds !== []) {
            // 子查詢：每位被邀請者於活動窗內、status=3 的「首筆」訂單 id（MIN(id)）。
            $firstOrders = Order::query()
                ->whereIn('member_id', $invitedIds)
                ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
                ->where('created_at', '>=', $detail->start_time)
                ->where('created_at', '<', $detail->end_time)
                ->groupBy('member_id')
                ->selectRaw('member_id, MIN(id) as first_id');

            // 外層：首單「點數」達門檻者人數（比 orders.point 對齊舊系統 o.point >= event.point，
            // 非 orders.amount；joinSub 自動併入子查詢 bindings，無裸 DB::raw 拼接）。
            $current = Order::query()
                ->joinSub($firstOrders, 't', fn ($join) => $join->on('orders.id', '=', 't.first_id'))
                ->where('orders.point', '>=', $detail->point)
                ->count();
        }

        $required = $tiers->first(fn (int $tier) => $tier > $current) ?? (int) $tiers->last();

        return [
            'actionType' => 'INVITE_PLAYER_DEPOSIT',
            'actionId' => EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value,
            'requiredPoint' => $required,
            'currentPoint' => $current,
            'remaining' => max($required - $current, 0),
            'depositThreshold' => (int) $detail->point,
        ];
    }

    /**
     * 邀請會員首儲計數（action 16 INVITE_PLAYER_DEPOSIT）：
     * 該 inviter 邀請的會員中，於活動視窗內完成首儲的數量。
     * 不檢查 *_duplicate flags — 是否計入由「該被邀請會員真有首儲」決定。
     */
    private function resolveInviteFirstDepositCount(Member $member, EventDetail $detail): array
    {
        $required = (int) $detail->point;

        $invitedIds = EventInviteRecord::query()
            ->where('event_id', $detail->event_id)
            ->where('invite_member_id', $member->id)
            ->pluck('member_id')
            ->all();

        if ($invitedIds === []) {
            return [0, $required];
        }

        $count = Member::query()
            ->whereIn('id', $invitedIds)
            ->whereNotNull('first_deposit')
            ->where('first_deposit', '>=', $detail->start_time)
            ->where('first_deposit', '<=', $detail->end_time)
            ->count();

        return [$count, $required];
    }

    /**
     * LOTTERY (5)：統計會員在此 event_detail 下、尚未開獎的 lucky_draws 上持有的彩券數。
     * 已開派完獎（status=1）與已取消（status=4）的 draw 排除。
     * lucky_draw_tickets / lucky_draws 沒對應 Eloquent model — 走 DB::table 聚合（允許情境）。
     */
    private function resolveLotteryTickets(Member $member, EventDetail $detail): array
    {
        $required = (int) $detail->point;

        $count = DB::table('lucky_draw_tickets')
            ->join('lucky_draws', 'lucky_draws.id', '=', 'lucky_draw_tickets.lucky_draw_id')
            ->where('lucky_draws.event_detail_id', $detail->id)
            ->whereIn('lucky_draws.status', [0, 2, 3]) // 未開獎 / 已開未派 / 派獎中
            ->where('lucky_draw_tickets.member_id', $member->id)
            ->count();

        return [$count, $required];
    }

    /**
     * W_JACKPOT (7)：同 LOTTERY，table 改為 w_jackpot_tickets / w_jackpots。
     */
    private function resolveWJackpotTickets(Member $member, EventDetail $detail): array
    {
        $required = (int) $detail->point;

        $count = DB::table('w_jackpot_tickets')
            ->join('w_jackpots', 'w_jackpots.id', '=', 'w_jackpot_tickets.w_jackpot_id')
            ->where('w_jackpots.event_detail_id', $detail->id)
            ->whereIn('w_jackpots.status', [0, 2, 3])
            ->where('w_jackpot_tickets.member_id', $member->id)
            ->count();

        return [$count, $required];
    }

    /**
     * SLOT_MACHINE_COMPETITION (8)：當期未派發的競賽上，會員累計投注額為當前進度。
     * 排行榜型本質是相對排名，但對 bot 顯示「再投注 X 元」這類話術，
     * 用 detail.point 當作達標門檻就足夠。
     */
    private function resolveCompetitionBetAmount(Member $member, EventDetail $detail): array
    {
        $required = (int) $detail->point;

        $current = (float) DB::table('event_slot_competition_records')
            ->join(
                'event_slot_competitions',
                'event_slot_competitions.id',
                '=',
                'event_slot_competition_records.event_slot_competition_id',
            )
            ->where('event_slot_competitions.event_detail_id', $detail->id)
            ->whereIn('event_slot_competitions.status', [0, 2]) // 未派獎 / 派獎中
            ->where('event_slot_competition_records.member_id', $member->id)
            ->sum('event_slot_competition_records.bet_amount');

        return [(int) $current, $required];
    }

    /**
     * REFRESH_RANKING (13)：當期未派發的刷新排行榜上，會員 is_calc=1 的累計投注額。
     */
    private function resolveRefreshRankingBetAmount(Member $member, EventDetail $detail): array
    {
        $required = (int) $detail->point;

        $current = (float) DB::table('event_refresh_ranking_records')
            ->join(
                'event_refresh_rankings',
                'event_refresh_rankings.id',
                '=',
                'event_refresh_ranking_records.event_refresh_ranking_id',
            )
            ->where('event_refresh_rankings.event_detail_id', $detail->id)
            ->where('event_refresh_rankings.status', 0) // 未派發
            ->where('event_refresh_ranking_records.member_id', $member->id)
            ->where('event_refresh_ranking_records.is_calc', 1) // 1: 計算 (排除電話/IP 重複)
            ->sum('event_refresh_ranking_records.bet_amount');

        return [(int) $current, $required];
    }

    /**
     * EventProcess fallback：從 event_processes 取 point 作為當前進度。
     *
     * 目前唯一 caller 為 INVITE_PLAYER_OFFERS (31) — PHP 層的
     * EventService::updateEventProcess 仍以此為累計計數，不切換 source 以避免
     * 與後台 SP_payout_event_invite 的 received 計數脫鉤。
     */
    private function resolveFromEventProcess(Member $member, EventDetail $detail): ?array
    {
        $process = EventProcess::query()
            ->where('member_id', $member->id)
            ->where('detail_id', $detail->id)
            ->where('status', 0)
            ->first();

        if ($process === null) {
            return null;
        }

        return [(int) $process->point, (int) $detail->point];
    }

    /**
     * 將 keyword 正規化（去頭尾空白後若為空字串則回傳 null）。
     */
    private function normalizeKeyword(?string $keyword): ?string
    {
        if ($keyword === null) {
            return null;
        }
        $trimmed = trim($keyword);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * 依 keyword 過濾 detailIds：保留對應 event 的 title 或多語言 name 包含 keyword 的 detailId。
     *
     * @param  array<int, int>  $detailIds
     * @return array<int, int>
     */
    private function filterDetailIdsByKeyword(array $detailIds, ?string $keyword): array
    {
        $needle = $this->normalizeKeyword($keyword);
        if ($needle === null || $detailIds === []) {
            return $detailIds;
        }

        /** @var array<int, int> $detailToEvent */
        $detailToEvent = EventDetail::query()
            ->whereIn('id', $detailIds)
            ->pluck('event_id', 'id')
            ->all();

        $eventIds = array_values(array_unique($detailToEvent));
        if ($eventIds === []) {
            return [];
        }

        $matchedByTitle = Event::query()
            ->whereIn('id', $eventIds)
            ->where('title', 'like', '%' . $needle . '%')
            ->pluck('id')
            ->all();

        $matchedByName = EventName::query()
            ->whereIn('event_id', $eventIds)
            ->where('title', 'like', '%' . $needle . '%')
            ->pluck('event_id')
            ->all();

        $matchedEventIds = array_unique(array_merge($matchedByTitle, $matchedByName));
        if ($matchedEventIds === []) {
            return [];
        }

        $matchedEventIdsFlip = array_flip($matchedEventIds);

        $result = [];
        foreach ($detailToEvent as $detailId => $eventId) {
            if (isset($matchedEventIdsFlip[$eventId])) {
                $result[] = (int) $detailId;
            }
        }

        return $result;
    }

    /**
     * 一次取得 detail_id → event.title 的對應 map，避免迴圈內 N+1 查詢。
     *
     * @param  array<int, int>  $detailIds
     * @return array<int, string>
     */
    private function loadEventNameMapByDetailIds(array $detailIds): array
    {
        if ($detailIds === []) {
            return [];
        }

        $detailToEvent = EventDetail::query()
            ->whereIn('id', $detailIds)
            ->pluck('event_id', 'id')
            ->all();

        $eventIds = array_values(array_unique($detailToEvent));
        if ($eventIds === []) {
            return [];
        }

        /** @var array<int, Event> $events */
        $events = Event::query()
            ->whereIn('id', $eventIds)
            ->get()
            ->keyBy('id')
            ->all();

        $map = [];
        foreach ($detailToEvent as $detailId => $eventId) {
            $event = $events[$eventId] ?? null;
            if ($event === null) {
                continue;
            }

            // Prize.name 可能比 Event.title 更貼近活動名稱，但 events.title 為必有欄位故優先使用
            $map[$detailId] = (string) ($event->title ?? '');
        }

        return $map;
    }

    /**
     * 取得本地化的 action 進度訊息。
     */
    private function resolveActionMessage(
        ?EventActionIdEnum $actionEnum,
        int $current,
        int $required,
        int $remaining,
    ): string {
        $key = $actionEnum?->name ?? 'default';
        $langKey = 'livechat_bot_event.' . $key;

        if (!Lang::has($langKey)) {
            $langKey = 'livechat_bot_event.default';
        }

        return __($langKey, [
            'current' => (string) $current,
            'required' => (string) $required,
            'remaining' => (string) $remaining,
        ]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private function buildClaimableMessage(array $entry): array
    {
        return [
            'Promotion: ' . ($entry['promotionName'] !== '' ? $entry['promotionName'] : 'Promotion #' . $entry['applyId']),
            'Apply ID: ' . $entry['applyId'],
            'Reward Amount: ' . $entry['rewardAmount'],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private function buildConditionedMessage(array $entry): array
    {
        $title = $entry['promotionName'] !== '' ? $entry['promotionName'] : 'V1 Promotion';
        $messages = ['Promotion: ' . $title];

        foreach ((array) $entry['nonQualify'] as $issue) {
            $messages[] = 'Issue: ' . $issue;
        }

        $progress = $entry['progress'];
        // 主 progress 可能是「邀請並充值」續儲維度（只有 level-1 的活動）：帶 depositThreshold
        // 時用續儲格式（含 min deposit），否則用純邀請／一般格式（與既有逐位元組相同）。
        if (isset($progress['depositThreshold'])) {
            $messages[] = sprintf(
                'Progress: %s %d / %d (%d remaining, min deposit %d points)',
                $progress['actionType'],
                $progress['currentPoint'],
                $progress['requiredPoint'],
                $progress['remaining'],
                $progress['depositThreshold'],
            );
        } else {
            $messages[] = sprintf(
                'Progress: %s %d / %d (%d remaining)',
                $progress['actionType'],
                $progress['currentPoint'],
                $progress['requiredPoint'],
                $progress['remaining'],
            );
        }

        // 附加維度（目前僅 level-1 邀請並充值）：沿用 Progress 格式並帶出充值門檻。
        foreach ((array) ($entry['additionalProgress'] ?? []) as $dimension) {
            $messages[] = sprintf(
                'Progress: %s %d / %d (%d remaining, min deposit %d points)',
                $dimension['actionType'],
                $dimension['currentPoint'],
                $dimension['requiredPoint'],
                $dimension['remaining'],
                $dimension['depositThreshold'],
            );
        }

        return $messages;
    }
}
