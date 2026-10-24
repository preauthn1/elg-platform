<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\EventJackpotRecord;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\NewbieChallengeProgress;
use App\Models\PrizeMember;
use App\Models\TransactionLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 積寶活動（`api/event/jackpot/*`）
 *
 * 遷移自 API_Frontend EventJackpotController；tpower-master 與 vw-master 僅差 2 行
 * （vw 的 show 多回 start_time / end_time），已取 vw 的聯集。
 * 積寶活動以 `events.type_id = 3` 辨識，期數設定放 `event_jackpots`，
 * 中獎資格由後台／SP 寫進 `prize_members`（`event_detail_id` 指向 `event_jackpots.id`）。
 */
class EventJackpotService
{
    /** events.type_id：積寶活動 */
    private const int EVENT_TYPE_JACKPOT = 3;

    /** 營業日換日時間（時） */
    private const int BUSINESS_DAY_START_HOUR = 7;

    public function __construct(
        protected AgentService $agentService,
    ) {}

    /**
     * event/jackpot/show - 活動資訊與自己的獎項
     *
     * 舊版查無活動時回傳 `data: null`（`is_object` 判斷），此處維持相同行為。
     */
    public function getEvent(Member $member, Agent $agent, int $languageId): ?object
    {
        $data = DB::table('events as e')
            ->join('prizes as p', 'p.event_id', 'e.id')
            ->leftJoin('event_names as en', function ($join) use ($languageId) {
                $join->on('en.event_id', 'e.id')->where('en.language_id', $languageId);
            })
            ->leftJoin('event_images as ei', function ($join) use ($languageId) {
                $join->on('ei.event_id', 'e.id')->where('ei.language_id', $languageId);
            })
            ->whereIn('e.agent_id', $agent->hierarchy_array)
            ->where('e.status', 1)
            ->where('e.type_id', self::EVENT_TYPE_JACKPOT)
            ->select([
                DB::raw('DATE_ADD(e.start_time, INTERVAL 8 HOUR) as start_time'),
                DB::raw('DATE_ADD(e.end_time, INTERVAL 8 HOUR) as end_time'),
                DB::raw('IFNULL(en.title, e.title) as title'),
                DB::raw('IFNULL(en.content, e.content) as content'),
                DB::raw('IFNULL(ei.image, p.background) as banner'),
                'p.max_point as jackpot',
            ])
            ->first();

        if (!$data) {
            return null;
        }

        $data->prize = $this->getMemberPrizes($member);

        return $data;
    }

    /**
     * show 內的個人獎項列表
     *
     * 沿用舊版條件：未領取（status = 0）**或** 更新時間早於今日 16:00 者皆列出。
     *
     * @return Collection<int, mixed>
     */
    private function getMemberPrizes(Member $member): Collection
    {
        $cutoff = now()->startOfDay()->addHours(16)->toDateTimeString();

        return DB::table('prize_members as pm')
            ->join('prizes as p', 'p.id', 'pm.prize_id')
            ->join('events as e', function ($join) {
                $join->on('e.id', 'p.event_id')
                    ->where('e.status', 1)
                    ->where('e.type_id', self::EVENT_TYPE_JACKPOT);
            })
            ->join('event_jackpots as ej', 'ej.id', 'pm.event_detail_id')
            ->where('pm.member_id', $member->id)
            ->where(function ($query) use ($cutoff) {
                $query->where('pm.status', 0)->orWhere('pm.updated_at', '<', $cutoff);
            })
            ->where(function ($query) {
                $query->where('pm.expired_time', '>=', now()->toDateTimeString())
                    ->orWhereNull('pm.expired_time');
            })
            ->orderBy('pm.content_id')
            ->select([
                'pm.id as id',
                'e.id as event_id',
                'pm.lottery_point as point',
                'ej.image',
                'pm.status',
                DB::raw('DATE_ADD(pm.expired_time, INTERVAL 8 HOUR) as expired_time'),
            ])
            ->get();
    }

