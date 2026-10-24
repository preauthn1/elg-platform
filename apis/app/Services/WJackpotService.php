<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Event\PrizeTypeEnum;
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
use App\Models\TransactionLog;
use App\Models\WJackpot;
use App\Models\WJackpotTicket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * W-Jackpot 與 W-Jackpot 6D 抽獎券活動
 *
 * 遷移自 API_Frontend WJackpotController（tpower-master / vw-master 兩分支內容完全一致）。
 * - W-Jackpot 走 w_jackpots / w_jackpot_tickets / w_jackpot_reward_items
 * - W-Jackpot 6D 走 lucky_draws / lucky_draw_tickets / reward_items，以 PrizeTypeEnum::W_JACKPOT_6D 區分
 *
 * 沿用舊版行為：獎金直接記入 Member.balance，稽核流水倍數取自 prizes.flow_limit_multiple
 * （與 LuckyDrawService 取 agent 設定 EVENT_FLOW_LIMIT_RATE 不同，兩者不可互換）。
 */
class WJackpotService
{
    /** issues_1272 [GFF][WTT] 4D、W-jackpot 新增領取時間限制：期數列表只回最近 3 期 */
    private const int ISSUE_LIST_LIMIT = 3;

    public function __construct(
        private AgentService $agentService,
    ) {}

    /**
     * event/wjackpot/issues - W-Jackpot 期數列表
     */
    public function getIssueList(Agent $agent): Collection
    {
        $now = now();
        $agentIds = $agent->hierarchy_array;

        $list = WJackpot::query()
            ->join('events as e', 'e.id', 'w_jackpots.event_id')
            ->join('event_details as ed', function ($join) use ($now) {
                $join->on('ed.id', 'w_jackpots.event_detail_id')
                    ->where('ed.start_time', '<=', $now);
            })
            ->where(function ($query) use ($agentIds) {
                $query->whereNull('e.agent_id')
                    ->orWhereIn('e.agent_id', $agentIds);
            })
            ->where('e.status', 1)
            ->orderByDesc('w_jackpots.no')
            ->limit(self::ISSUE_LIST_LIMIT)
            ->select(['w_jackpots.id as issue_id', 'w_jackpots.no as issue_no', 'w_jackpots.draw_time'])
            ->get();

        return $this->formatIssueList($list);
    }

    /**
     * event/wjackpot6d/issues - W-Jackpot 6D 期數列表
     */
    public function get6dIssueList(Agent $agent): Collection
    {
        $now = now();
        $agentIds = $agent->hierarchy_array;

        $list = LuckyDraw::query()
            ->join('events as e', 'e.id', 'lucky_draws.event_id')
            ->join('prizes as p', function ($join) {
                $join->on('p.event_id', 'e.id')
                    ->where('p.type_id', PrizeTypeEnum::W_JACKPOT_6D->value)
                    ->where('p.status', 1);
            })
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
            ->limit(self::ISSUE_LIST_LIMIT)
            ->select(['lucky_draws.id as issue_id', 'lucky_draws.no as issue_no', 'lucky_draws.draw_time'])
            ->get();

        return $this->formatIssueList($list);
    }

    /**
     * event/wjackpot/issues/detail - W-Jackpot 期數明細
     */
    public function getIssueDetail(int $issueId, int $memberId): array
    {
        $issue = WJackpot::query()
            ->join('event_details as ed', 'ed.id', 'w_jackpots.event_detail_id')
            ->where('w_jackpots.id', $issueId)
            ->select([
                'w_jackpots.id as issue_id',
                'w_jackpots.no as issue_no',
                'w_jackpots.draw_time',
                'w_jackpots.draw_number',
                'w_jackpots.draw_number_2',
                'w_jackpots.draw_number_3',
                'w_jackpots.draw_number_4',
                'w_jackpots.draw_number_5',
                'w_jackpots.status',
                'ed.start_time',
                'ed.end_time',
                'ed.draw_time as event_detail_draw_time',
                'ed.expire_hour',
            ])
            ->first();

        if (!$issue) {
            throw new ApiException(__('error_message.1941'));
        }

        $summary = $this->summarizeTickets(
            WJackpotTicket::query()->where('w_jackpot_id', $issueId),
            $memberId,
            $this->resolveExpireDate($issue->event_detail_draw_time, (int) $issue->expire_hour),
        );

        // 未開獎時開獎號碼以 '----' 佔位（6D 為 '------'、V-Jackpot 為 '-------'，位數不同不可共用）
        $drawn = $issue->status == 1;
        $placeholder = '----';

        return [
            'issue_id' => $issue->issue_id,
            'issue_name' => str_replace('{N}', (string) $issue->issue_no, __('param.w_jackpot_periods')),
            'begin_time' => $this->toFrontendTime($issue->start_time),
            'end_time' => $this->toFrontendTime($issue->end_time),
            'draw_time' => $this->toFrontendTime($issue->draw_time),
            'result_number' => $drawn ? $issue->draw_number : $placeholder,
            'result_number_2' => $drawn ? $issue->draw_number_2 : $placeholder,
            'result_number_3' => $drawn ? $issue->draw_number_3 : $placeholder,
            'result_number_4' => explode(',', (string) ($drawn ? $issue->draw_number_4 : $placeholder)),
            'result_number_5' => explode(',', (string) ($drawn ? $issue->draw_number_5 : $placeholder)),
            'summary' => $summary,
        ];
    }

