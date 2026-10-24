<?php

declare(strict_types=1);

namespace App\Services\LivechatBot\Concerns;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\OrderStatusEnum;
use App\Helpers\Common;
use App\Models\EventDetail;
use App\Models\EventInviteRecord;
use App\Models\Member;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Event V1 邀請（action 15 NEW_INVITE_PLAYER_OFFERS）進度共用邏輯。
 *
 * 自 {@see \App\Services\LivechatBot\PromotionV1Builder} 抽出的純邀請計數與分級門檻
 * （`filteredInvitedMemberIds`、`inviteTiers`、`resolveInviteRequired`），並補上
 * Livechat Bot referral-progress endpoint 兼容 V1 所需的活動查找、獎勵金額解析與
 * level-1「邀請並充值」達標被邀請者清單方法，供 builder 與 service 共用。
 */
trait ResolvesV1InviteProgress
{
    /**
     * 取得「該 inviter 的有效被邀請者 member_id 清單」。
     *
     * 過濾規則對齊舊系統 EventNewInviteController::getNewInvitePrizeCnt 的 $invite_members 子句：
     * - 限活動時間窗（created_at 落在 [start_time, end_time)）
     * - 依 event block 旗標**動態**套用重複過濾（旗標=0 時不排除對應 duplicate）：
     *   ip_block→ip_duplicate=0、phone_block→phone_duplicate=0、
     *   account_block→realname_duplicate=0、tgid_block→telegram_id_duplicate=0
     * - realname_block=1 → 要求被邀請人（member_id）realname_validate=1
     *
     * 為 resolveInviteCount（level-0 純邀請計數）與 resolveInviteDepositDimension
     * （level-1 邀請並充值維度）共用，確保兩維度的「被邀請者母體」完全一致。
     *
     * @return array<int, int>
     */
    protected function filteredInvitedMemberIds(EventDetail $detail, Member $member): array
    {
        return EventInviteRecord::query()
            ->where('event_id', $detail->event_id)
            ->where('invite_member_id', $member->id)
            ->where('created_at', '>=', $detail->start_time)
            ->where('created_at', '<', $detail->end_time)
            ->when($detail->ip_block == 1, fn ($q) => $q->where('ip_duplicate', 0))
            ->when($detail->phone_block == 1, fn ($q) => $q->where('phone_duplicate', 0))
            ->when($detail->account_block == 1, fn ($q) => $q->where('realname_duplicate', 0))
            ->when($detail->tgid_block == 1, fn ($q) => $q->where('telegram_id_duplicate', 0))
            ->when($detail->realname_block == 1, fn ($q) => $q->whereHas('member', fn ($mq) => $mq->where('realname_validate', 1)))
            // 刻意不去重：對齊舊系統 EventNewInviteController::getNewInvitePrizeCnt 的 level-0 計數
            // （`count($invite_members)` 算列數，非 distinct 被邀請者），此即 V1 派彩實際採計的基準。
            // event_invite_records 雖無 (invite_member_id, member_id) 唯一約束、偶有重複列，仍逐列計入
            // 以與派彩一致。downlines 另經 usernamesByIds 的 whereIn('id') 天然去重，故有重複列時
            // current_count 可能 > downlines 筆數——此為刻意取捨，見 docs/aichatbot_apis/referral-progress.md。
            // 請勿在此加 ->unique()，否則 bot 顯示會較 V1 派彩偏少。
            ->pluck('member_id')
            ->all();
    }

