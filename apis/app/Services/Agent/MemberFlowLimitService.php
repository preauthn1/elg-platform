<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\FlowLimitStatusEnum;
use App\Enums\FlowLimitTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\CouponBatch;
use App\Models\Event;
use App\Models\FlowLimit;
use App\Models\LoginEvent;
use App\Models\Member;
use App\Models\NewbieChallengeConfigLang;
use App\Models\NewbieChallengeProgress;
use App\Services\Agent\Concerns\ChecksMemberScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 會員稽核流水（flow_limits）列表與取消，由 API_Agent `member/flowLimit`、`member/cancel_flow_limit` 移植（target/tickets#4438）。
 *
 * 必須：
 *   - 一律排除優惠錢包獎勵（type 104），列表、「是否還有未通過」與取消三處同一條件。
 *   - 取消＝狀態改 9、寫備註與操作者；取消全部或取消鎖分模式（type 105）那筆時，會員 event_mode 歸 0。
 *   - 取消先鎖會員列再改流水，同一會員的取消互斥。
 * 與舊接口的差異（刻意）：
 *   - 活動名稱只查當頁用到的 id，不再整批撈全站活動。
 *   - 單筆只能取消「未通過」那筆；舊接口連已通過、已取消的都能再改一次（前端本來就只在未通過列顯示按鈕）。
 * 舊接口保留給舊後台 WEB_AgentBackend，改規則時兩邊要一起改。
 */
class MemberFlowLimitService
{
    use ChecksMemberScope;

    /** 標題取自 events／event_names 的類型（同舊接口清單） */
    private const array EVENT_TITLE_TYPES = [
        TransactionEnum::ACTIVITY_AWARD,
        TransactionEnum::FIRST_DEPOSIT_REWARDS,
        TransactionEnum::DEPOSIT_REWARDS,
        TransactionEnum::PICK_UP_ONCE_A_DAY,
        TransactionEnum::INVITE_PLAYER_OFFERS,
        TransactionEnum::CYCLE_DEPOSIT_EVENT_REWARD,
        TransactionEnum::SLOT_BATTLE,
        TransactionEnum::SHAREHOLDER_REWARD,
    ];

    /** 新人專享挑戰：flow_limits.event_id 固定 0，標題改用會員參加的挑戰設定名稱 */
    private const array NEWBIE_CHALLENGE_TYPES = [
        TransactionEnum::NEWBIE_CHALLENGE_CHALLENGE_FLOW,
        TransactionEnum::NEWBIE_CHALLENGE_BONUS_FLOW,
    ];

    /** members.event_mode：未鎖定 */
    private const int EVENT_MODE_UNLOCKED = 0;

    /**
     * @return array{items: list<array<string, mixed>>, paginator: \Illuminate\Pagination\LengthAwarePaginator<int, FlowLimit>, status_zero_exists: bool}
     *
     * @throws ApiException member not found／member out of scope
     */
    public function list(Agent $operator, int $memberId, int $languageId, int $page, int $perPage): array
    {
        $member = $this->findMemberInScope($operator, $memberId);

        // 多錢包站的獎勵記在 wallet_transaction_logs，主錢包找不到時改取那邊（同舊接口）
        $bonusColumn = $member->agent?->is_multi_wallet ? 'IFNULL(t.point, wt.point)' : 't.point';

        $paginator = $this->memberFlowLimits($member->id)
            ->leftJoin('transaction_logs as t', 't.id', 'flow_limits.reference_id')
            ->leftJoin('wallet_transaction_logs as wt', 'wt.id', 'flow_limits.wallet_transaction_log_id')
            ->select([
                'flow_limits.id',
                'flow_limits.type',
                'flow_limits.event_id',
                'flow_limits.flow_limit',
                'flow_limits.reach',
                'flow_limits.status',
                'flow_limits.remark',
                'flow_limits.created_at',
                'flow_limits.updated_at',
            ])
            ->selectRaw("{$bonusColumn} as bonus")
            ->orderByDesc('flow_limits.id')
            ->paginate(perPage: $perPage, page: $page);

        /** @var Collection<int, FlowLimit> $rows */
        $rows = collect($paginator->items());
        $titles = $this->titles($rows, $member->id, $languageId);

        return [
            'items' => $rows->map(static fn (FlowLimit $row): array => [
                'id' => (int) $row->id,
                'type' => (int) $row->type,
                'event_id' => (int) $row->event_id,
                'title' => $titles[$row->id] ?? '',
                'bonus' => Common::numAdd('0', (string) ($row->getAttribute('bonus') ?? '0'), 4),
                'flow_limit' => Common::numAdd('0', (string) $row->flow_limit, 4),
                'valid_flow_limit' => Common::numAdd('0', (string) $row->reach, 4),
                'status' => (int) $row->status,
                'status_text' => __('param.flow_limit_status.' . $row->status),
                'remark' => $row->remark,
                'created_at' => $row->created_at?->toBackendTime()->toDateTimeString(),
                'updated_at' => $row->updated_at?->toBackendTime()->toDateTimeString(),
            ])->values()->all(),
            'paginator' => $paginator,
            'status_zero_exists' => $this->memberFlowLimits($member->id)
                ->where('status', FlowLimitStatusEnum::PENDING->value)
                ->exists(),
        ];
    }

