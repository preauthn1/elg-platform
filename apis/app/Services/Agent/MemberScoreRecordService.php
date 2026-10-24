<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\LanguageEnum;
use App\Enums\Score\ScoreBatchStatusEnum;
use App\Enums\Score\ScoreLogDirectionEnum;
use App\Enums\Score\ScoreLogTypeEnum;
use App\Enums\Score\ScoreSourceTypeEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\EventV2DetailLang;
use App\Models\EventV2UserJoin;
use App\Models\MemberScoreBatch;
use App\Models\MemberScoreLog;
use App\Models\MemberWallet;
use App\Services\Agent\Concerns\ChecksMemberScope;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 專屬優惠 › 積分紀錄（target/tickets#4232）——純讀，不寫任何積分資料。
 *
 * 主表每會員一列，**只列有積分錢包列的會員**（Max 2026-09-16 定案：沒發過積分的列出來全是 0 沒意義）。
 * 四種來源全部讀 member_score_logs 一張表，不分別去活動表、調整紀錄各撈再合併（工單明文）。
 * 「來源」欄由 type（做了什麼）× reference_type（哪個系統）組出，活動名稱依參照另查一次語系表。
 *
 * 效能：先分頁取這頁的會員，再只對這頁做 logs／batches 聚合（累計獲得、已過期、最近異動），
 * 不對整棵下線 join——比照 API_Agent 會員列表「顯示階段才 join」的作法。
 * 範圍＝整棵下線（ChecksMemberScope，與會員清單一致）。
 */
class MemberScoreRecordService
{
    use ChecksMemberScope;

    /** 活動名稱查不到看的人的語系時退回英文：英文是營運建立活動必填的語系，各站 languages 表 en 皆為 id 1 */
    private const int FALLBACK_LANGUAGE_ID = LanguageEnum::EN->value;

