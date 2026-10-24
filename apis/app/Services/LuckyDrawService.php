<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\FlowLimit;
use App\Models\LuckyDraw;
use App\Models\LuckyDrawTicket;
use App\Models\Member;
use App\Models\NewbieChallengeProgress;
use App\Models\Prize;
use App\Models\PrizeMember;
use App\Models\TransactionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LuckyDrawService
{
    /**
     * event/lucky-draw/issues - 抽獎活動期數列表
     */
    public function getIssueList(Agent $agent): Collection
    {
        $now = now();
        $agentIds = $agent->hierarchy_array;

        $list = LuckyDraw::query()
            ->join('events as e', 'e.id', 'lucky_draws.event_id')
            ->join('event_details as ed', function ($join) use ($now) {
                $join->on('ed.id', 'lucky_draws.event_detail_id')
                    ->where('ed.start_time', '<=', $now);
            })
            ->where(function ($query) use ($agentIds) {
                $query->whereNull('e.agent_id')
                    ->orWhereIn('e.agent_id', $agentIds);
            })
            ->where('e.status', 1)
            ->orderByDesc('lucky_draws.no')
            ->select(['lucky_draws.id as issue_id', 'lucky_draws.no as issue_no', 'lucky_draws.draw_time'])
            ->get();

        return $list->map(function ($item) {
            return [
                'issue_id' => $item->issue_id,
                'issue_name' => str_replace('{N}', (string) $item->issue_no, __('param.periods')),
                'draw_time' => $item->draw_time ? Carbon::parse($item->draw_time)->toFrontendTime()->toDateTimeString() : null,
            ];
        });
    }

    /**
     * event/lucky-draw/issues/detail - 抽獎活動明細
     */
    public function getIssueDetail(int $issueId, int $memberId): array
    {
        $luckyDraw = LuckyDraw::query()
            ->join('event_details as ed', 'ed.id', 'lucky_draws.event_detail_id')
            ->where('lucky_draws.id', $issueId)
            ->select([
                'lucky_draws.id as issue_id',
                'lucky_draws.no as issue_no',
                'lucky_draws.draw_time',
                'lucky_draws.draw_number',
                'lucky_draws.status',
                'ed.start_time',
                'ed.end_time',
                'ed.draw_time as event_detail_draw_time',
                'ed.expire_hour',
            ])
            ->first();

        if (!$luckyDraw) {
            throw new ApiException('Issue not found', 404);
        }

        $summary = LuckyDrawTicket::query()
            ->where('lucky_draw_id', $issueId)
            ->where('member_id', $memberId)
            ->selectRaw('COUNT(*) as total_tickets')
            ->selectRaw('TRUNCATE(IFNULL(SUM(point), 0), 2) as total_winnings')
            ->selectRaw('TRUNCATE(SUM(CASE WHEN status = 0 THEN IFNULL(point, 0) ELSE 0 END), 2) as total_unclaimed')
            ->first();

        $expireDate = $luckyDraw->expire_hour > 0
            ? Carbon::parse($luckyDraw->event_detail_draw_time)->addHours($luckyDraw->expire_hour)
            : Carbon::parse($luckyDraw->event_detail_draw_time)->addYears(30);

        // 領獎時間已過期, 未領取金額不再顯示
        $unclaimed = $expireDate->lt(now()) ? '0' : (string) ($summary->total_unclaimed ?? 0);

        return [
            'issue_id' => $luckyDraw->issue_id,
            'issue_name' => str_replace('{N}', (string) $luckyDraw->issue_no, __('param.Lucky_draw_periods')),
            'begin_time' => Carbon::parse($luckyDraw->start_time)->toFrontendTime()->toDateTimeString(),
            'end_time' => Carbon::parse($luckyDraw->end_time)->toFrontendTime()->toDateTimeString(),
            'draw_time' => $luckyDraw->draw_time ? Carbon::parse($luckyDraw->draw_time)->toFrontendTime()->toDateTimeString() : null,
            'result_number' => $luckyDraw->status == 1 ? $luckyDraw->draw_number : '-------',
            'summary' => [
                'totalCount_tickets' => $summary->total_tickets ?? 0,
                'totalCount_winnings' => (string) ($summary->total_winnings ?? 0),
                'totalCount_unclaimed' => $unclaimed,
            ],
        ];
    }

    /**
     * event/lucky-draw/issues/tickets - 每期抽獎券明細
     */
    public function getIssueTickets(int $issueId, int $memberId, int $languageId, int $perPage, int $page): array
    {
        $luckyDraw = LuckyDraw::query()
            ->join('event_details as ed', 'ed.id', 'lucky_draws.event_detail_id')
            ->where('lucky_draws.id', $issueId)
            ->select(['lucky_draws.draw_number', 'lucky_draws.status', 'ed.draw_time', 'ed.expire_hour'])
            ->first();

        if (!$luckyDraw) {
            throw new ApiException('Issue not found', 404);
        }

        $expireDate = $luckyDraw->expire_hour > 0
            ? Carbon::parse($luckyDraw->draw_time)->addHours($luckyDraw->expire_hour)
            : Carbon::parse($luckyDraw->draw_time)->addYears(30);

        $paginator = LuckyDrawTicket::query()
            ->leftJoin('reward_items as ri', 'ri.id', 'lucky_draw_tickets.reward_item_id')
            ->leftJoin('reward_item_names as rin', function ($join) use ($languageId) {
                $join->on('rin.type_id', 'ri.id')
                    ->where('rin.language_id', $languageId);
            })
            ->where('lucky_draw_tickets.lucky_draw_id', $issueId)
            ->where('lucky_draw_tickets.member_id', $memberId)
            ->orderByDesc('lucky_draw_tickets.type')
            ->orderBy('ri.level')
            ->orderBy('lucky_draw_tickets.id')
            ->select([
                'lucky_draw_tickets.id as ticket_id',
                'lucky_draw_tickets.ticket_number',
                'lucky_draw_tickets.point',
                'lucky_draw_tickets.type',
                'lucky_draw_tickets.status',
                'ri.type as reward_type',
                'ri.level as reward_level',
                'ri.prize_name as reward_prize_name',
                'rin.prize_name as reward_prize_name_lang',
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(function ($ticket) use ($luckyDraw, $expireDate) {
            return [
                'ticket_id' => $ticket->ticket_id,
                'ticket_number' => $ticket->ticket_number,
                'reward_type' => $ticket->reward_type,
                'reward_level' => $ticket->reward_level ?? 0,
                'reward_amount' => Common::numAdd('0', (string) ($ticket->point ?? 0), 2),
                'reward_name' => $ticket->reward_type == 2 ? ($ticket->reward_prize_name_lang ?: $ticket->reward_prize_name) : '',
                'type' => $ticket->type,
                'status' => $this->resolveTicketStatus($ticket, $luckyDraw, $expireDate),
                'statusText' => $this->resolveTicketStatusText($ticket, $luckyDraw, $expireDate),
            ];
        });

        return [
            'result_number' => $luckyDraw->draw_number ?: '',
            'paginator' => $paginator,
        ];
    }

    /**
     * event/lucky-draw/issues/receive - 領取獎項
     */
    public function receive(Member $member, int $ticketId): void
    {
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

        DB::transaction(function () use ($member, $ticketId) {
            $lockedMember = Member::where('id', $member->id)->lockForUpdate()->first();

            $ticket = LuckyDrawTicket::query()
                ->join('reward_items as ri', 'ri.id', 'lucky_draw_tickets.reward_item_id')
                ->join('lucky_draws as ld', 'ld.id', 'lucky_draw_tickets.lucky_draw_id')
                ->join('events as e', function ($join) {
                    $join->on('e.id', 'ld.event_id')->where('e.auto_payout', 0);
                })
                ->where('lucky_draw_tickets.id', $ticketId)
                ->where('lucky_draw_tickets.member_id', $lockedMember->id)
                ->select([
                    'ld.event_id as event_id',
                    'lucky_draw_tickets.reward_item_id',
                    'ri.type as reward_type',
                    'lucky_draw_tickets.point',
                    'lucky_draw_tickets.type',
                    'lucky_draw_tickets.status',
                ])
                ->first();

            if (!$ticket) {
                throw new ApiException(__('error_message.1941'));
            }

            if ($ticket->reward_item_id == 0 && $ticket->type == 0) {
                throw new ApiException(__('error_message.1942'));
            }

            if ($ticket->status == 1) {
                throw new ApiException(__('error_message.1943'));
            }

            if ($ticket->reward_type == 2) {
                throw new ApiException(__('error_message.1944'));
            }

            $prize = Prize::where('event_id', $ticket->event_id)->where('status', 1)->first();
            if (!$prize) {
                throw new ApiException(__('error_message.2001'));
            }

            $updated = LuckyDrawTicket::where('id', $ticketId)
                ->where('status', 0)
                ->update(['status' => 1, 'time' => now()]);
            // 沿用舊版：併發下已被搶先領取時回 2001（而非 1943）
            if (!$updated) {
                throw new ApiException(__('error_message.2001'));
            }

            // member_id 有極少數情況會因不可控原因被存成 0，查詢時放寬允許 member_id=0，並在更新時補回正確的 member_id
            $prizeMember = PrizeMember::where('prize_id', $prize->id)
                ->where('lottery_number', $ticketId)
                ->where('status', 0)
                ->where(function ($query) use ($lockedMember) {
                    $query->where('member_id', $lockedMember->id)->orWhere('member_id', 0);
                })
                ->lockForUpdate()
                ->first();
            if (!$prizeMember) {
                throw new ApiException(__('error_message.2001'));
            }
            $prizeMember->update(['status' => 1, 'member_id' => $lockedMember->id]);

            $this->creditReward($lockedMember, (string) $ticket->point, $ticket->event_id, $ticketId);
        });
    }

    /**
     * event/lucky-draw/issues/receive-all - 該期獎項全部領取
     */
    public function receiveAll(Member $member, int $issueId): void
    {
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

        DB::transaction(function () use ($member, $issueId) {
            $lockedMember = Member::where('id', $member->id)->lockForUpdate()->first();

            $tickets = LuckyDrawTicket::query()
                ->join('lucky_draws as ld', 'ld.id', 'lucky_draw_tickets.lucky_draw_id')
                ->where('lucky_draw_tickets.lucky_draw_id', $issueId)
                ->where('lucky_draw_tickets.member_id', $lockedMember->id)
                ->where('lucky_draw_tickets.reward_item_id', '>', 0)
                ->where('lucky_draw_tickets.status', 0)
                ->where('lucky_draw_tickets.point', '>', 0)
                ->select(['ld.event_id as event_id', 'lucky_draw_tickets.id as ticket_id', 'lucky_draw_tickets.point'])
                ->get();

            if ($tickets->isEmpty()) {
                throw new ApiException(__('error_message.1000'));
            }

            // 同一期的票券 event_id 恆定，獎項只需查一次
            $prize = Prize::where('event_id', $tickets->first()->event_id)->where('status', 1)->first();
            if (!$prize) {
                throw new ApiException(__('error_message.2001'));
            }

            foreach ($tickets as $ticket) {
                $updated = LuckyDrawTicket::where('id', $ticket->ticket_id)
                    ->where('status', 0)
                    ->update(['status' => 1, 'time' => now()]);
                if (!$updated) {
                    continue;
                }

                $prizeMember = PrizeMember::where('prize_id', $prize->id)
                    ->where('lottery_number', $ticket->ticket_id)
                    ->where('status', 0)
                    ->where(function ($query) use ($lockedMember) {
                        $query->where('member_id', $lockedMember->id)->orWhere('member_id', 0);
                    })
                    ->lockForUpdate()
                    ->first();
                if (!$prizeMember) {
                    throw new ApiException(__('error_message.2001'));
                }
                $prizeMember->update(['status' => 1, 'member_id' => $lockedMember->id]);

                $this->creditReward($lockedMember, (string) $ticket->point, $ticket->event_id, $ticket->ticket_id);
                $lockedMember->refresh();
            }
        });
    }

    /**
     * 派發獎勵金額至會員主錢包，並寫入交易紀錄與稽核流水
     *
     * 沿用舊版行為：直接記入 Member.balance，不分流多錢包。
     * apis 另有 WalletService::getOrCreateWallet()／Agent::is_multi_wallet 多錢包分流機制（見 CheckinService），
     * 依遷移原則本次先保持舊版行為一致，是否整合待遷移與測試完成後再評估。
     */
    private function creditReward(Member $lockedMember, string $amount, int $eventId, int $ticketId): void
    {
        $agent = Agent::find($lockedMember->parent);
        $flowLimitRate = Common::numAdd('0', (string) $agent->getSettingValue(AgentCustomizedSettingKeyEnum::EVENT_FLOW_LIMIT_RATE), 4);

        $beforeBalance = $lockedMember->balance;
        $lockedMember->balance = Common::numAdd($beforeBalance, $amount, 4);
        $lockedMember->save();

        $log = TransactionLog::create([
            'transaction_no' => (string) $ticketId,
            'order_id' => $ticketId,
            'agent_id' => $agent->id,
            'member_id' => $lockedMember->id,
            'point' => $amount,
            'before_balance' => $beforeBalance,
            'balance' => $lockedMember->balance,
            'rate' => 1,
            'vendor_point' => 0,
            'remark' => '',
            'operator' => (string) $lockedMember->id,
            'type' => TransactionEnum::V_JACKPOT_REWARDS->value,
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
            'admin' => 0,
        ]);

        if (Common::numCompare($flowLimitRate, '0', 4) > 0) {
            FlowLimit::create([
                'member_id' => $lockedMember->id,
                // 沿用舊版：flow_limits.type 存 TransactionEnum 值（非 reference 類型 1）
                'type' => TransactionEnum::V_JACKPOT_REWARDS->value,
                'event_id' => $eventId,
                'reference_id' => $log->id,
                'wallet_transaction_log_id' => 0,
                'flow_limit' => Common::numMul($amount, $flowLimitRate, 4),
                'reach' => 0,
                'admin' => 0,
            ]);
        }
    }

    private function resolveTicketStatus(LuckyDrawTicket $ticket, LuckyDraw $luckyDraw, Carbon $expireDate): int
    {
        // 0:未開獎, 1:已開獎-已領取, 2:已開獎-未領取, 3:已開獎-沒中獎, 4:已開獎-已過期
        if (now()->gt($expireDate)) {
            return 4;
        }
        if ($luckyDraw->status == 0) {
            return 0;
        }
        if ($ticket->status == 1) {
            return 1;
        }
        if ($ticket->type == 0) {
            return 3;
        }

        return 2;
    }

    private function resolveTicketStatusText(LuckyDrawTicket $ticket, LuckyDraw $luckyDraw, Carbon $expireDate): string
    {
        if (now()->gt($expireDate)) {
            return __('param.past_due');
        }
        if ($luckyDraw->status == 0) {
            return __('param.no_draw');
        }
        if ($ticket->type == 0) {
            return __('param.did_not_win');
        }
        if ($ticket->status == 1) {
            return __('param.received');
        }

        return __('param.unaccalimed');
    }
}
