<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\Event\PrizeMemberStatusEnum;
use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 直屬會員紀錄。
 *
 * 每列＝範疇內一名會員，統計欄位為其直屬下線（member_agent.topper_member_id）在期間內的數據。
 * 先對範疇內會員分頁，再只對當頁會員做各項聚合，不對全表 GROUP BY。
 */
class MemberReferralInfoService
{
    /** 邀請類活動 action_id：計入總推薦獎金，並從獎勵金排除 */
    private const array INVITE_ACTION_IDS = [
        EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
        EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value,
        EventActionIdEnum::INVITE_PLAYER_OFFERS->value,
        EventActionIdEnum::TPOWER_INVITE_PLAYER->value,
    ];

    /** 非活動類贈點 transaction_logs.type */
    private const array BONUS_TX_TYPES = [
        TransactionEnum::CHECKIN_REWARD->value,
        TransactionEnum::MAKE_UP->value,
        TransactionEnum::VIP_REWARD->value,
        TransactionEnum::SHAREHOLDER_REWARD->value,
        TransactionEnum::COUPON_CODE->value,
        TransactionEnum::PROFIT_SHARE_REWARD->value,
        TransactionEnum::MISSION_REWARD->value,
    ];

    /** 遊戲錢包贈點 wallet_transaction_logs.type：10 打卡、105 任務獎勵 */
    private const array BONUS_WALLET_TX_TYPES = [10, 105];

    /** 禮碼贈點 rolling_chip_logs.type：19 VIP、23 兌換碼、25 任務獎勵 */
    private const array BONUS_ROLLING_CHIP_TYPES = [19, 23, 25];