    /**
     * 取消該會員全部未通過的稽核流水，並解除活動鎖分模式。
     *
     * @throws ApiException member not found／member out of scope／記錄不存在（沒有未通過的流水）
     */
    public function cancelAll(Agent $operator, int $memberId, ?string $remark): void
    {
        // 範疇檢查是一般讀，放交易外；交易內第一個讀是鎖定讀
        $this->findMemberInScope($operator, $memberId);

        DB::transaction(function () use ($operator, $memberId, $remark): void {
            /** @var Member $member */
            $member = Member::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();

            $affected = $this->memberFlowLimits($member->id)
                ->where('status', FlowLimitStatusEnum::PENDING->value)
                ->update($this->cancelledAttributes($operator, $remark));

            if ($affected === 0) {
                throw new ApiException(__('error_message.1010'));
            }

            $member->event_mode = self::EVENT_MODE_UNLOCKED;
            $member->save();
        });
    }

    /**
     * 取消單筆未通過的稽核流水；該筆是活動鎖分模式時一併解除會員 event_mode。
     *
     * @throws ApiException 記錄不存在（查無、已非未通過、或為優惠錢包）／member out of scope
     */
    public function cancelOne(Agent $operator, int $flowLimitId, ?string $remark): void
    {
        $memberId = FlowLimit::query()->whereKey($flowLimitId)->value('member_id');
        if ($memberId === null) {
            throw new ApiException(__('error_message.1010'));
        }

        // 歸屬看該筆流水的會員
        $this->findMemberInScope($operator, (int) $memberId);

        DB::transaction(function () use ($operator, $flowLimitId, $memberId, $remark): void {
            /** @var Member $member */
            $member = Member::query()->whereKey($memberId)->lockForUpdate()->firstOrFail();

            /** @var FlowLimit|null $flowLimit */
            $flowLimit = $this->memberFlowLimits($member->id)
                ->whereKey($flowLimitId)
                ->lockForUpdate()
                ->first();

            if ($flowLimit === null || (int) $flowLimit->status !== FlowLimitStatusEnum::PENDING->value) {
                throw new ApiException(__('error_message.1010'));
            }

            $flowLimit->fill($this->cancelledAttributes($operator, $remark))->save();

            if ((int) $flowLimit->type === FlowLimitTypeEnum::EVENT_LOCK_MODE->value) {
                $member->event_mode = self::EVENT_MODE_UNLOCKED;
                $member->save();
            }
        });
    }

    /**
     * 該會員的稽核流水，排除優惠錢包獎勵（優惠錢包有自己的解鎖流程，不在此列出或取消）。
     *
     * @return Builder<FlowLimit>
     */
    private function memberFlowLimits(int $memberId): Builder
    {
        return FlowLimit::query()
            ->where('flow_limits.member_id', $memberId)
            ->where('flow_limits.type', '!=', TransactionEnum::PROMOTION_WALLET_REWARD->value);
    }

    /** @return array{status: int, remark: string|null, admin: int} */
    private function cancelledAttributes(Agent $operator, ?string $remark): array
    {
        return [
            'status' => FlowLimitStatusEnum::CANCELLED->value,
            'remark' => $remark,
            'admin' => $operator->id,
        ];
    }