    /**
     * event/wjackpot6d/issues/detail - W-Jackpot 6D 期數明細
     */
    public function get6dIssueDetail(int $issueId, int $memberId): array
    {
        $issue = LuckyDraw::query()
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

        if (!$issue) {
            throw new ApiException(__('error_message.1941'));
        }

        $summary = $this->summarizeTickets(
            LuckyDrawTicket::query()->where('lucky_draw_id', $issueId),
            $memberId,
            $this->resolveExpireDate($issue->event_detail_draw_time, (int) $issue->expire_hour),
        );

        return [
            'issue_id' => $issue->issue_id,
            'issue_name' => str_replace('{N}', (string) $issue->issue_no, __('param.w_jackpot_periods')),
            'begin_time' => $this->toFrontendTime($issue->start_time),
            'end_time' => $this->toFrontendTime($issue->end_time),
            'draw_time' => $this->toFrontendTime($issue->draw_time),
            'result_number' => $issue->status == 1 ? $issue->draw_number : '------',
            'summary' => $summary,
        ];
    }

    /**
     * event/wjackpot/issues/tickets - W-Jackpot 每期抽獎券明細
     */
    public function getIssueTickets(int $issueId, int $memberId, int $languageId, int $perPage, int $page): array
    {
        $issue = WJackpot::query()
            ->join('event_details as ed', function ($join) {
                $join->on('ed.id', 'w_jackpots.event_detail_id')
                    ->where('ed.status', 1);
            })
            ->where('w_jackpots.id', $issueId)
            ->select([
                'w_jackpots.draw_number',
                'w_jackpots.draw_number_2',
                'w_jackpots.draw_number_3',
                'w_jackpots.draw_number_4',
                'w_jackpots.draw_number_5',
                'w_jackpots.status',
                'ed.draw_time',
                'ed.expire_hour',
            ])
            ->first();

        if (!$issue) {
            throw new ApiException(__('error_message.1941'));
        }

        $expireDate = $this->resolveExpireDate($issue->draw_time, (int) $issue->expire_hour);

        $paginator = WJackpotTicket::query()
            ->leftJoin('w_jackpot_reward_items as wjri', 'wjri.id', 'w_jackpot_tickets.reward_item_id')
            ->leftJoin('w_jackpot_reward_item_names as wjrin', function ($join) use ($languageId) {
                $join->on('wjrin.w_jackpot_reward_item_id', 'wjri.id')
                    ->where('wjrin.language_id', $languageId);
            })
            ->where('w_jackpot_tickets.w_jackpot_id', $issueId)
            ->where('w_jackpot_tickets.member_id', $memberId)
            ->orderByDesc('w_jackpot_tickets.type')
            ->orderBy('wjri.level')
            ->orderBy('w_jackpot_tickets.id')
            ->select([
                'w_jackpot_tickets.id as ticket_id',
                'w_jackpot_tickets.ticket_number',
                'w_jackpot_tickets.point',
                'w_jackpot_tickets.type',
                'w_jackpot_tickets.status',
                'wjri.type as reward_type',
                'wjri.level as reward_level',
                'wjri.prize_name as reward_prize_name',
                'wjrin.prize_name as reward_prize_name_lang',
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(
            fn ($ticket) => $this->formatTicket($ticket, (int) $issue->status, $expireDate)
        );

        return [
            'result_number' => $issue->draw_number ?: '',
            'result_number_2' => $issue->draw_number_2 ?: '',
            'result_number_3' => $issue->draw_number_3 ?: '',
            'result_number_4' => explode(',', (string) $issue->draw_number_4),
            'result_number_5' => explode(',', (string) $issue->draw_number_5),
            'paginator' => $paginator,
        ];
    }

    /**
     * event/wjackpot6d/issues/tickets - W-Jackpot 6D 每期抽獎券明細
     */
    public function get6dIssueTickets(int $issueId, int $memberId, int $languageId, int $perPage, int $page): array
    {
        $issue = LuckyDraw::query()
            ->join('event_details as ed', function ($join) {
                $join->on('ed.id', 'lucky_draws.event_detail_id')
                    ->where('ed.status', 1);
            })
            ->where('lucky_draws.id', $issueId)
            ->select(['lucky_draws.draw_number', 'lucky_draws.status', 'ed.draw_time', 'ed.expire_hour'])
            ->first();

        if (!$issue) {
            throw new ApiException(__('error_message.1941'));
        }

        $expireDate = $this->resolveExpireDate($issue->draw_time, (int) $issue->expire_hour);
        // 6D 另有「已中獎但超過 draw_time + expire_hour」的逾期判定（一般抽獎券無此分支）
        $rewardExpired = Carbon::parse($issue->draw_time)->addHours((int) $issue->expire_hour)->lt(now());

        $paginator = LuckyDrawTicket::query()
            ->join('lucky_draws as ld', 'ld.id', 'lucky_draw_tickets.lucky_draw_id')
            ->join('prizes as p', function ($join) {
                $join->on('p.event_id', 'ld.event_id')
                    ->where('p.type_id', PrizeTypeEnum::W_JACKPOT_6D->value)
                    ->where('p.status', 1);
            })
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

        $paginator->getCollection()->transform(
            fn ($ticket) => $this->formatTicket($ticket, (int) $issue->status, $expireDate, $rewardExpired)
        );

        return [
            'result_number' => $issue->draw_number ?: '------',
            'paginator' => $paginator,
        ];
    }

    /**
     * event/wjackpot/issues/receive - W-Jackpot 領取獎項
     */
    public function receive(Member $member, int $ticketId): void
    {
        $this->assertClaimable($member);

        DB::transaction(function () use ($member, $ticketId) {
            $lockedMember = Member::where('id', $member->id)->lockForUpdate()->first();

            $ticket = WJackpotTicket::query()
                ->join('w_jackpot_reward_items as wjri', 'wjri.id', 'w_jackpot_tickets.reward_item_id')
                ->join('w_jackpots as wj', 'wj.id', 'w_jackpot_tickets.w_jackpot_id')
                ->join('prizes as p', function ($join) {
                    $join->on('p.event_id', 'wj.event_id')->where('p.status', 1);
                })
                ->where('w_jackpot_tickets.id', $ticketId)
                ->where('w_jackpot_tickets.member_id', $lockedMember->id)
                ->select([
                    'w_jackpot_tickets.reward_item_id',
                    'w_jackpot_tickets.point',
                    'w_jackpot_tickets.type',
                    'w_jackpot_tickets.status',
                    'wjri.type as reward_type',
                    'p.id as prize_id',
                    DB::raw('IFNULL(p.flow_limit_multiple, 0) AS event_flow_limit_rate'),
                ])
                ->first();

            $this->assertTicketReceivable($ticket);

            $this->markTicketClaimed(WJackpotTicket::query(), $ticketId);
            $this->markPrizeMemberClaimed((int) $ticket->prize_id, $ticketId, $lockedMember->id);

            $this->creditReward(
                $lockedMember,
                (string) $ticket->point,
                $ticketId,
                TransactionEnum::W_JACKPOT_REWARD,
                (string) $ticket->event_flow_limit_rate,
            );
        });
    }

    /**
     * event/wjackpot6d/issues/receive - W-Jackpot 6D 領取獎項
     */
    public function receive6d(Member $member, int $ticketId): void
    {
        $this->assertClaimable($member);

        DB::transaction(function () use ($member, $ticketId) {
            $lockedMember = Member::where('id', $member->id)->lockForUpdate()->first();

            $ticket = LuckyDrawTicket::query()
                ->join('reward_items as ri', 'ri.id', 'lucky_draw_tickets.reward_item_id')
                ->join('lucky_draws as ld', 'ld.id', 'lucky_draw_tickets.lucky_draw_id')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.id', 'ld.event_detail_id')->where('ed.status', 1);
                })
                ->join('prizes as p', function ($join) {
                    $join->on('p.event_id', 'ld.event_id')
                        ->where('p.type_id', PrizeTypeEnum::W_JACKPOT_6D->value)
                        ->where('p.status', 1);
                })
                ->where('lucky_draw_tickets.id', $ticketId)
                ->where('lucky_draw_tickets.member_id', $lockedMember->id)
                ->select([
                    'lucky_draw_tickets.reward_item_id',
                    'lucky_draw_tickets.point',
                    'lucky_draw_tickets.type',
                    'lucky_draw_tickets.status',
                    'ri.type as reward_type',
                    'p.id as prize_id',
                    DB::raw('IFNULL(p.flow_limit_multiple, 0) AS event_flow_limit_rate'),
                    DB::raw('CASE WHEN DATE_ADD(ed.draw_time, INTERVAL ed.expire_hour HOUR) < NOW() THEN 1 ELSE 0 END AS expire_status'),
                ])
                ->first();

            $this->assertTicketReceivable($ticket);

            // 6D 專屬：逾期不可領取
            if ($ticket->expire_status == 1) {
                throw new ApiException(__('error_message.1948'));
            }

            $this->markTicketClaimed(LuckyDrawTicket::query(), $ticketId);
            $this->markPrizeMemberClaimed((int) $ticket->prize_id, $ticketId, $lockedMember->id);

            $this->creditReward(
                $lockedMember,
                (string) $ticket->point,
                $ticketId,
                TransactionEnum::W_JACKPOT_6D_REWARD,
                (string) $ticket->event_flow_limit_rate,
            );
        });
    }

