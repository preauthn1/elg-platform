<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\EventSlotCompetitionRecord;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\NewbieChallengeProgress;
use App\Models\PrizeMember;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 老虎機爭霸賽（action 8）
 *
 * 遷移自 API_Frontend SlotCompetitionController。
 */
class SlotCompetitionService
{
    /** prize_contents.point_type：禮碼 */
    private const int POINT_TYPE_ROLLING_CHIP = 2;

    public function __construct(
        protected AgentService $agentService,
        protected EventService $eventService,
        protected WalletService $walletService,
    ) {}

    /**
     * event/slot_competition/list - 期數列表
     *
     * @return Collection<int, mixed>
     */
    public function getIssueList(Agent $agent): Collection
    {
        return DB::table('events as e')
            ->join('event_details as ed', function ($join) {
                $join->on('ed.event_id', 'e.id')
                    ->where('ed.start_time', '<=', now()->toDateTimeString())
                    ->where('ed.status', 1)
                    ->where('ed.action_id', EventActionIdEnum::SLOT_MACHINE_COMPETITION->value);
            })
            ->join('event_slot_competitions as esc', function ($join) {
                $join->on('esc.event_id', 'e.id')->on('esc.event_detail_id', 'ed.id');
            })
            ->whereIn('e.agent_id', $agent->hierarchy_array)
            ->where('e.status', 1)
            ->orderByDesc('esc.no')
            ->select([
                'e.id as event_id',
                'esc.id as id',
                'esc.no as no',
                DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) as start_time'),
                DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) as end_time'),
            ])
            ->get();
    }

    /**
     * event/slot_competition/leader_board - 排行榜
     *
     * 未在名次內時，於列表尾端補上自己的紀錄，名次顯示 `--`。
     *
     * @return list<array{ranking: mixed, username: string, bet_amount: mixed, last_bet_time: mixed}>
     */
    public function getLeaderBoard(Member $member, Agent $agent, int $eventId, int $no, ?int $page, ?int $perPage): array
    {
        $this->eventService->assertEventAccessible($eventId, $agent);

        // 取得獎品數量作為預設每頁筆數
        $prizeNumber = (int) DB::table('prizes as p')
            ->join('prize_contents as pc', function ($join) {
                $join->on('pc.prize_id', 'p.id')->where('pc.status', 1);
            })
            ->where('p.event_id', $eventId)
            ->where('p.status', 1)
            ->sum('pc.number');

        $page = $page ?: 1;
        $perPage = $perPage ?: $prizeNumber;

        $query = $this->leaderBoardQuery($eventId, $no);

        // 無啟用獎項時 $prizeNumber 為 0：舊版 SUM() 回 NULL 使 take(null) 不產生 LIMIT，
        // 等同不分頁撈出全部名次；此處不可退化成 LIMIT 0，否則排行榜整張消失
        $rows = $perPage > 0 ? $query->forPage($page, $perPage)->get() : $query->get();

        $result = [];
        if ($rows->isEmpty()) {
            return $result;
        }

        $hasSelf = false;
        foreach ($rows as $row) {
            if ((int) $row->member_id === $member->id) {
                $hasSelf = true;
            } else {
                // 帳號遮蔽規則見 Common::maskEventUsername()；兩分支差異與採 vw 規則的理由見遷移文件 3-1
                $row->username = Common::maskEventUsername((string) $row->username);
            }

            $result[] = [
                'ranking' => $row->ranking,
                'username' => $row->username,
                'bet_amount' => $row->bet_amount,
                'last_bet_time' => $row->last_bet_time,
            ];
        }

        if ($hasSelf) {
            return $result;
        }

        // 自己不在名次內時補在最後
        $self = $this->leaderBoardQuery($eventId, $no, $member->id)->get();
        foreach ($self as $row) {
            $result[] = [
                'ranking' => '--',
                'username' => $row->username,
                'bet_amount' => $row->bet_amount,
                'last_bet_time' => $row->last_bet_time,
            ];
        }

        return $result;
    }

    /**
     * event/slot_competition/ranking - 我的排名
     *
     * @return Collection<int, mixed>
     */
    public function getMyRanking(Member $member, Agent $agent, int $eventId): Collection
    {
        $this->eventService->assertEventAccessible($eventId, $agent);

        return DB::table('event_slot_competitions as esc')
            ->join('event_details as ed', function ($join) {
                $join->on('ed.id', 'esc.event_detail_id')
                    ->where('ed.status', 1)
                    ->where('ed.action_id', EventActionIdEnum::SLOT_MACHINE_COMPETITION->value);
            })
            ->leftJoin('event_slot_competition_records as escr', function ($join) use ($member) {
                $join->on('escr.event_slot_competition_id', 'esc.id')->where('escr.member_id', $member->id);
            })
            ->where('esc.event_id', $eventId)
            ->where('esc.draw_time', '<=', now()->toDateTimeString())
            ->orderByDesc('esc.no')
            ->select([
                'ed.event_id',
                'esc.no',
                DB::raw('DATE_ADD(esc.draw_time, INTERVAL 8 HOUR) as draw_time'),
                DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) as start_time'),
                DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) as end_time'),
                'escr.bet_amount',
                'escr.ranking',
                'escr.type',
                // 超過領取期限且未領取者顯示為 2（已逾期）
                DB::raw('CASE WHEN (DATE_ADD(esc.draw_time, INTERVAL ed.expire_hour HOUR) < NOW() AND escr.status = 0) THEN 2 ELSE escr.status END as status'),
                DB::raw('DATE_ADD(escr.last_bet_time, INTERVAL 8 HOUR) as last_bet_time'),
                DB::raw('CASE WHEN escr.type = 1 THEN escr.point ELSE null END AS point'),
            ])
            ->get();
    }

    /**
     * event/slot_competition/awards - 獎項列表
     *
     * @return array{content: string|null, prizes: Collection<int, mixed>}
     */
    public function getAwards(Agent $agent, int $eventId, int $languageId): array
    {
        $this->eventService->assertEventAccessible($eventId, $agent);

        $content = DB::table('events as e')
            ->leftJoin('event_names as en', function ($join) use ($languageId) {
                $join->on('en.event_id', 'e.id')->where('en.language_id', $languageId);
            })
            ->where('e.id', $eventId)
            ->select(DB::raw('(CASE WHEN en.content is NULL THEN e.content ELSE en.content END) as content'))
            ->first();

        $now = now()->toDateTimeString();
        $prizes = DB::table('events as e')
            ->join('event_details as ed', function ($join) use ($now) {
                $join->on('ed.event_id', 'e.id')
                    ->where('ed.status', 1)
                    ->where('ed.action_id', EventActionIdEnum::SLOT_MACHINE_COMPETITION->value)
                    ->where('ed.start_time', '<=', $now)
                    ->where('ed.end_time', '>=', $now);
            })
            ->join('prizes as p', function ($join) {
                $join->on('p.event_id', 'e.id')->where('p.status', 1);
            })
            ->join('prize_contents as pc', function ($join) {
                $join->on('pc.prize_id', 'p.id')->where('pc.status', 1);
            })
            ->leftJoin('prize_content_names as pcn', function ($join) use ($languageId) {
                $join->on('pcn.prize_content_id', 'pc.id')->where('pcn.language_id', $languageId);
            })
            ->where('e.id', $eventId)
            ->where('e.status', 1)
            ->orderBy('pc.level')
            ->select([
                'e.id as event_id',
                DB::raw('IFNULL(pcn.name, pc.name) AS name'),
                'pc.level',
                'pc.number',
                'pc.point',
            ])
            ->get();

        // 舊版查無活動時直接讀 null 屬性而致命
        return ['content' => $content->content ?? null, 'prizes' => $prizes];
    }

    /**
     * event/slot_competition/receive - 領取獎項
     */
    public function receive(Member $member, Agent $agent, int $eventId, int $no): void
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

        $ticket = DB::table('event_slot_competitions as esc')
            ->join('events as e', function ($join) {
                $join->on('e.id', 'esc.event_id')->where('e.status', 1)->where('e.auto_payout', 0);
            })
            ->join('event_details as ed', function ($join) {
                $join->on('ed.id', 'esc.event_detail_id')->on('ed.event_id', 'e.id')->where('ed.status', 1);
            })
            ->join('prizes as p', function ($join) {
                $join->on('p.event_id', 'e.id')->where('p.status', 1);
            })
            ->join('prize_contents as pc', function ($join) {
                $join->on('pc.prize_id', 'p.id')->where('pc.status', 1);
            })
            ->join('event_slot_competition_records as escr', function ($join) use ($member) {
                $join->on('escr.event_slot_competition_id', 'esc.id')
                    ->on('escr.prize_content_id', 'pc.id')
                    ->where('escr.member_id', $member->id)
                    ->where('escr.type', 1); // 已中獎
            })
            ->where('esc.event_id', $eventId)
            ->where('esc.no', $no)
            ->where('esc.status', 1)
            ->select([
                'e.id as event_id',
                'escr.id as record_id',
                'escr.type',
                'escr.point',
                'escr.prize_content_id',
                'p.id as prize_id',
                'p.name as prize_name',
                'p.flow_limit_multiple',
                'pc.point_type', // 1: 平台點數, 2: 禮碼
                DB::raw('DATE_ADD(esc.draw_time, INTERVAL ed.expire_hour HOUR) as expired_time'),
            ])
            ->first();

        if (!$ticket || ($ticket->prize_content_id == 0 && $ticket->type == 0)) {
            // 未中獎
            throw new ApiException(__('error_message.1946'));
        }

        if (now()->toDateTimeString() > $ticket->expired_time) {
            // 已過期
            throw new ApiException(__('error_message.1948'));
        }

        DB::transaction(function () use ($member, $ticket) {
            $record = EventSlotCompetitionRecord::lockForUpdate()->find($ticket->record_id);
            if ($record->status > 0) {
                // 已領取
                throw new ApiException(__('error_message.1947'));
            }

            $point = Common::numAdd('0', (string) $ticket->point, 4);

            PrizeMember::create([
                'member_id' => $member->id,
                'prize_id' => $ticket->prize_id,
                'content_id' => $ticket->prize_content_id,
                'lottery_point' => $point,
                'status' => 1,
                'admin' => 0,
            ]);

            if ($ticket->point_type == self::POINT_TYPE_ROLLING_CHIP) {
                $this->creditRollingChip($member, $ticket, $point);
            } else {
                $this->creditPlatformPoint($member, $ticket, $point);
            }

            $record->update(['status' => 1, 'time' => now()->toDateTimeString()]);
        });
    }

    /**
     * 派發禮碼
     */
    private function creditRollingChip(Member $member, object $ticket, string $point): void
    {
        $wallet = $this->walletService->getLockedWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);
        $beforeBalance = Common::numAdd('0', (string) $wallet->balance, 4);
        $afterBalance = Common::numAdd($beforeBalance, $point, 4);
        $wallet->update(['balance' => $afterBalance]);

        RollingChipLog::create([
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'type' => RollingChipLogTypeEnum::SLOT_COMPETITION->value,
            'amount' => $point,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => $ticket->prize_name,
            'admin' => 0,
        ]);
    }

    /**
     * 派發平台點數並寫入稽核流水
     */
    private function creditPlatformPoint(Member $member, object $ticket, string $point): void
    {
        $lockedMember = Member::lockForUpdate()->find($member->id);
        $beforeBalance = Common::numAdd('0', (string) $lockedMember->balance, 4);
        $afterBalance = Common::numAdd($beforeBalance, $point, 4);
        $lockedMember->update(['balance' => $afterBalance]);

        $log = TransactionLog::create([
            'transaction_no' => $ticket->record_id,
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'order_id' => $ticket->record_id,
            'type' => TransactionEnum::SLOT_BATTLE->value,
            'rate' => 1,
            'point' => $point,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => '',
            'operator' => 'system',
            'admin' => 0,
        ]);

        // 沿用舊版：稽核流水僅在派發平台點數時寫入，禮碼不寫
        if (Common::numCompare((string) $ticket->flow_limit_multiple, '0', 4) > 0) {
            FlowLimit::create([
                'member_id' => $member->id,
                'type' => TransactionEnum::SLOT_BATTLE->value,
                'event_id' => $ticket->event_id,
                'reference_id' => $log->id,
                'flow_limit' => Common::numMul($point, (string) $ticket->flow_limit_multiple, 4),
                'reach' => 0,
                'status' => 0,
                'admin' => 0,
            ]);
        }
    }

    /**
     * 排行榜查詢；帶 $memberId 時只取該會員
     */
    private function leaderBoardQuery(int $eventId, int $no, ?int $memberId = null): \Illuminate\Database\Query\Builder
    {
        return DB::table('event_slot_competitions as esc')
            ->join('event_slot_competition_records as escr', 'escr.event_slot_competition_id', 'esc.id')
            ->join('members as m', function ($join) use ($memberId) {
                $join->on('m.id', 'escr.member_id')
                    ->where('m.status', 1)
                    ->when($memberId !== null, fn ($query) => $query->where('m.id', $memberId));
            })
            ->where('esc.event_id', $eventId)
            ->where('esc.no', $no)
            ->orderByDesc('escr.bet_amount')
            ->orderBy('last_bet_time')
            ->select([
                'ranking',
                'm.id as member_id',
                'm.username',
                DB::raw('TRUNCATE(escr.bet_amount, 2) as bet_amount'),
                DB::raw('DATE_ADD(last_bet_time, INTERVAL 8 HOUR) as last_bet_time'),
            ]);
    }
}