    /**
     * 主表：會員帳號／所屬代理／可用積分／已過期積分／累計獲得／已使用／最近到期／最近異動時間。
     *
     * @param  array{username?: string|null, agent_username?: string|null}  $filters
     */
    public function list(Agent $agent, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $w = MemberWallet::query()->getModel()->getTable(); // 不用 new：錢包寫入守衛（WalletWriteEntrypointTest）攔 new MemberWallet

        $query = MemberWallet::query()
            ->join('members as m', 'm.id', '=', "{$w}.member_id")
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->where("{$w}.wallet_type_id", WalletEnum::CODE_SCORE->value);

        // 範圍：admin 不限；主帳號用自己的 hierarchy_path、子帳號用上級的；解析不到 fail-closed
        $scopePath = $this->memberListScopePath($agent);
        if ($scopePath === '') {
            $query->whereRaw('1 = 0');
        } elseif ($scopePath !== null) {
            $query->where('a.hierarchy_path', 'like', $scopePath . '%');
        }

        if (!empty($filters['username'])) {
            $query->where('m.username', $filters['username']);
        }

        if (!empty($filters['agent_username'])) {
            // 「所屬代理」＝那一條線：該代理本身＋其下線代理的會員；不在可視範圍內的代理視同查無
            $line = Agent::query()->where('username', $filters['agent_username'])->first(['id', 'hierarchy_path']);
            $linePath = $line?->hierarchy_path;
            $inScope = $line !== null && ($scopePath === null || ($scopePath !== '' && str_starts_with((string) $linePath, $scopePath)));

            if (!$inScope || $linePath === null || $linePath === '') {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('a.hierarchy_path', 'like', $linePath . '%');
            }
        }

        $total = (clone $query)->count();

        $rows = $query->select([
            'm.id as member_id',
            'm.username',
            'a.username as agent_username',
            "{$w}.balance as available_score",
            "{$w}.updated_at as wallet_updated_at",
        ])
            ->orderByDesc("{$w}.updated_at")
            ->orderByDesc('m.id')
            ->forPage($page, $perPage)
            ->get();

        $memberIds = $rows->pluck('member_id')->all();

        // 只對這一頁聚合。已使用＝系統消耗＋人工扣除（人工調整兩個方向都有，只算扣除方向；target/tickets#4438）
        $logStats = MemberScoreLog::query()
            ->whereIn('member_id', $memberIds)
            ->groupBy('member_id')
            ->selectRaw(
                'member_id'
                . ', SUM(CASE WHEN type = ? THEN score ELSE 0 END) AS expired_total'
                . ', SUM(CASE WHEN direction = ? AND type IN (?, ?) THEN score ELSE 0 END) AS used_total'
                . ', MAX(created_at) AS last_log_at',
                [
                    ScoreLogTypeEnum::EXPIRE->value,
                    ScoreLogDirectionEnum::DECREASE->value,
                    ScoreLogTypeEnum::CONSUME->value,
                    ScoreLogTypeEnum::MANUAL->value,
                ],
            )
            ->get()
            ->keyBy('member_id');

        $granted = MemberScoreBatch::query()
            ->whereIn('member_id', $memberIds)
            ->groupBy('member_id')
            ->selectRaw('member_id, SUM(score) AS granted_total')
            ->pluck('granted_total', 'member_id');

        $nextExpiry = $this->nextExpiryByMember($memberIds);

        $items = $rows->map(function ($row) use ($logStats, $granted, $nextExpiry): array {
            $stat = $logStats->get($row->member_id);
            $expiry = $nextExpiry[$row->member_id] ?? null;

            return [
                'member_id' => (int) $row->member_id,
                'username' => $row->username,
                'agent_username' => $row->agent_username,
                'available_score' => Common::numAdd('0', (string) $row->available_score, 4),
                'expired_score' => Common::numAdd('0', (string) ($stat?->expired_total ?? '0'), 4),
                'granted_score' => Common::numAdd('0', (string) ($granted[$row->member_id] ?? '0'), 4),
                'used_score' => Common::numAdd('0', (string) ($stat?->used_total ?? '0'), 4),
                // 最近到期：還有剩餘、到期時間最早的那一刻；全部無期限或沒剩餘為 null（前端顯示「-」）
                'next_expire_at' => $expiry !== null
                    ? Carbon::parse($expiry->expired_at)->toBackendTime()->toDateTimeString()
                    : null,
                'next_expire_score' => $expiry !== null ? Common::numAdd('0', (string) $expiry->remaining_total, 4) : null,
                'last_changed_at' => $stat?->last_log_at
                    ? Carbon::parse($stat->last_log_at)->toBackendTime()->toDateTimeString()
                    : null,
            ];
        })->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * 每位會員「最近到期」的那一刻與當刻到期的剩餘（target/tickets#4438 會員抽屜積分卡）。
     *
     * 只看有效、還有剩餘、有到期時間且到期時間在現在之後的批次——到期排程每 10 分鐘跑一次，
     * 中間可能有已過期但還沒被標失效的批次，不能拿來顯示。同一刻到期的多批（到期時間落在 10 分鐘刻度上很常見）
     * 剩餘加總，因為會員看到的是「那一刻會失效多少」。
     *
     * @param  list<int>  $memberIds
     * @return array<int, object{expired_at: string, remaining_total: string}>
     */
    private function nextExpiryByMember(array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $nextExpiry = [];
        MemberScoreBatch::query()
            ->whereIn('member_id', $memberIds)
            ->where('status', ScoreBatchStatusEnum::ACTIVE->value)
            ->where('remaining_score', '>', 0)
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', Carbon::now()->toDateTimeString())
            ->groupBy('member_id', 'expired_at')
            ->orderBy('expired_at')
            ->selectRaw('member_id, expired_at, SUM(remaining_score) AS remaining_total')
            ->toBase()
            ->get()
            ->each(function (object $row) use (&$nextExpiry): void {
                $nextExpiry[(int) $row->member_id] ??= $row;
            });

        return $nextExpiry;
    }

    /**
     * 明細：某會員某期間的積分異動，新→舊；到期日只有發放類有值、扣除類留空（沿 log 欄位）。
     * 可再縮到單一批次（batch_id）與來源（type × direction；「人工發放／人工扣除」靠 direction 分）。
     */
    public function logs(
        Agent $agent,
        int $memberId,
        ?string $startTime,
        ?string $endTime,
        int $languageId,
        int $page,
        int $perPage,
        ?int $batchId = null,
        ?int $type = null,
        ?int $direction = null,
    ): LengthAwarePaginator {
        $member = $this->findMemberInScope($agent, $memberId);

        $query = MemberScoreLog::query()
            ->where('member_id', $member->id)
            ->when($batchId !== null, fn ($q) => $q->where('batch_id', $batchId))
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->when($direction !== null, fn ($q) => $q->where('direction', $direction));

        if ($startTime !== null) {
            $query->where('created_at', '>=', Carbon::parse($startTime, config('custom.backend.timezone'))->toSystemTime());
        }
        if ($endTime !== null) {
            $query->where('created_at', '<=', Carbon::parse($endTime, config('custom.backend.timezone'))->toSystemTime());
        }

        $total = (clone $query)->count();
        $logs = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        $activityNames = $this->resolveActivityNames($logs, $languageId);

        $items = $logs->map(fn (MemberScoreLog $log): array => [
            'log_id' => $log->id,
            'batch_id' => $log->batch_id,
            'changed_at' => $log->created_at->toBackendTime()->toDateTimeString(),
            'type' => $log->type->value,
            'direction' => $log->direction->value,
            'score' => Common::numAdd('0', (string) $log->score, 4),
            'after_score' => Common::numAdd('0', (string) $log->after_score, 4),
            'expired_at' => $log->expired_at?->toBackendTime()->toDateTimeString(),
            'reference_type' => $log->reference_type->value,
            'reference_id' => $log->reference_id,
            'activity_name' => $activityNames[$log->reference_id] ?? null,
            'operator' => $log->operator,
            'remark' => $log->remark,
        ])->values();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * 活動 V2 參照 → 活動名稱（指定語系，查不到退到預設語系）。只對這一頁的參照查，兩條小查詢。
     *
     * @param  Collection<int, MemberScoreLog>  $logs
     * @return array<int, string> event_v2_user_joins.id => 活動名稱
     */
    private function resolveActivityNames(Collection $logs, int $languageId): array
    {
        $joinIds = $logs
            ->filter(fn (MemberScoreLog $log) => $log->reference_type === ScoreSourceTypeEnum::EVENT_V2_USER_JOIN && $log->reference_id > 0)
            ->pluck('reference_id')
            ->unique()
            ->values();

        if ($joinIds->isEmpty()) {
            return [];
        }

        $detailIdByJoin = EventV2UserJoin::query()
            ->whereIn('id', $joinIds)
            ->pluck('event_v2_detail_id', 'id');

        $langs = EventV2DetailLang::query()
            ->whereIn('event_v2_detail_id', $detailIdByJoin->values()->unique())
            ->whereIn('language_id', array_unique([$languageId, self::FALLBACK_LANGUAGE_ID]))
            ->get(['event_v2_detail_id', 'language_id', 'name']);

        $names = [];
        foreach ($detailIdByJoin as $joinId => $detailId) {
            $preferred = $langs->first(fn ($l) => (int) $l->event_v2_detail_id === (int) $detailId && (int) $l->language_id === $languageId);
            $fallback = $langs->first(fn ($l) => (int) $l->event_v2_detail_id === (int) $detailId);
            $name = ($preferred ?? $fallback)?->name;
            if ($name !== null) {
                $names[(int) $joinId] = (string) $name;
            }
        }

        return $names;
    }
}