    /**
     * event/wjackpot/issues/receive-all - W-Jackpot 該期全部領取
     *
     * 沿用舊版：查無可領取抽獎券時視為成功（不拋錯），與 6D 版行為不同。
     */
    public function receiveAll(Member $member, int $issueId): void
    {
        $this->assertClaimable($member);

        DB::transaction(function () use ($member, $issueId) {
            $lockedMember = Member::where('id', $member->id)->lockForUpdate()->first();

            $tickets = WJackpotTicket::query()
                ->join('w_jackpots as wj', 'wj.id', 'w_jackpot_tickets.w_jackpot_id')
                ->join('prizes as p', function ($join) {
                    $join->on('p.event_id', 'wj.event_id')->where('p.status', 1);
                })
                ->where('w_jackpot_tickets.w_jackpot_id', $issueId)
                ->where('w_jackpot_tickets.member_id', $lockedMember->id)
                ->where('w_jackpot_tickets.reward_item_id', '>', 0)
                ->where('w_jackpot_tickets.status', 0)
                ->where('w_jackpot_tickets.point', '>', 0)
                ->select([
                    'w_jackpot_tickets.id as ticket_id',
                    'w_jackpot_tickets.point',
                    'p.id as prize_id',
                    DB::raw('IFNULL(p.flow_limit_multiple, 0) AS event_flow_limit_rate'),
                ])
                ->get();

            // 舊版此處直接回成功，不拋 1000
            $this->claimTickets($lockedMember, $tickets, WJackpotTicket::class, TransactionEnum::W_JACKPOT_REWARD);
        });
    }