    public function list(Agent $agent, array $filters, string $startAt, string $endAt, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery($agent);

        if (!empty($filters['agent_username'])) {
            $query->where('a.username', $filters['agent_username']);
        }
        if (!empty($filters['username'])) {
            $query->where('m.username', $filters['username']);
        }

        $total = (clone $query)->count('m.id');

        $rows = $query->select([
            'm.id as member_id',
            'm.username',
            'm.status',
            'a.username as agent_username',
        ])
            ->orderByDesc('m.id')
            ->forPage($page, $perPage)
            ->get();

        $memberIds = $rows->pluck('member_id')->map(fn ($id) => (int) $id)->all();

        if ($memberIds !== []) {
            $directCount = $this->directCountMap($memberIds, $startAt, $endAt);
            $firstDeposit = $this->firstDepositCountMap($memberIds, $startAt, $endAt);
            $hourly = $this->hourlyStatsMap($memberIds, $startAt, $endAt);
            $bets = $this->betStatsMap($memberIds, $startAt, $endAt);
            $rewards = $this->rewardAmountMap($memberIds, $startAt, $endAt);
            $referralRewards = $this->referralRewardMap($memberIds, $startAt, $endAt);

            $rows->transform(function ($row) use ($directCount, $firstDeposit, $hourly, $bets, $rewards, $referralRewards) {
                $id = (int) $row->member_id;
                $h = $hourly[$id] ?? null;
                $b = $bets[$id] ?? null;

                $row->direct_count = $directCount[$id] ?? 0;
                $row->first_deposit_count = $firstDeposit[$id] ?? 0;
                $row->deposit_count = (int) ($h->deposit_count ?? 0);
                $row->deposit_amount = (string) ($h->deposit_amount ?? '0');
                $row->withdrawal_count = (int) ($h->withdrawal_count ?? 0);
                $row->withdrawal_amount = (string) ($h->withdrawal_amount ?? '0');
                $row->bet_count = (int) ($b->bet_count ?? 0);
                $row->valid_bet = (string) ($b->valid_bet ?? '0');
                $row->reward_amount = $rewards[$id] ?? '0.0000';
                $row->referral_reward_amount = $referralRewards[$id] ?? '0.0000';

                return $row;
            });
        }

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * 範疇內會員 base query（同 MemberOnlineService）：自己直屬＋下線代理的會員；
     * admin 不限、子帳號以主帳號為準、hierarchy_path 空時 fail-closed 只看直屬。
     */
    private function baseQuery(Agent $agent): Builder
    {
        $query = DB::table('members as m')
            ->join('agents as a', 'a.id', '=', 'm.parent');

        if ($this->isAdmin($agent)) {
            return $query;
        }

        $ownerId = $this->ownerId($agent);
        $path = $this->effectiveHierarchyPath($agent);

        if ($path === null || $path === '') {
            $query->where('a.id', $ownerId);

            return $query;
        }

        $query->where(function (Builder $q) use ($ownerId, $path) {
            $q->where('a.id', $ownerId)
                ->orWhere('a.hierarchy_path', 'like', $path . '/%');
        });

        return $query;
    }

    /** 直屬下線 join 基底：member_agent（topper 為當頁會員） */
    private function downlineQuery(array $memberIds): Builder
    {
        return DB::table('member_agent as ma')
            ->whereIn('ma.topper_member_id', $memberIds)
            ->groupBy('ma.topper_member_id');
    }

    /** 直屬人數：期間內註冊的直屬下線數 */
    private function directCountMap(array $memberIds, string $startAt, string $endAt): array
    {
        return $this->downlineQuery($memberIds)
            ->join('members as d', 'd.id', '=', 'ma.member_id')
            ->whereBetween('d.created_at', [$startAt, $endAt])
            ->select('ma.topper_member_id', DB::raw('COUNT(*) as cnt'))
            ->pluck('cnt', 'topper_member_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * 首存人數：期間內完成首儲的直屬下線數。
     *
     * 首儲時間取自 orders 的最早成功付款（MIN(pay_time)），不用 members.first_deposit
     * ——該欄位實務上幾乎沒有寫入，拿來判定會全數落空。
     */
    private function firstDepositCountMap(array $memberIds, string $startAt, string $endAt): array
    {
        // 子查詢先限縮在當頁會員的直屬下線，避免對整張 orders 分組（會整表掃描）
        $firstDeposit = DB::table('member_agent as ma')
            ->join('orders as o', function ($join) {
                $join->on('o.member_id', '=', 'ma.member_id')
                    ->where('o.status', OrderStatusEnum::DEPOSIT_FINISHED->value);
            })
            ->whereIn('ma.topper_member_id', $memberIds)
            ->groupBy('ma.topper_member_id', 'o.member_id')
            ->select('ma.topper_member_id', DB::raw('MIN(o.pay_time) as first_pay_time'));

        return DB::query()
            ->fromSub($firstDeposit, 'fd')
            ->whereBetween('fd.first_pay_time', [$startAt, $endAt])
            ->groupBy('fd.topper_member_id')
            ->select('fd.topper_member_id', DB::raw('COUNT(*) as cnt'))
            ->pluck('cnt', 'topper_member_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * 存提統計：直屬下線的小時匯總加總。
     * 起點對齊整點以涵蓋起始的部分小時。
     */
    private function hourlyStatsMap(array $memberIds, string $startAt, string $endAt): array
    {
        $startHour = Carbon::parse($startAt)->startOfHour()->toDateTimeString();

        return $this->downlineQuery($memberIds)
            ->join('member_hourly_statistics as mhs', 'mhs.member_id', '=', 'ma.member_id')
            ->whereBetween('mhs.summary_hour', [$startHour, $endAt])
            ->select(
                'ma.topper_member_id',
                DB::raw('SUM(mhs.deposit_count) as deposit_count'),
                DB::raw('SUM(mhs.deposit_amount) as deposit_amount'),
                DB::raw('SUM(mhs.withdrawal_count) as withdrawal_count'),
                DB::raw('SUM(mhs.withdrawal_amount) as withdrawal_amount'),
            )
            ->get()
            ->keyBy('topper_member_id')
            ->all();
    }

    /** 投注統計：直屬下線的下注小時匯總（record_count=筆數、bet=有效流水加總） */
    private function betStatsMap(array $memberIds, string $startAt, string $endAt): array
    {
        return $this->downlineQuery($memberIds)
            ->join('summary_bet_records as sbr', 'sbr.member_id', '=', 'ma.member_id')
            ->whereBetween('sbr.summary_time', [$startAt, $endAt])
            ->select(
                'ma.topper_member_id',
                DB::raw('SUM(sbr.record_count) as bet_count'),
                DB::raw('SUM(sbr.bet) as valid_bet'),
            )
            ->get()
            ->keyBy('topper_member_id')
            ->all();
    }

    /** 獎勵金：直屬下線期間內的平台獎勵合計（排除邀請獎勵；客服上分/紅包不在來源集合內） */
    private function rewardAmountMap(array $memberIds, string $startAt, string $endAt): array
    {
        $txStatus = TransactionStatusEnum::STATUS_SUCCESS->value;

        // 非活動類贈點（transaction_logs）
        $tx = $this->downlineQuery($memberIds)
            ->join('transaction_logs as tl', 'tl.member_id', '=', 'ma.member_id')
            ->where('tl.status', $txStatus)
            ->whereIn('tl.type', self::BONUS_TX_TYPES)
            ->whereBetween('tl.created_at', [$startAt, $endAt])
            ->select('ma.topper_member_id', DB::raw('SUM(tl.point) as amount'))
            ->pluck('amount', 'topper_member_id');

        // 遊戲錢包贈點（wallet_transaction_logs）
        $wallet = $this->downlineQuery($memberIds)
            ->join('wallet_transaction_logs as wtl', 'wtl.member_id', '=', 'ma.member_id')
            ->where('wtl.status', $txStatus)
            ->whereIn('wtl.type', self::BONUS_WALLET_TX_TYPES)
            ->whereBetween('wtl.created_at', [$startAt, $endAt])
            ->select('ma.topper_member_id', DB::raw('SUM(wtl.point) as amount'))
            ->pluck('amount', 'topper_member_id');

        // 禮碼贈點（rolling_chip_logs）
        $rollingChip = $this->downlineQuery($memberIds)
            ->join('rolling_chip_logs as rcl', 'rcl.member_id', '=', 'ma.member_id')
            ->where('rcl.status', $txStatus)
            ->whereIn('rcl.type', self::BONUS_ROLLING_CHIP_TYPES)
            ->whereBetween('rcl.created_at', [$startAt, $endAt])
            ->select('ma.topper_member_id', DB::raw('SUM(rcl.amount) as amount'))
            ->pluck('amount', 'topper_member_id');

        // 活動 V1 已領獎（排除邀請類；event_detail_id 對不到明細者視為非邀請）
        $eventV1 = $this->prizeQuery($memberIds, $startAt, $endAt)
            ->where(function (Builder $q) {
                $q->whereNull('ed.action_id')
                    ->orWhereNotIn('ed.action_id', self::INVITE_ACTION_IDS);
            })
            ->pluck('amount', 'topper_member_id');

        // 活動 V2：參加獎勵（cost_type=1，入帳於參加當下）
        $eventV2Join = $this->downlineQuery($memberIds)
            ->join('event_v2_user_joins as ej', 'ej.member_id', '=', 'ma.member_id')
            ->join('event_v2_details as ed2', function ($join) {
                $join->on('ed2.id', '=', 'ej.event_v2_detail_id')
                    ->where('ed2.cost_type', EventV2DetailCostTypeEnum::JOIN->value);
            })
            ->whereBetween('ej.created_at', [$startAt, $endAt])
            ->select('ma.topper_member_id', DB::raw('SUM(ej.reward) as amount'))
            ->pluck('amount', 'topper_member_id');

        // 活動 V2：完成提領（cost_type=2，入帳於完成活動）
        $eventV2Claim = $this->downlineQuery($memberIds)
            ->join('event_v2_user_joins as ej', 'ej.member_id', '=', 'ma.member_id')
            ->join('event_v2_details as ed2', function ($join) {
                $join->on('ed2.id', '=', 'ej.event_v2_detail_id')
                    ->where('ed2.cost_type', EventV2DetailCostTypeEnum::COMPLETE->value);
            })
            ->where('ej.status', EventV2UserJoinStatusEnum::COMPLETE->value)
            ->whereBetween('ej.updated_at', [$startAt, $endAt])
            ->select('ma.topper_member_id', DB::raw('SUM(ej.withdraw) as amount'))
            ->pluck('amount', 'topper_member_id');

        $result = [];
        foreach ([$tx, $wallet, $rollingChip, $eventV1, $eventV2Join, $eventV2Claim] as $map) {
            foreach ($map as $topperId => $amount) {
                $result[$topperId] = Common::numAdd($result[$topperId] ?? '0', (string) ($amount ?? '0'), 4);
            }
        }

        return $result;
    }

    /** 總推薦獎金：當頁會員「本人」期間內已領取的邀請類活動獎勵 */
    private function referralRewardMap(array $memberIds, string $startAt, string $endAt): array
    {
        return DB::table('prize_members as pm')
            ->join('event_details as ed', 'ed.id', '=', 'pm.event_detail_id')
            ->leftJoin('prize_contents as pc', 'pc.id', '=', 'pm.content_id')
            ->whereIn('pm.member_id', $memberIds)
            ->where('pm.status', PrizeMemberStatusEnum::AWARD->value)
            ->whereBetween('pm.updated_at', [$startAt, $endAt])
            ->whereIn('ed.action_id', self::INVITE_ACTION_IDS)
            ->groupBy('pm.member_id')
            ->select('pm.member_id', DB::raw('SUM(COALESCE(pm.lottery_point, pc.point, 0)) as amount'))
            ->pluck('amount', 'member_id')
            ->map(fn ($v) => Common::numAdd('0', (string) $v, 4))
            ->all();
    }

    /** 活動 V1 已領獎聚合基底（直屬下線；金額＝抽獎點數或獎項固定點數） */
    private function prizeQuery(array $memberIds, string $startAt, string $endAt): Builder
    {
        return $this->downlineQuery($memberIds)
            ->join('prize_members as pm', 'pm.member_id', '=', 'ma.member_id')
            ->leftJoin('prize_contents as pc', 'pc.id', '=', 'pm.content_id')
            ->leftJoin('event_details as ed', 'ed.id', '=', 'pm.event_detail_id')
            ->where('pm.status', PrizeMemberStatusEnum::AWARD->value)
            ->whereBetween('pm.updated_at', [$startAt, $endAt])
            ->select('ma.topper_member_id', DB::raw('SUM(COALESCE(pm.lottery_point, pc.point, 0)) as amount'));
    }

    /** 子帳號（host_id != 0）以其主帳號的階層為準。 */
    private function effectiveHierarchyPath(Agent $agent): ?string
    {
        $path = $agent->hierarchy_path;
        if ($agent->host_id != 0) {
            $upper = Agent::find($agent->host_id);
            $path = $upper->hierarchy_path ?? $path;
        }

        return $path;
    }

    private function ownerId(Agent $agent): int
    {
        return $agent->host_id != 0 ? (int) $agent->host_id : (int) $agent->id;
    }

    private function isAdmin(Agent $agent): bool
    {
        return $agent->id == 1 || $agent->host_id == 1;
    }
}