    /**
     * event/jackpot/win_list - 當個營業日的中獎名單
     *
     * 營業日為 07:00 至隔日 07:00。帳號中間兩字以 `**` 遮蔽（規則見 Common::maskEventUsername）。
     *
     * @return list<array{image: mixed, username: string, point: mixed, created_at: mixed}>
     */
    public function getWinList(Agent $agent): array
    {
        [$start, $end] = $this->businessDayRange();

        $prizeId = DB::table('events as e')
            ->join('prizes as p', 'p.event_id', 'e.id')
            ->whereIn('e.agent_id', $agent->hierarchy_array)
            ->where('e.status', 1)
            ->where('e.type_id', self::EVENT_TYPE_JACKPOT)
            ->value('p.id');

        // 舊版此處直接讀 null 屬性，查無活動時會致命
        if (!$prizeId) {
            return [];
        }

        $rows = DB::table('prize_members as pm')
            ->join('prizes as p', 'p.id', 'pm.prize_id')
            ->join('events as e', function ($join) {
                $join->on('e.id', 'p.event_id')
                    ->where('e.status', 1)
                    ->where('e.type_id', self::EVENT_TYPE_JACKPOT);
            })
            ->join('members as m', 'm.id', 'pm.member_id')
            ->where('pm.prize_id', $prizeId)
            ->where('pm.created_at', '>=', $start)
            ->where('pm.created_at', '<=', $end)
            ->where('pm.status', 1)
            ->orderBy('pm.content_id')
            ->select([
                'm.image as image',
                'm.username as username',
                'pm.lottery_point as point',
                DB::raw('DATE_ADD(pm.created_at, INTERVAL 8 HOUR) as created_at'),
            ])
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'image' => $row->image,
                'username' => Common::maskEventUsername((string) $row->username),
                'point' => $row->point,
                'created_at' => $row->created_at,
            ];
        }

        return $result;
    }

    /**
     * 營業日區間：當下時間過 07:00 取今日 07:00 起算，否則取昨日 07:00 起算
     *
     * @return array{0: string, 1: string}
     */
    private function businessDayRange(): array
    {
        $boundary = now()->startOfDay()->addHours(self::BUSINESS_DAY_START_HOUR);

        if (now()->hour >= self::BUSINESS_DAY_START_HOUR) {
            return [$boundary->toDateTimeString(), $boundary->copy()->addDay()->toDateTimeString()];
        }

        return [$boundary->copy()->subDay()->toDateTimeString(), $boundary->toDateTimeString()];
    }

    /**
     * event/jackpot/receive - 領取積寶獎勵
     */
    public function receive(Member $member, Agent $agent, int $prizeMemberId): void
    {
        // 鎖分模式中禁止領取獎勵
        if ($member->event_mode > 0) {
            throw new ApiException(__('error_message.1934'));
        }

        // V2 活動進行中禁止領取 V1 獎勵
        if ($member->is_event_v2) {
            throw new ApiException(__('error_message.1991'));
        }

        // 新人專享挑戰活動進行中禁止領取 V1 獎勵
        if (NewbieChallengeProgress::where('member_id', $member->id)
            ->whereIn('stage', [
                NewbieChallengeStageEnum::CHALLENGING->value,
                NewbieChallengeStageEnum::BONUS_PENDING->value,
                NewbieChallengeStageEnum::BONUS_WAGERING->value,
            ])
            ->exists()) {
            throw new ApiException(__('error_message.1992'));
        }

        $agentSettings = $this->agentService->getAgentSettings($agent);
        if (!RealnameValidationService::isEventRewardRealnameValidated($agentSettings, $member)) {
            throw new ApiException(__('error_message.realname_invalid.event_reward'));
        }

        $ticket = DB::table('prize_members as pm')
            ->join('prizes as p', 'pm.prize_id', 'p.id')
            ->join('events as e', function ($join) {
                $join->on('e.id', 'p.event_id')
                    ->where('e.status', 1)
                    ->where('e.type_id', self::EVENT_TYPE_JACKPOT)
                    ->where('e.auto_payout', 0);
            })
            ->join('event_jackpots as ej', 'ej.id', 'pm.event_detail_id')
            ->where('pm.member_id', $member->id)
            ->where('pm.id', $prizeMemberId)
            ->select([
                'pm.id as id',
                'e.id as event_id',
                'pm.lottery_point as point',
                'pm.expired_time',
                'pm.status',
                'ej.flow_limit_multiple',
            ])
            ->first();

        if (!$ticket) {
            // 未中獎
            throw new ApiException(__('error_message.1946'));
        }

        if ($ticket->status == 1) {
            // 已領取
            throw new ApiException(__('error_message.1947'));
        }

        if (!is_null($ticket->expired_time) && now()->toDateTimeString() > $ticket->expired_time) {
            // 已過期
            throw new ApiException(__('error_message.1953'));
        }

        DB::transaction(function () use ($member, $ticket) {
            // 舊版只在交易外圍鎖 member，狀態未重驗；此處以條件式更新確保不會重複派彩
            $prizeMember = PrizeMember::query()
                ->where('id', $ticket->id)
                ->where('status', 0)
                ->lockForUpdate()
                ->first();

            if (!$prizeMember) {
                // 已領取
                throw new ApiException(__('error_message.1947'));
            }

            $point = Common::numAdd('0', (string) $ticket->point, 4);

            $lockedMember = Member::lockForUpdate()->find($member->id);
            $beforeBalance = Common::numAdd('0', (string) $lockedMember->balance, 4);
            $afterBalance = Common::numAdd($beforeBalance, $point, 4);
            $lockedMember->update(['balance' => $afterBalance]);

            $prizeMember->update(['status' => 1]);

            $log = TransactionLog::create([
                'transaction_no' => $ticket->id,
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'order_id' => $ticket->id,
                'type' => TransactionEnum::JACKPOT->value,
                'rate' => 1,
                'point' => $point,
                'before_balance' => $beforeBalance,
                'balance' => $afterBalance,
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'remark' => '',
                'operator' => 'system',
                'admin' => 0,
            ]);

            // 稽核流水倍數取自 event_jackpots.flow_limit_multiple
            if (Common::numCompare((string) $ticket->flow_limit_multiple, '0', 4) > 0) {
                FlowLimit::create([
                    'member_id' => $member->id,
                    'type' => TransactionEnum::JACKPOT->value,
                    'event_id' => $ticket->event_id,
                    'reference_id' => $log->id,
                    'flow_limit' => Common::numMul($point, (string) $ticket->flow_limit_multiple, 4),
                    'reach' => 0,
                    'status' => 0,
                    'admin' => 0,
                ]);
            }
        });
    }

    /**
     * event/jackpot/check_notify - 取得尚未通知的中獎彈窗
     *
     * @return Collection<int, mixed>
     */
    public function getNotifications(Member $member, int $languageId): Collection
    {
        return DB::table('event_jackpot_records as ejr')
            ->join('event_jackpots as ej', 'ej.id', 'ejr.event_jackpot_id')
            ->leftJoin('event_jackpot_images as eji', function ($join) use ($languageId) {
                $join->on('eji.event_jackpot_id', 'ej.id')->where('eji.language_id', $languageId);
            })
            ->where('ejr.member_id', $member->id)
            ->where('ejr.status', 0)
            ->select([
                'ejr.id as id',
                'ej.point as point',
                DB::raw('IFNULL(eji.image, ej.image) as image'),
            ])
            ->get();
    }

    /**
     * event/jackpot/confirm_notify - 確認已看過中獎彈窗
     *
     * 舊版只用 `EventJackpotRecord::find($request->id)` 未綁會員，任何人都能把別人的
     * 通知標記為已通知；此處補上 member_id 綁定。查無紀錄時沿用舊版靜默回成功。
     */
    public function confirmNotification(Member $member, int $recordId): void
    {
        $record = EventJackpotRecord::query()
            ->where('id', $recordId)
            ->where('member_id', $member->id)
            ->first();

        $record?->update(['status' => 1]);
    }
}
