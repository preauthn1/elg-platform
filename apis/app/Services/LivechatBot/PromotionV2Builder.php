<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\LivechatBotPromotionSourceEnum;
use App\Enums\StatusEnum;
use App\Helpers\Common;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Services\EventV2\EventV2Service;
use App\Services\MemberService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Livechat Bot 活動 V2 builder。
 *
 * 從 event_v2 / event_v2_details / event_v2_user_joins 取資料並分流為三大分類：
 * - active：JOIN 狀態的 EventV2UserJoin
 * - claimable：通過 getQualificationIssues 的 EventV2Detail
 * - conditioned：未通過 getQualificationIssues 的 EventV2Detail
 */
class PromotionV2Builder
{
    /**
     * 兩種分類共用的 Detail 列表，避免重複查詢。
     *
     * 使用 spl_object_id($member) 為 key 以避免不同 member 之間的快取互相污染。
     *
     * @var array<int, array<int, EventV2Detail>>
     */
    private array $eligibleDetailCache = [];

    /**
     * @var array<int, array<int, array<int, string>>>
     */
    private array $issuesCache = [];

    public function __construct(
        protected MemberService $memberService,
        protected EventV2Service $eventV2Service,
    ) {}

    /**
     * 進行中（JOIN 狀態）的活動。
     *
     * @param  string|null  $keyword  以 event_v2.name 或 event_v2_detail_langs.name 進行 LIKE 過濾（預設 null = 不過濾）
     * @return array<int, array<string, mixed>>
     */
    public function buildActive(Member $member, ?string $keyword = null): array
    {
        $userJoins = EventV2UserJoin::with(['eventV2Detail.detailLang'])
            ->where('member_id', $member->id)
            ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
            ->get();

        if ($userJoins->isEmpty()) {
            return [];
        }

        $detailIds = $userJoins->pluck('event_v2_detail_id')->unique()->all();

        if ($this->normalizeKeyword($keyword) !== null) {
            $detailIds = $this->filterDetailIdsByKeyword($detailIds, $keyword);
            if ($detailIds === []) {
                return [];
            }
            $userJoins = $userJoins->whereIn('event_v2_detail_id', $detailIds);
        }

        $eventV2Map = $this->loadEventV2MapByDetailIds($detailIds);
        $flowLimitData = $this->memberService->getFlowLimit($member);
        $turnoverProgress = $this->buildTurnoverProgress($flowLimitData);

        $result = [];
        foreach ($userJoins as $userJoin) {
            $detail = $userJoin->eventV2Detail;
            if ($detail === null) {
                continue;
            }

            $eventV2 = $eventV2Map[$detail->event_v2_id] ?? null;
            $title = $this->resolveTitle($detail);

            $reward = Common::numAdd('0', (string) $userJoin->reward, 4);
            $balanceLimit = $userJoin->balance_limit !== null
                ? Common::numAdd('0', (string) $userJoin->balance_limit, 4)
                : null;
            $flowLimit = $userJoin->flow_limit !== null
                ? Common::numAdd('0', (string) $userJoin->flow_limit, 4)
                : null;
            $withdrawLimit = $userJoin->withdraw_limit !== null
                ? Common::numAdd('0', (string) $userJoin->withdraw_limit, 4)
                : null;

            $entry = [
                'promotionSource' => LivechatBotPromotionSourceEnum::V2->value,
                'promotionId' => $detail->id,
                'promotionName' => $eventV2?->name ?? '',
                'promotionTitle' => $title,
                'activeFrom' => $this->toFrontendTime($detail->start_time),
                'activeTo' => $this->toFrontendTime($detail->end_time),
                'applyId' => $userJoin->id,
                'joinStatus' => $this->resolveJoinStatusLabel($userJoin->status),
                'reward' => $reward,
                'balanceLimit' => $balanceLimit,
                'flowLimit' => $flowLimit,
                'withdrawLimit' => $withdrawLimit,
                'turnoverProgress' => $turnoverProgress,
            ];

            $entry['message'] = $this->buildActiveMessage($entry);
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * 可參加的活動（getQualificationIssues 為空）。
     *
     * @param  string|null  $keyword  以 event_v2.name 或 event_v2_detail_langs.name 進行 LIKE 過濾（預設 null = 不過濾）
     * @return array<int, array<string, mixed>>
     */
    public function buildClaimable(Member $member, ?string $keyword = null): array
    {
        $details = $this->loadEligibleDetails($member);
        if ($details === []) {
            return [];
        }

        if ($this->normalizeKeyword($keyword) !== null) {
            $matchedIds = $this->filterDetailIdsByKeyword(array_column($details, 'id'), $keyword);
            if ($matchedIds === []) {
                return [];
            }
            $matchedFlip = array_flip($matchedIds);
            $details = array_values(array_filter(
                $details,
                fn (EventV2Detail $detail): bool => isset($matchedFlip[$detail->id]),
            ));
            if ($details === []) {
                return [];
            }
        }

        $eventV2Map = $this->loadEventV2MapByDetailIds(array_column($details, 'id'));

        $result = [];
        foreach ($details as $detail) {
            $issues = $this->resolveIssues($member, $detail);
            if ($issues !== []) {
                continue;
            }

            $eventV2 = $eventV2Map[$detail->event_v2_id] ?? null;
            $title = $this->resolveTitle($detail);

            $entry = [
                'promotionSource' => LivechatBotPromotionSourceEnum::V2->value,
                'promotionId' => $detail->id,
                'promotionName' => $eventV2?->name ?? '',
                'promotionTitle' => $title,
                'activeFrom' => $this->toFrontendTime($detail->start_time),
                'activeTo' => $this->toFrontendTime($detail->end_time),
                'conditionFormula' => $detail->condition_formula,
                'rewardFormula' => $detail->reward_formula,
                'flowFormula' => $detail->flow_formula,
                'withdrawFormula' => $detail->withdraw_formula,
            ];

            $entry['message'] = $this->buildClaimableMessage($entry);
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * 有條件未滿足的活動（getQualificationIssues 不為空）。
     *
     * @param  string|null  $keyword  以 event_v2.name 或 event_v2_detail_langs.name 進行 LIKE 過濾（預設 null = 不過濾）
     * @return array<int, array<string, mixed>>
     */
    public function buildConditioned(Member $member, ?string $keyword = null): array
    {
        $details = $this->loadEligibleDetails($member);
        if ($details === []) {
            return [];
        }

        if ($this->normalizeKeyword($keyword) !== null) {
            $matchedIds = $this->filterDetailIdsByKeyword(array_column($details, 'id'), $keyword);
            if ($matchedIds === []) {
                return [];
            }
            $matchedFlip = array_flip($matchedIds);
            $details = array_values(array_filter(
                $details,
                fn (EventV2Detail $detail): bool => isset($matchedFlip[$detail->id]),
            ));
            if ($details === []) {
                return [];
            }
        }

        $eventV2Map = $this->loadEventV2MapByDetailIds(array_column($details, 'id'));

        $result = [];
        foreach ($details as $detail) {
            $issues = $this->resolveIssues($member, $detail);
            if ($issues === []) {
                continue;
            }

            $eventV2 = $eventV2Map[$detail->event_v2_id] ?? null;
            $title = $this->resolveTitle($detail);

            $formulas = [
                'conditionFormula' => $detail->condition_formula,
                'rewardFormula' => $detail->reward_formula,
                'flowFormula' => $detail->flow_formula,
                'withdrawFormula' => $detail->withdraw_formula,
            ];

            $entry = [
                'promotionSource' => LivechatBotPromotionSourceEnum::V2->value,
                'promotionId' => $detail->id,
                'promotionName' => $eventV2?->name ?? '',
                'promotionTitle' => $title,
                'formulas' => $formulas,
                'nonQualify' => $issues,
            ];

            $entry['message'] = $this->buildConditionedMessage($entry);
            $result[] = $entry;
        }

        return $result;
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
     * 依 keyword 過濾 detailIds：保留對應 event_v2.name 或多語言 event_v2_detail_langs.name 含 keyword 的 detailId。
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
        $detailToEvent = EventV2Detail::query()
            ->whereIn('id', $detailIds)
            ->pluck('event_v2_id', 'id')
            ->all();

        $eventV2Ids = array_values(array_unique($detailToEvent));
        if ($eventV2Ids === []) {
            return [];
        }

        // 1) 命中 event_v2.name 的活動 → 該活動下所有 detailIds 全部命中
        $matchedEventV2Ids = EventV2::query()
            ->whereIn('id', $eventV2Ids)
            ->where('name', 'like', '%' . $needle . '%')
            ->pluck('id')
            ->all();

        $matchedEventV2IdsFlip = array_flip($matchedEventV2Ids);

        // 2) 命中 event_v2_detail_langs.name 的活動（跨所有 language_id 取 union） → 對應 detailId 命中
        $matchedDetailIdsByLang = EventV2DetailLang::query()
            ->whereIn('event_v2_detail_id', $detailIds)
            ->where('name', 'like', '%' . $needle . '%')
            ->pluck('event_v2_detail_id')
            ->unique()
            ->all();

        $matchedDetailIdsByLangFlip = array_flip($matchedDetailIdsByLang);

        $result = [];
        foreach ($detailToEvent as $detailId => $eventV2Id) {
            if (isset($matchedEventV2IdsFlip[$eventV2Id]) || isset($matchedDetailIdsByLangFlip[$detailId])) {
                $result[] = (int) $detailId;
            }
        }

        return $result;
    }

    /**
     * 載入「有效時間 + status=啟用 + 在會員代理層級內」的 EventV2Detail。
     *
     * @return array<int, EventV2Detail>
     */
    private function loadEligibleDetails(Member $member): array
    {
        $cacheKey = spl_object_id($member);
        if (isset($this->eligibleDetailCache[$cacheKey])) {
            return $this->eligibleDetailCache[$cacheKey];
        }

        $time = Carbon::now()->toDateTimeString();
        $agent = $member->agent;
        $hierarchy = $agent?->hierarchy_array ?? [];

        $query = EventV2Detail::with(['detailLang'])
            ->where('start_time', '<=', $time)
            ->where('end_time', '>=', $time)
            ->where('status', StatusEnum::ENABLE->value);

        if ($hierarchy !== []) {
            $query->whereIn('agent_id', $hierarchy);
        }

        $details = $query->get()->all();

        $this->eligibleDetailCache[$cacheKey] = $details;

        return $details;
    }

    /**
     * 取得 detail 對應的 EventV2 名稱 map。
     *
     * @param  array<int, int>  $detailIds
     * @return array<int, EventV2>
     */
    private function loadEventV2MapByDetailIds(array $detailIds): array
    {
        if ($detailIds === []) {
            return [];
        }

        $eventV2Ids = EventV2Detail::query()
            ->whereIn('id', $detailIds)
            ->pluck('event_v2_id')
            ->unique()
            ->all();

        if ($eventV2Ids === []) {
            return [];
        }

        return EventV2::query()
            ->whereIn('id', $eventV2Ids)
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * 跑一次 getQualificationIssues 並做快取，避免 claimable / conditioned 重複呼叫。
     *
     * @return array<int, string>
     */
    private function resolveIssues(Member $member, EventV2Detail $detail): array
    {
        $memberKey = spl_object_id($member);
        if (isset($this->issuesCache[$memberKey][$detail->id])) {
            return $this->issuesCache[$memberKey][$detail->id];
        }

        try {
            $eventClass = $this->eventV2Service->getEventClass($detail->id);
            $issues = $eventClass->getQualificationIssues($detail, $member);
        } catch (Throwable $e) {
            Log::warning('LivechatBot promotions v2 qualification check failed', [
                'member_id' => $member->id,
                'event_v2_detail_id' => $detail->id,
                'error' => $e->getMessage(),
            ]);
            // 取不到 handler 或執行錯誤 → 視為「條件不明」放入 conditioned
            $issues = [$e->getMessage() !== '' ? $e->getMessage() : 'Unable to evaluate event qualification.'];
        }

        $this->issuesCache[$memberKey][$detail->id] = $issues;

        return $issues;
    }

    /**
     * 從 detailLang 取出活動標題（優先英文 / fallback 第一筆）。
     */
    private function resolveTitle(EventV2Detail $detail): string
    {
        $langs = $detail->detailLang;
        if ($langs === null || $langs->isEmpty()) {
            return '';
        }

        $en = $langs->firstWhere('language_id', EventV2Service::DEFAULT_LANGUAGE_id);

        return $en?->name ?? ($langs->first()->name ?? '');
    }

    /**
     * 將時間轉為 frontend 時區（GMT+8）格式 Y-m-d H:i:s。
     */
    private function toFrontendTime(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return Carbon::parse($datetime)->toFrontendTime()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * 將 EventV2UserJoin.status 轉為 enum case name。
     */
    private function resolveJoinStatusLabel(int $status): string
    {
        $case = EventV2UserJoinStatusEnum::tryFrom($status);

        return $case?->name ?? 'UNKNOWN';
    }

    /**
     * 流水進度字串：reach / required（4 位小數）。
     *
     * @param  array<string, mixed>  $flowLimitData
     */
    private function buildTurnoverProgress(array $flowLimitData): string
    {
        $reach = Common::numAdd('0', (string) ($flowLimitData['reach'] ?? '0'), 4);
        $required = Common::numAdd('0', (string) ($flowLimitData['flow_limit'] ?? '0'), 4);

        return $reach . ' / ' . $required;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private function buildActiveMessage(array $entry): array
    {
        return [
            'Promotion: ' . ($entry['promotionTitle'] !== '' ? $entry['promotionTitle'] : (string) $entry['promotionId']),
            'Apply ID: ' . $entry['applyId'],
            'Status: ' . $entry['joinStatus'],
            'Reward: ' . $entry['reward'],
            'Balance Limit: ' . ($entry['balanceLimit'] ?? 'N/A'),
            'Flow Limit: ' . ($entry['flowLimit'] ?? 'N/A'),
            'Withdraw Limit: ' . ($entry['withdrawLimit'] ?? 'N/A'),
            'Turnover Progress: ' . $entry['turnoverProgress'],
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private function buildClaimableMessage(array $entry): array
    {
        return [
            'Promotion: ' . ($entry['promotionTitle'] !== '' ? $entry['promotionTitle'] : (string) $entry['promotionId']),
            'Condition: ' . ($entry['conditionFormula'] ?? 'N/A'),
            'Reward Formula: ' . ($entry['rewardFormula'] ?? 'N/A'),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<int, string>
     */
    private function buildConditionedMessage(array $entry): array
    {
        $title = $entry['promotionTitle'] !== '' ? $entry['promotionTitle'] : (string) $entry['promotionId'];

        $messages = ['Promotion: ' . $title];
        foreach ((array) $entry['nonQualify'] as $issue) {
            $messages[] = 'Issue: ' . $issue;
        }

        return $messages;
    }
}