    /**
     * event/wjackpot6d/issues/receive-all - W-Jackpot 6D 該期全部領取
     */
    public function receive6dAll(Member $member, int $issueId): void
    {
        $this->assertClaimable($member);

        DB::transaction(function () use ($member, $issueId) {
            $lockedMember = Member::where('id', $member->id)->lockForUpdate()->first();

            $tickets = LuckyDrawTicket::query()
                ->join('lucky_draws as ld', 'ld.id', 'lucky_draw_tickets.lucky_draw_id')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.id', 'ld.event_detail_id')->where('ed.status', 1);
                })
                ->join('prizes as p', function ($join) {
                    $join->on('p.event_id', 'ld.event_id')
                        ->where('p.type_id', PrizeTypeEnum::W_JACKPOT_6D->value)
                        ->where('p.status', 1);
                })
                ->where('lucky_draw_tickets.lucky_draw_id', $issueId)
                ->where('lucky_draw_tickets.member_id', $lockedMember->id)
                ->where('lucky_draw_tickets.reward_item_id', '>', 0)
                ->where('lucky_draw_tickets.status', 0)
                ->where('lucky_draw_tickets.point', '>', 0)
                ->whereRaw('DATE_ADD(ed.draw_time, INTERVAL ed.expire_hour HOUR) > NOW()')
                ->select([
                    'lucky_draw_tickets.id as ticket_id',
                    'lucky_draw_tickets.point',
                    'p.id as prize_id',
                    DB::raw('IFNULL(p.flow_limit_multiple, 0) AS event_flow_limit_rate'),
                ])
                ->get();

            // 舊版 6D 版本查無資料時拋 1000（與非 6D 版不同）
            if ($tickets->isEmpty()) {
                throw new ApiException(__('error_message.1000'));
            }

            $this->claimTickets($lockedMember, $tickets, LuckyDrawTicket::class, TransactionEnum::W_JACKPOT_6D_REWARD);
        });
    }

    /**
     * 鎖分模式與實名認證檢查（W-Jackpot 系列專屬，一般抽獎券無實名檢查）
     */
    private function assertClaimable(Member $member): void
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

        $agent = $member->agent ?? Agent::find($member->parent);
        $agentSettings = $this->agentService->getAgentSettings($agent);

        if (!RealnameValidationService::isEventRewardRealnameValidated($agentSettings, $member)) {
            throw new ApiException(__('error_message.realname_invalid.event_reward'));
        }
    }

    /**
     * 抽獎券共用檢查：查無 / 未中獎 / 已領取 / 實體獎品需洽客服
     */
    private function assertTicketReceivable(mixed $ticket): void
    {
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
    }

    /**
     * 逐張領取（receive-all 共用）
     *
     * @param  Collection<int, mixed>  $tickets
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $ticketModel
     */
    private function claimTickets(Member $lockedMember, Collection $tickets, string $ticketModel, TransactionEnum $type): void
    {
        foreach ($tickets as $ticket) {
            $this->markTicketClaimed($ticketModel::query(), (int) $ticket->ticket_id);
            $this->markPrizeMemberClaimed((int) $ticket->prize_id, (int) $ticket->ticket_id, $lockedMember->id);

            $this->creditReward(
                $lockedMember,
                (string) $ticket->point,
                (int) $ticket->ticket_id,
                $type,
                (string) $ticket->event_flow_limit_rate,
            );

            $lockedMember->refresh();
        }
    }

    private function markTicketClaimed(mixed $query, int $ticketId): void
    {
        $updated = $query->where('id', $ticketId)
            ->where('status', 0)
            ->update(['status' => 1, 'time' => now()]);

        // 沿用舊版：併發下已被搶先領取時回 2001（而非 1943）
        if (!$updated) {
            throw new ApiException(__('error_message.2001'));
        }
    }

    /**
     * 標記 prize_members 已領取
     *
     * 與 LuckyDrawService 不同：W-Jackpot 的 prize_members 由 SP_payout_w_jackpot_ticket_reward /
     * SP_payout_lottery_ticket_6d_reward 以正確 member_id 寫入，舊版此處未做 member_id=0 補償，故不加。
     */
    private function markPrizeMemberClaimed(int $prizeId, int $ticketId, int $memberId): void
    {
        $updated = DB::table('prize_members')
            ->where('member_id', $memberId)
            ->where('prize_id', $prizeId)
            ->where('lottery_number', $ticketId)
            ->where('status', 0)
            ->update(['status' => 1]);

        if (!$updated) {
            throw new ApiException(__('error_message.2001'));
        }
    }

    /**
     * 派發獎勵至主錢包並寫入交易紀錄與稽核流水
     *
     * 沿用舊版：直接記入 Member.balance，不分流多錢包；稽核流水倍數取自 prizes.flow_limit_multiple，
     * 且 flow_limits 不帶 event_id（皆與 LuckyDrawService 不同，為舊版既有差異）。
     */
    private function creditReward(Member $lockedMember, string $amount, int $ticketId, TransactionEnum $type, string $flowLimitRate): void
    {
        $amount = Common::numAdd('0', $amount, 4);
        $flowLimitRate = Common::numAdd('0', $flowLimitRate, 4);

        $beforeBalance = $lockedMember->balance;
        $lockedMember->balance = Common::numAdd($beforeBalance, $amount, 4);
        $lockedMember->save();

        $log = TransactionLog::create([
            'transaction_no' => (string) $ticketId,
            'order_id' => $ticketId,
            'agent_id' => $lockedMember->parent,
            'member_id' => $lockedMember->id,
            'point' => $amount,
            'before_balance' => $beforeBalance,
            'balance' => $lockedMember->balance,
            'rate' => 1,
            'vendor_point' => 0,
            'remark' => '',
            'operator' => 'system',
            'type' => $type->value,
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
            'admin' => 0,
        ]);

        if (Common::numCompare($flowLimitRate, '0', 4) > 0) {
            FlowLimit::create([
                'member_id' => $lockedMember->id,
                'type' => $type->value,
                'reference_id' => $log->id,
                'wallet_transaction_log_id' => 0,
                'flow_limit' => Common::numMul($amount, $flowLimitRate, 4),
                'reach' => 0,
                'admin' => 0,
            ]);
        }
    }

    /**
     * @param  Collection<int, mixed>  $list
     */
    private function formatIssueList(Collection $list): Collection
    {
        return $list->map(fn ($item) => [
            'issue_id' => $item->issue_id,
            'issue_name' => str_replace('{N}', (string) $item->issue_no, __('param.periods')),
            'draw_time' => $this->toFrontendTime($item->draw_time),
        ]);
    }

    /**
     * 抽獎券張數 / 中獎總額 / 未領取總額；逾期後未領取金額歸零
     */
    private function summarizeTickets(mixed $query, int $memberId, Carbon $expireDate): array
    {
        $summary = $query->where('member_id', $memberId)
            ->selectRaw('COUNT(*) as total_tickets')
            ->selectRaw('TRUNCATE(IFNULL(SUM(point), 0), 2) as total_winnings')
            ->selectRaw('TRUNCATE(SUM(CASE WHEN status = 0 THEN IFNULL(point, 0) ELSE 0 END), 2) as total_unclaimed')
            ->first();

        return [
            'totalCount_tickets' => $summary->total_tickets ?? 0,
            'totalCount_winnings' => (string) ($summary->total_winnings ?? 0),
            'totalCount_unclaimed' => $expireDate->lt(now()) ? '0' : (string) ($summary->total_unclaimed ?? 0),
        ];
    }

    /**
     * 抽獎券顯示欄位與狀態
     *
     * status: 0:未開獎, 1:已開獎-已領取, 2:已開獎-未領取, 3:已開獎-沒中獎, 4:已開獎-已過期
     */
    private function formatTicket(mixed $ticket, int $issueStatus, Carbon $expireDate, bool $rewardExpired = false): array
    {
        [$status, $statusText] = $this->resolveTicketStatus($ticket, $issueStatus, $expireDate, $rewardExpired);

        return [
            'ticket_id' => $ticket->ticket_id,
            'ticket_number' => $ticket->ticket_number,
            'reward_type' => $ticket->reward_type,
            'reward_level' => $ticket->reward_level ?? 0,
            'reward_amount' => Common::numAdd('0', (string) ($ticket->point ?? 0), 2),
            'reward_name' => $ticket->reward_type == 2 ? ($ticket->reward_prize_name_lang ?: $ticket->reward_prize_name) : '',
            'type' => $ticket->type,
            'status' => $status,
            'statusText' => $statusText,
        ];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function resolveTicketStatus(mixed $ticket, int $issueStatus, Carbon $expireDate, bool $rewardExpired): array
    {
        if (now()->gt($expireDate)) {
            return [4, __('param.past_due')];
        }

        if ($issueStatus === 0) {
            return [0, __('param.no_draw')];
        }

        if ($ticket->type == 0) {
            return [3, __('param.did_not_win')];
        }

        if ($ticket->status == 1) {
            return [1, __('param.received')];
        }

        // 6D 專屬：已中獎但超過領取期限
        if ($rewardExpired) {
            return [4, __('param.past_due')];
        }

        return [2, __('param.unaccalimed')];
    }

    /**
     * 領獎期限：event_details.draw_time + expire_hour，未設定 expire_hour 時視同永不過期
     */
    private function resolveExpireDate(mixed $eventDetailDrawTime, int $expireHour): Carbon
    {
        $drawTime = Carbon::parse($eventDetailDrawTime);

        return $expireHour > 0 ? $drawTime->addHours($expireHour) : $drawTime->addYears(30);
    }

    private function toFrontendTime(mixed $time): ?string
    {
        return $time ? Carbon::parse($time)->toFrontendTime()->toDateTimeString() : null;
    }
}