    /**
     * 當頁每筆流水的來源名稱（flow_limits.id => 名稱），規則同舊接口：
     *   新人專享挑戰（111／112）→ 挑戰設定名稱；event_id < 0 → 優惠碼批次號；
     *   event_id > 0 且為活動類 → 活動名稱；登入活動（15）→ 登入活動名稱。
     *
     * @param  Collection<int, FlowLimit>  $rows
     * @return array<int, string>
     */
    private function titles(Collection $rows, int $memberId, int $languageId): array
    {
        $eventTypes = array_map(static fn (TransactionEnum $type): int => $type->value, self::EVENT_TITLE_TYPES);
        $newbieTypes = array_map(static fn (TransactionEnum $type): int => $type->value, self::NEWBIE_CHALLENGE_TYPES);

        $eventIds = $rows->filter(fn (FlowLimit $row): bool => $row->event_id > 0 && in_array((int) $row->type, $eventTypes, true))->pluck('event_id');
        $loginEventIds = $rows->filter(fn (FlowLimit $row): bool => $row->event_id > 0 && (int) $row->type === TransactionEnum::LOGIN_EVENT_REWARDS->value)->pluck('event_id');
        $couponBatchIds = $rows->filter(fn (FlowLimit $row): bool => $row->event_id < 0)->map(fn (FlowLimit $row): int => -$row->event_id);
        $hasNewbie = $rows->contains(fn (FlowLimit $row): bool => in_array((int) $row->type, $newbieTypes, true));

        $eventTitles = $this->eventTitles($eventIds->unique()->values()->all(), $languageId);
        $loginEventTitles = $this->loginEventTitles($loginEventIds->unique()->values()->all(), $languageId);
        $couponCodes = $couponBatchIds->isEmpty()
            ? []
            : CouponBatch::query()->whereIn('id', $couponBatchIds->unique()->values()->all())->pluck('batch_code', 'id')->all();
        $newbieTitle = $hasNewbie ? $this->newbieChallengeTitle($memberId, $languageId) : '';

        return $rows->mapWithKeys(fn (FlowLimit $row): array => [
            $row->id => match (true) {
                in_array((int) $row->type, $newbieTypes, true) => $newbieTitle,
                $row->event_id < 0 => (string) ($couponCodes[-$row->event_id] ?? ''),
                in_array((int) $row->type, $eventTypes, true) => (string) ($eventTitles[$row->event_id] ?? ''),
                (int) $row->type === TransactionEnum::LOGIN_EVENT_REWARDS->value => (string) ($loginEventTitles[$row->event_id] ?? ''),
                default => '',
            },
        ])->all();
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<int, string>
     */
    private function eventTitles(array $eventIds, int $languageId): array
    {
        if ($eventIds === []) {
            return [];
        }

        return Event::query()
            ->leftJoin('event_names as n', function (JoinClause $join) use ($languageId): void {
                $join->on('n.event_id', 'events.id')->where('n.language_id', $languageId);
            })
            ->whereIn('events.id', $eventIds)
            ->selectRaw('events.id, IFNULL(n.title, events.title) as title')
            ->pluck('title', 'id')
            ->all();
    }

    /**
     * @param  list<int>  $loginEventIds
     * @return array<int, string>
     */
    private function loginEventTitles(array $loginEventIds, int $languageId): array
    {
        if ($loginEventIds === []) {
            return [];
        }

        return LoginEvent::query()
            ->leftJoin('login_event_names as n', function (JoinClause $join) use ($languageId): void {
                $join->on('n.login_event_id', 'login_events.id')->where('n.language_id', $languageId);
            })
            ->whereIn('login_events.id', $loginEventIds)
            ->selectRaw('login_events.id, IFNULL(n.title, login_events.title) as title')
            ->pluck('title', 'id')
            ->all();
    }

    /** 每人只參加一次新人挑戰，用 member_id 找設定；語系找不到退回英文 */
    private function newbieChallengeTitle(int $memberId, int $languageId): string
    {
        $configId = NewbieChallengeProgress::query()->where('member_id', $memberId)->value('config_id');
        if ($configId === null) {
            return '';
        }

        $names = NewbieChallengeConfigLang::query()
            ->where('config_id', $configId)
            ->whereIn('language_id', [$languageId, LanguageEnum::EN->value])
            ->pluck('name', 'language_id');

        return (string) ($names[$languageId] ?? $names[LanguageEnum::EN->value] ?? '');
    }
}
