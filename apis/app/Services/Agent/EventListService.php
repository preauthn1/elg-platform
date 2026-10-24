<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\EventListSortFieldEnum;
use App\Enums\Event\EventActionIdEnum;
use App\Enums\Event\EventTypeEnum;
use App\Enums\Event\PrizeTypeEnum;
use App\Enums\SortDirectionEnum;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventJackpot;
use App\Models\Prize;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * 後台活動管理（V1）列表：後端分頁、排序、代理範疇（target/tickets#4320）。
 *
 * 自 API_Agent `EventController@list` 遷入，查詢語意對齊舊接口：
 *  - events INNER JOIN prizes（無獎勵設定的活動不列）；一個活動多筆 prize 時取最早一筆，
 *    舊接口以 GROUP BY events.id 達到同效，這裡改 joinSub 取 MIN(id) 以相容 ONLY_FULL_GROUP_BY。
 *  - 活動名稱／內容依語系從 event_names 覆寫，沒有翻譯退回 events 本體。
 *  - 代理範疇：平台端看全部，其餘看自己（子帳號看主帳號）hierarchy_path 之下的代理所建活動。
 *  - detail 陣列的挑選規則與舊接口一致（見 attachDetails），但改為兩次 whereIn 批次撈取，消掉逐列查詢。
 *
 * 排序：依 EventListSortFieldEnum 白名單；起訖時間與滿足條件為 detail 的彙總值，以相關子查詢當排序鍵
 * （子查詢為常數 SQL、不含任何使用者輸入）。
 */
class EventListService
{
    /**
     * @param  array{id?: ?int, username?: ?string, title?: ?string, status?: ?int}  $filters
     */
    public function paginate(
        Agent $agent,
        int $languageId,
        array $filters,
        int $page,
        int $perPage,
        ?EventListSortFieldEnum $sortField,
        SortDirectionEnum $direction,
    ): LengthAwarePaginator {
        $firstPrize = Prize::query()
            ->selectRaw('MIN(id) AS id, event_id')
            ->groupBy('event_id');

        $query = Event::query()
            ->joinSub($firstPrize, 'fp', 'fp.event_id', '=', 'events.id')
            ->join('prizes as p', 'p.id', '=', 'fp.id')
            ->leftJoin('event_names as n', function (JoinClause $join) use ($languageId) {
                $join->on('n.event_id', '=', 'events.id')
                    ->where('n.language_id', $languageId);
            })
            ->join('agents as ag', 'ag.id', '=', 'events.agent_id')
            ->when(!$agent->isPlatformAdmin(), function (Builder $query) use ($agent) {
                $query->where('ag.hierarchy_path', 'like', $this->scopeHierarchyPath($agent) . '%');
            })
            ->when($filters['id'] ?? null, fn (Builder $q) => $q->where('events.id', $filters['id']))
            ->when(($filters['username'] ?? '') !== '', fn (Builder $q) => $q->where('ag.username', $filters['username']))
            // 活動名稱模糊比對：原始名稱與當前語系翻譯名稱皆算（列表顯示的是翻譯名稱，舊接口只比原名）
            ->when(($filters['title'] ?? '') !== '', function (Builder $q) use ($filters) {
                $keyword = '%' . $filters['title'] . '%';
                $q->where(function (Builder $q) use ($keyword) {
                    $q->where('events.title', 'like', $keyword)
                        ->orWhere('n.title', 'like', $keyword);
                });
            })
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('events.status', $filters['status']))
            ->select([
                'events.id',
                'events.type_id',
                'events.sort',
                'events.status',
                'events.agent_id',
                'events.created_at',
                'ag.username as agent_username',
                'p.id as prize_id',
                'p.type_id as prize_type_id',
                'p.number as prize_number',
                'p.is_repeatedly as prize_is_repeatedly',
                'p.withdrawal_cancel_reward as prize_withdrawal_cancel_reward',
                'p.expire_date as prize_expire_date',
                'p.draw_date as prize_draw_date',
            ])
            ->selectRaw('COALESCE(n.title, events.title) AS title')
            ->selectRaw('COALESCE(n.content, events.content) AS content');

        $this->selectSortKeys($query);
        $this->applyOrder($query, $sortField, $direction);

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $this->attachDetails($paginator->getCollection());