    /**
     * 取得邀請類活動指定成就維度（level）的有效 achieve_people 門檻級距（升冪、去重、>0）。
     *
     * 邀請類活動 prize_contents.level 為「成就類型」而非門檻排名：
     * - level=0 → 純邀請（action 15 NEW_INVITE_PLAYER_OFFERS / 60 TPOWER）
     * - level=1 → 邀請並充值（被邀請者於活動窗內首單達金額門檻）
     *
     * @return Collection<int, int>
     */
    protected function inviteTiers(EventDetail $detail, int $level): Collection
    {
        return ($detail->event?->prize?->prizeContents ?? collect())
            ->where('status', 1)
            ->filter(fn ($content) => (int) $content->level === $level)
            ->map(fn ($content) => (int) $content->achieve_people)
            ->filter(fn (int $people) => $people > 0)
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * 邀請達標門檻（分級）。
     *
     * 門檻來源為 prize_contents.achieve_people（非 event_details.point）。活動可分多級，
     * 規則 A：回傳「玩家尚未達成的最低一級」= 大於 $count 的最小 achieve_people。
     * 已超過所有級別時回傳最高級（讓呼叫端以 current >= required 過濾為已達標）。
     * 無任何有效級別（皆 <=0 或無 active prize_content）時退回 event_details.point。
     *
     * @param  int  $count  目前有效進度數
     * @param  int  $level  成就維度（0 純邀請、1 邀請並充值）
     */
    protected function resolveInviteRequired(EventDetail $detail, int $count, int $level = 0): int
    {
        $tiers = $this->inviteTiers($detail, $level);

        if ($tiers->isEmpty()) {
            // point 為保守退路：action 60 (TPOWER) 等無 achieve_people 級距的活動沿用，
            // 改回 null 會讓這些活動消失於 conditioned，造成回歸。
            return (int) $detail->point;
        }

        $next = $tiers->first(fn (int $tier) => $tier > $count);

        return $next ?? (int) $tiers->last();
    }

    /**
     * 找該會員「當期可參加」的 action-15 邀請活動（最緊迫一筆，end_time ASC）。
     *
     * 濾鏡對齊 {@see \App\Services\LivechatBot\PromotionV1Builder::loadEligibleDetails}：
     * - event.status=1、agent_id ∈ hierarchy、未被 eventExcludeAgents 排除
     * - prize.status=1 且 expire_date > now
     * - detail.status=1 且 now ∈ [start_time, end_time)
     *
     * eager load `event.prize.prizeContents` 以供 inviteTiers / inviteRewardForTarget 使用。
     */
    protected function findActiveV1InviteDetail(Member $member): ?EventDetail
    {
        $hierarchy = $member->agent?->hierarchy_array ?? [];
        if ($hierarchy === []) {
            return null;
        }

        $now = Carbon::now();

        /** @var EventDetail|null $detail */
        $detail = EventDetail::query()
            ->with([
                'event.prize' => fn ($q) => $q->where('status', 1)->where('expire_date', '>', $now),
                'event.prize.prizeContents',
            ])
            ->where('event_details.action_id', EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value)
            ->where('event_details.status', 1)
            ->where('event_details.start_time', '<=', $now)
            ->where('event_details.end_time', '>', $now)
            ->whereHas('event', function ($eventQuery) use ($hierarchy, $now) {
                $eventQuery
                    ->where('status', 1)
                    ->whereIn('agent_id', $hierarchy)
                    ->whereDoesntHave('eventExcludeAgents', fn ($q) => $q->whereIn('agent_id', $hierarchy))
                    ->whereHas('prize', fn ($q) => $q
                        ->where('status', 1)
                        ->where('expire_date', '>', $now)
                    );
            })
            ->orderBy('event_details.end_time', 'asc')
            ->first();

        return $detail;
    }

    /**
     * 取得指定成就維度（level）達標門檻（achieve_people=$target）對應的獎勵金額。
     *
     * 從 eager-load 的 `event.prize.prizeContents` 取 status=1 且 level/achieve_people 相符的
     * 第一筆 prize_content，以其 `point` 格式化為 2 位小數；找不到回 '0.00'。
     */
    protected function inviteRewardForTarget(EventDetail $detail, int $level, int $target): string
    {
        /** @var \App\Models\PrizeContent|null $content */
        $content = ($detail->event?->prize?->prizeContents ?? collect())
            ->where('status', 1)
            ->first(fn ($c) => (int) $c->level === $level && (int) $c->achieve_people === $target);

        if ($content === null) {
            return '0.00';
        }

        return Common::numAdd('0', (string) $content->point, 2);
    }

    /**
     * level-1「邀請並充值」達標的被邀請者 member_id 清單。
     *
     * 即 {@see filteredInvitedMemberIds} 母體中、其活動窗內首筆完成訂單
     * （status=3、created_at ∈ [start,end)、MIN(id)）之 `orders.point` >= `detail.point`（最低充值點數）者。
     * 對齊 PromotionV1Builder::resolveInviteDepositDimension 的子查詢，但回 member_id（非僅 count），供 downlines 用。
     *
     * @return array<int, int>
     */
    protected function qualifiedDepositInviteeIds(EventDetail $detail, Member $member): array
    {
        $invitedIds = $this->filteredInvitedMemberIds($detail, $member);
        if ($invitedIds === []) {
            return [];
        }

        // 子查詢：每位被邀請者於活動窗內、status=3 的「首筆」訂單 id（MIN(id)）。
        $firstOrders = Order::query()
            ->whereIn('member_id', $invitedIds)
            ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
            ->where('created_at', '>=', $detail->start_time)
            ->where('created_at', '<', $detail->end_time)
            ->groupBy('member_id')
            ->selectRaw('member_id, MIN(id) as first_id');

        // 外層：首單「點數」達門檻者的 member_id（比 orders.point 對齊舊系統 o.point >= event.point）。
        return Order::query()
            ->joinSub($firstOrders, 't', fn ($join) => $join->on('orders.id', '=', 't.first_id'))
            ->where('orders.point', '>=', $detail->point)
            ->pluck('orders.member_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