        return $paginator;
    }

    /**
     * 代理範疇的 hierarchy_path：代理子帳號看主帳號的範圍。
     */
    private function scopeHierarchyPath(Agent $agent): string
    {
        if ((int) $agent->host_id !== 0) {
            $host = Agent::query()->find($agent->host_id);
            if ($host !== null) {
                return (string) $host->hierarchy_path;
            }
        }

        return (string) $agent->hierarchy_path;
    }

    /**
     * 排序鍵：起訖時間、滿足條件（行為＋門檻）。
     * 一般活動來自 event_details（啟用中），Jackpot 活動（events.type_id=3）來自 event_jackpots，
     * 與 attachDetails 的來源一致。純常數 SQL，不含使用者輸入。
     */
    private function selectSortKeys(Builder $query): void
    {
        $jackpotAction = EventActionIdEnum::JACKPOT->value;

        $query
            ->selectRaw(
                'COALESCE('
                . '(SELECT MIN(d.start_time) FROM event_details AS d WHERE d.event_id = events.id AND d.status = 1), '
                . '(SELECT MIN(j.start_time) FROM event_jackpots AS j WHERE j.event_id = events.id)'
                . ') AS sort_start_time'
            )
            ->selectRaw(
                'COALESCE('
                . '(SELECT MAX(d.end_time) FROM event_details AS d WHERE d.event_id = events.id AND d.status = 1), '
                . '(SELECT MAX(j.end_time) FROM event_jackpots AS j WHERE j.event_id = events.id)'
                . ') AS sort_end_time'
            )
            ->selectRaw(
                'COALESCE('
                . '(SELECT d.action_id FROM event_details AS d WHERE d.event_id = events.id ORDER BY d.id LIMIT 1), '
                . "{$jackpotAction}"
                . ') AS sort_action_id'
            )
            ->selectRaw(
                'COALESCE('
                . '(SELECT d.point FROM event_details AS d WHERE d.event_id = events.id ORDER BY d.id LIMIT 1), '
                . '(SELECT FLOOR(j.achieve_point) FROM event_jackpots AS j WHERE j.event_id = events.id ORDER BY j.id LIMIT 1)'
                . ') AS sort_point'
            );
    }

    private function applyOrder(Builder $query, ?EventListSortFieldEnum $sortField, SortDirectionEnum $direction): void
    {
        if ($sortField === null) {
            // 舊接口預設：建立時間新到舊
            $query->orderBy('events.created_at', 'desc')->orderBy('events.id', 'desc');

            return;
        }

        foreach ($sortField->orderColumns() as $column) {
            $query->orderBy($column, $direction->value);
        }
        // 同值時固定以 id 由新到舊，翻頁結果才穩定
        $query->orderBy('events.id', 'desc');
    }

    /**
     * 為本頁每筆活動掛上 detail 陣列（挑選規則對齊舊接口）：
     *  - Jackpot 活動：event_jackpots 首筆，action_id 固定為 JACKPOT、門檻取 floor(achieve_point)。
     *  - 其餘：以首筆 detail 的 action_id / game_join_event_used 決定挑法——
     *      V-Jackpot 獎勵只看當下進行中的 detail；
     *      老虎機競賽、或投注且未使用遊戲加入活動：多期 detail 中優先取當下進行中者，否則取結束最晚者；
     *      其他：取啟用中的首筆。
     *
     * @param  Collection<int, Event>  $rows
     */
    private function attachDetails(Collection $rows): void
    {
        $jackpotIds = $rows
            ->where('type_id', EventTypeEnum::JACKPOT->value)
            ->pluck('id');
        $regularIds = $rows->pluck('id')->diff($jackpotIds);

        $jackpots = $jackpotIds->isEmpty()
            ? collect()
            : EventJackpot::query()->whereIn('event_id', $jackpotIds)->orderBy('id')->get()->groupBy('event_id');
        $details = $regularIds->isEmpty()
            ? collect()
            : EventDetail::query()->whereIn('event_id', $regularIds)->orderBy('id')->get()->groupBy('event_id');

        $now = now()->toDateTimeString();

        foreach ($rows as $row) {
            $row->setAttribute('detail', (int) $row->type_id === EventTypeEnum::JACKPOT->value
                ? $this->jackpotDetail($jackpots->get($row->id, collect()))
                : $this->regularDetail($details->get($row->id, collect()), (int) $row->prize_type_id, $now));
        }
    }

    /**
     * @param  Collection<int, EventJackpot>  $jackpots
     * @return list<array<string, mixed>>
     */
    private function jackpotDetail(Collection $jackpots): array
    {
        $first = $jackpots->first();
        if ($first === null) {
            return [];
        }

        return [[
            'action_id' => EventActionIdEnum::JACKPOT->value,
            'point' => (int) floor((float) $first->achieve_point),
            'start_time' => $this->toBackendTime($first->start_time),
            'end_time' => $this->toBackendTime($first->end_time),
        ]];
    }

    /**
     * @param  Collection<int, EventDetail>  $all  該活動全部 detail（含停用，依 id 升冪）
     * @return list<array<string, mixed>>
     */
    private function regularDetail(Collection $all, int $prizeTypeId, string $now): array
    {
        $first = $all->first();
        if ($first === null) {
            return [];
        }

        $actionId = (int) $first->action_id;
        $gameJoinEventUsed = (int) $first->game_join_event_used;

        $active = $all->where('status', 1);
        if ($prizeTypeId === PrizeTypeEnum::V_JACKPOT->value) {
            $active = $active->filter(fn (EventDetail $d) => $d->start_time <= $now && $d->end_time >= $now);
        }
        if ($active->isEmpty()) {
            return [];
        }

        $multiPeriod = $actionId === EventActionIdEnum::SLOT_MACHINE_COMPETITION->value
            || ($actionId === EventActionIdEnum::BET->value && $gameJoinEventUsed === 0);

        if ($multiPeriod) {
            $byEndDesc = $active->sortByDesc('end_time')->values();
            $current = $byEndDesc->first(fn (EventDetail $d) => $d->start_time <= $now && $d->end_time >= $now);
            $picked = $current ?? $byEndDesc->first();

            return [$this->detailToArray($picked)];
        }

        return [$this->detailToArray($active->first())];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailToArray(EventDetail $detail): array
    {
        return [
            'id' => $detail->id,
            'action_id' => (int) $detail->action_id,
            'order_type_id' => $detail->order_type_id,
            'vendor_id' => $detail->vendor_id,
            'game_id' => $detail->game_id,
            'rate' => $detail->rate,
            'point' => $detail->point,
            'start_time' => $this->toBackendTime($detail->start_time),
            'end_time' => $this->toBackendTime($detail->end_time),
        ];
    }

    /**
     * DB（系統時區）時間轉後台顯示時區字串；舊接口以 DATE_ADD(... INTERVAL 8 HOUR) 寫死，這裡改走設定。
     */
    private function toBackendTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toBackendTime()->toDateTimeString();
    }
}
