<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\EventPayoutCancel;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberCode;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use Illuminate\Support\Facades\DB;

/**
 * 新玩家邀請活動（action 15）
 *
 * 遷移自 API_Frontend EventNewInviteController（tpower-master / vw-master 內容完全相同，
 * 但只有 vw-master 掛路由）。
 */
class EventNewInviteService
{
    public function __construct(
        protected WalletService $walletService,
    ) {}

    /** 領獎的具名鎖等待秒數（沿用舊版） */
    private const int LOCK_TIMEOUT = 3;

    /** prize_contents.point_type：派往遊戲錢包（多錢包開啟時由平台點數轉換而來） */
    private const int POINT_TYPE_GAME_WALLET = 3;

    /** prize_contents.point_type：禮碼 */
    private const int POINT_TYPE_ROLLING_CHIP = 2;

    /** prize_contents.point_type：平台點數 */
    private const int POINT_TYPE_PLATFORM = 1;

    /**
     * event/getNewInviteEvent - 取得新玩家邀請活動
     */
    public function getEvent(Member $member, Agent $agent, int $eventId, int $languageId): object
    {
        $memberCode = MemberCode::where('member_id', $member->id)->first();
        if (!$memberCode) {
            // 查無此玩家邀請碼
            throw new ApiException(__('error_message.1945'));
        }

        $event = $this->findEvent($agent, $eventId, $languageId);
        if (!$event) {
            throw new ApiException(__('error_message.1901'));
        }

        $event->code = $memberCode->agent_code;

        [$start, $end] = $this->getTimeRange($this->resolvePeriod($event->prize_info));

        $prizeContents = DB::table('prize_contents as pc')
            ->leftJoin('prize_members as pm', function ($join) use ($start, $end, $member) {
                $join->on('pm.content_id', 'pc.id')
                    ->where('pm.member_id', $member->id)
                    ->where('pm.created_at', '>=', $start)
                    ->where('pm.created_at', '<', $end);
            })
            ->where('pc.prize_id', $event->prize_id)
            ->where('pc.status', 1)
            ->select([
                'pc.id as prize_content_id',
                'pc.name as name',
                // 回應欄位名沿用舊版 type，內容實際是 prize_contents.level
                'pc.level as type',
                'pc.achieve_people as achieve_people',
                DB::raw('IFNULL(pm.id, 0) as achieve_number'),
                'pc.point as point',
            ])
            ->get();

        foreach ($prizeContents as $row) {
            // achieve_number 於查詢階段放的是 prize_members.id，非 0 代表本期已產生領獎紀錄
            $prizeMemberId = (int) $row->achieve_number;
            $achieveNumber = $prizeMemberId !== 0 ? (int) $row->achieve_people : 0;
            $showExchange = 0;
            $text = __('param.prize_button.can_not_exchange');

            if (in_array($row->type, [0, 1]) && $prizeMemberId !== 0) {
                $prizeMember = PrizeMember::find($prizeMemberId);
                if ($prizeMember?->status == 1) {
                    // 已領取獎項
                    $showExchange = 2;
                    $text = __('param.prize_button.exchanged');
                } else {
                    // 未領取，可能是 IP、手機號、真實姓名重複
                    $showExchange = 1;
                    $text = __('param.prize_button.exchange');
                }
            } else {
                $achieveNumber = $this->getAchieveCount($event, (int) $row->type, (int) $row->achieve_people, $member->id, $start, $end);
                if ($achieveNumber >= $row->achieve_people) {
                    $showExchange = 1;
                    $text = __('param.prize_button.exchange');
                }
            }

            $row->achieve_number = $achieveNumber;
            $row->show_exchange = $showExchange;
            $row->text = $text;
        }

        $event->prize_contents = $prizeContents;

        return $event;
    }

    /**
     * event/getNewInvitePrize - 領取新玩家邀請活動獎勵
     */
    public function receivePrize(Member $member, Agent $agent, int $prizeContentId): void
    {
        $memberCode = MemberCode::where('member_id', $member->id)->first();
        if (!$memberCode) {
            // 查無此玩家邀請碼
            throw new ApiException(__('error_message.1945'));
        }

        $prizeContent = PrizeContent::find($prizeContentId);
        if (!$prizeContent) {
            // 沒有獎品，回傳不符合參加活動的條件
            throw new ApiException(__('error_message.1937'));
        }

        $prize = Prize::find($prizeContent->prize_id);
        if (!$prize) {
            throw new ApiException(__('error_message.1937'));
        }

        $event = $this->findEvent($agent, (int) $prize->event_id, 1, withFlowLimit: true);
        if (!$event) {
            throw new ApiException(__('error_message.1901'));
        }

        [$start, $end] = $this->getTimeRange($this->resolvePeriod($event->prize_info));

        // 防止網路卡頓造成同時間重複領獎
        $lockKey = 'new_invite_prize_' . $member->id . '_' . $prizeContentId;
        if (!$this->acquireLock($lockKey)) {
            throw new ApiException(__('error_message.1949'));
        }

        try {
            $prizeMember = PrizeMember::where('member_id', $member->id)
                ->where('content_id', $prizeContentId)
                ->where('prize_id', $event->prize_id)
                ->where('created_at', '>=', $start)
                ->where('created_at', '<', $end)
                ->first();

            if ($prizeMember) {
                if ($prizeMember->status == 1) {
                    // 已領取過
                    throw new ApiException(__('error_message.1947'));
                }
            } else {
                // 舊版此處誤用 prize_contents.type（點數類型），已與 getEvent 統一為 level（見遷移文件 2-6）
                $achieveNumber = $this->getAchieveCount($event, (int) $prizeContent->level, (int) $prizeContent->achieve_people, $member->id, $start, $end);

                if ($achieveNumber < $prizeContent->achieve_people) {
                    // 未達成條件
                    throw new ApiException(__('error_message.1936'));
                }

                $prizeMember = PrizeMember::create([
                    'member_id' => $member->id,
                    'content_id' => $prizeContentId,
                    'event_detail_id' => $event->event_detail_id,
                    'prize_id' => $event->prize_id,
                    'expired_time' => $end,
                    'lottery_point' => $prizeContent->point,
                    'ip' => explode(', ', Common::getIp())[0],
                    'status' => 0,
                    'admin' => 0,
                ]);
            }

            $this->assertNotBlocked($event, $prizeMember->id, $member);

            // 有開啟多錢包、獎項為平台點數、且稽核流水倍數大於 0 時改派發到遊戲錢包
            $pointType = ($agent->is_multi_wallet
                && $prizeContent->point_type == self::POINT_TYPE_PLATFORM
                && $event->flow_limit_multiple > 0)
                ? self::POINT_TYPE_GAME_WALLET
                : (int) $prizeContent->point_type;

            DB::transaction(function () use ($event, $prizeContent, $prizeMember, $member, $pointType) {
                $transactionLogId = $this->sendPrizeToWallet($pointType, (string) $prizeContent->point, $member, (int) $prizeMember->id);

                if ($prizeContent->flow_limit_multiple > 0) {
                    FlowLimit::create([
                        'member_id' => $member->id,
                        'type' => TransactionEnum::LOGIN_EVENT_REWARDS->value,
                        'event_id' => $event->id,
                        'reference_id' => $pointType === self::POINT_TYPE_GAME_WALLET ? 0 : $transactionLogId,
                        'wallet_transaction_log_id' => $pointType === self::POINT_TYPE_GAME_WALLET ? $transactionLogId : 0,
                        'flow_limit' => Common::numMul((string) $prizeContent->point, (string) $prizeContent->flow_limit_multiple, 4),
                        'reach' => 0,
                        'status' => 0,
                        'admin' => 0,
                    ]);
                }

                $prizeMember->update(['status' => 1]);
            });
        } finally {
            $this->releaseLock($lockKey);
        }
    }

    /**
     * 取得新玩家邀請活動主檔（依代理層級與排除代理設定）
     */
    private function findEvent(Agent $agent, int $eventId, int $languageId, bool $withFlowLimit = false): ?object
    {
        $agentIds = $agent->hierarchy_array;

        $select = [
            'e.id',
            'ed.id as event_detail_id',
            'ed.point',
            'ed.ip_block as ip_block',
            'ed.phone_block as phone_block',
            'ed.account_block as account_block',
            'ed.realname_block as realname_block',
            'ed.tgid_block as tgid_block',
            DB::raw('IFNULL(en.title, e.title) as title'),
            DB::raw('IFNULL(en.content, e.content) as content'),
            'e.agent_id',
            'p.id as prize_id',
            'example.invalid as prize_info',
            DB::raw('DATE_ADD(p.expire_date, INTERVAL 8 HOUR) as prize_expire_date'),
            DB::raw('DATE_ADD(e.start_time, INTERVAL 8 HOUR) as event_start_time'),
            DB::raw('DATE_ADD(e.end_time, INTERVAL 8 HOUR) as event_end_time'),
        ];

        if ($withFlowLimit) {
            $select[] = 'p.flow_limit_multiple as flow_limit_multiple';
        }

        return DB::table('events as e')
            ->join('event_details as ed', function ($join) {
                $join->on('ed.event_id', 'e.id')
                    ->where('ed.status', 1)
                    ->where('ed.action_id', EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value);
            })
            ->leftJoin('event_names as en', function ($join) use ($languageId) {
                $join->on('en.event_id', 'e.id')->where('en.language_id', $languageId);
            })
            ->join('prizes as p', function ($join) {
                $join->on('p.event_id', 'e.id')
                    ->where('p.status', 1)
                    ->where('p.expire_date', '>', now()->toDateTimeString());
            })
            ->leftJoin('event_exclude_agents as eea', function ($join) use ($agentIds) {
                $join->on('eea.event_id', 'e.id')->whereIn('eea.agent_id', $agentIds);
            })
            ->where('e.id', $eventId)
            ->whereIn('e.agent_id', $agentIds)
            ->where('e.status', 1)
            ->whereRaw('e.start_time <= NOW()')
            ->whereNull('eea.id')
            ->orderByDesc('e.created_at')
            ->select($select)
            ->first();
    }

    /**
     * 計算達標人數
     *
     * $level 0: 邀請註冊人數；1: 被邀請人首儲達標人數；其餘: 0
     */
    private function getAchieveCount(object $event, int $level, int $achievePeople, int $memberId, string $start, string $end): int
    {
        $inviteMemberIds = DB::table('event_invite_records as eir')
            ->join('members as m', function ($join) use ($event) {
                $join->on('m.id', 'eir.member_id');
                if ($event->realname_block == 1) {
                    $join->where('m.realname_validate', 1);
                }
            })
            ->when($event->ip_block == 1, fn ($query) => $query->where('eir.ip_duplicate', 0))
            ->when($event->phone_block == 1, fn ($query) => $query->where('eir.phone_duplicate', 0))
            ->when($event->account_block == 1, fn ($query) => $query->where('eir.realname_duplicate', 0))
            ->when($event->tgid_block == 1, fn ($query) => $query->where('eir.telegram_id_duplicate', 0))
            ->where('eir.event_id', $event->id)
            ->where('eir.invite_member_id', $memberId)
            ->where('eir.created_at', '>=', $start)
            ->where('eir.created_at', '<', $end)
            ->pluck('eir.member_id')
            ->toArray();

        if ($level === 0) {
            return min(count($inviteMemberIds), $achievePeople);
        }

        if ($level !== 1) {
            return 0;
        }

        // 每位被邀請人取本期首筆成功訂單，再比對活動門檻金額
        $firstOrders = DB::table('orders')
            ->select('member_id', DB::raw('MIN(id) as first_id'))
            ->whereIn('member_id', $inviteMemberIds)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->where('status', 3)
            ->groupBy('member_id');

        $count = DB::table('orders as o')
            ->joinSub($firstOrders, 't', 'o.id', '=', 't.first_id')
            ->where('o.point', '>=', $event->point)
            ->count();

        return min($count, $achievePeople);
    }

    /**
     * 重複性檢查；命中即寫入 event_payout_cancels 並拋出對應錯誤
     */
    private function assertNotBlocked(object $event, int $prizeMemberId, Member $member): void
    {
        $blocked = $this->checkPrizeBlock($event, $prizeMemberId, $member);
        if (!$blocked) {
            return;
        }

        $blockData = EventPayoutCancel::query()
            ->where('event_id', $event->id)
            ->where('member_id', $member->id)
            ->where('prize_member_id', $prizeMemberId)
            ->first();

        if (!$blockData) {
            // 未通過檢查但沒有紀錄，代表資料異常
            throw new ApiException(__('error_message.1949'));
        }

        if ($blockData->status != 0) {
            return;
        }

        throw new ApiException(match ((int) $blockData->type) {
            1 => __('error_message.1952'),   // 真實姓名
            2, 4 => __('error_message.1950'), // 註冊 IP 或領獎 IP
            3 => __('error_message.1951'),   // 手機號碼
            default => __('error_message.1949'),
        });
    }

    private function checkPrizeBlock(object $event, int $prizeMemberId, Member $member): bool
    {
        // 已經判定過，代表一定有重複
        $exists = EventPayoutCancel::query()
            ->where('event_id', $event->id)
            ->where('member_id', $member->id)
            ->where('prize_member_id', $prizeMemberId)
            ->exists();

        if ($exists) {
            return true;
        }

        $isBlock = false;
        $blockType = 0;
        $blockMemberId = 0;

        // 同一活動明細下已領獎的其他會員
        $claimedMemberIds = PrizeMember::query()
            ->where('prize_id', $event->prize_id)
            ->where('event_detail_id', $event->event_detail_id)
            ->where('status', 1)
            ->where('member_id', '!=', $member->id)
            ->pluck('member_id')
            ->toArray();

        if ($event->ip_block == 1) {
            if (!$this->filled($member->register_ip)) {
                $isBlock = true;
                $blockType = 2;
            } else {
                $loginIp = explode(', ', Common::getIp())[0];
                $sameLoginIp = PrizeMember::query()
                    ->where('prize_id', $event->prize_id)
                    ->where('event_detail_id', $event->event_detail_id)
                    ->where('ip', $loginIp)
                    ->where('member_id', '!=', $member->id)
                    ->where('status', 1)
                    ->first();

                if ($sameLoginIp) {
                    $isBlock = true;
                    $blockMemberId = (int) $sameLoginIp->member_id;
                    $blockType = 4;
                }

                if (!$isBlock) {
                    $sameRegisterIp = Member::whereIn('id', $claimedMemberIds)
                        ->where('register_ip', $member->register_ip)
                        ->first();
                    if ($sameRegisterIp) {
                        $isBlock = true;
                        $blockMemberId = (int) $sameRegisterIp->id;
                        $blockType = 2;
                    }
                }
            }
        }

        if ($event->phone_block == 1 && !$isBlock) {
            if (!$this->filled($member->mobile)) {
                $isBlock = true;
                $blockType = 3;
            } else {
                $sameMobile = Member::whereIn('id', $claimedMemberIds)->where('mobile', $member->mobile)->first();
                if ($sameMobile) {
                    $isBlock = true;
                    $blockMemberId = (int) $sameMobile->id;
                    $blockType = 3;
                }
            }
        }

        if ($event->account_block == 1 && !$isBlock) {
            if (!$this->filled($member->first_name)) {
                $isBlock = true;
                $blockType = 1;
            } else {
                $sameName = Member::whereIn('id', $claimedMemberIds)->where('first_name', $member->first_name)->first();
                if ($sameName) {
                    $isBlock = true;
                    $blockMemberId = (int) $sameName->id;
                    $blockType = 1;
                }
            }
        }

        // 沿用舊版：tgid 檢查不看前面是否已命中
        if ($event->tgid_block == 1) {
            $rel = DB::table('member_telegram_rels')->where('member_id', $member->id)->first();
            if ($rel) {
                $sameTgId = DB::table('member_telegram_rels')
                    ->whereIn('member_id', $claimedMemberIds)
                    ->where('tg_user_id', $rel->tg_user_id)
                    ->first();
                if ($sameTgId) {
                    $isBlock = true;
                    $blockMemberId = (int) $sameTgId->member_id;
                    $blockType = 6;
                }
            }
        }

        if ($isBlock) {
            EventPayoutCancel::create([
                'event_id' => $event->id,
                'prize_member_id' => $prizeMemberId,
                'from_member_id' => $member->id,
                'member_id' => $member->id,
                'type' => $blockType,
                'block_member_id' => $blockMemberId,
            ]);
        }

        return $isBlock;
    }

    /**
     * 派發獎勵並回傳對應的紀錄 id（平台點數 / 禮碼 / 遊戲錢包）
     */
    private function sendPrizeToWallet(int $pointType, string $point, Member $member, int $prizeMemberId): int
    {
        if ($pointType !== self::POINT_TYPE_ROLLING_CHIP && $pointType !== self::POINT_TYPE_GAME_WALLET) {
            // 平台點數
            $lockedMember = Member::lockForUpdate()->find($member->id);
            $beforeBalance = Common::numAdd('0', (string) $lockedMember->balance, 4);
            $afterBalance = Common::numAdd($beforeBalance, $point, 4);
            $lockedMember->update(['balance' => $afterBalance]);

            return (int) TransactionLog::create([
                'transaction_no' => $prizeMemberId,
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'type' => TransactionEnum::ACTIVITY_AWARD->value,
                'rate' => 1,
                'point' => $point,
                'before_balance' => $beforeBalance,
                'balance' => $afterBalance,
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'remark' => 'New Invite Reward',
                'operator' => 'system',
                'admin' => 0,
            ])->id;
        }

        // 錢包類型要跟著獎勵類型走。舊版一律建立禮碼錢包，導致遊戲錢包的派彩落到錯誤的
        // 錢包（見遷移文件 2-6），該缺陷已修，這裡不可改回固定值。
        $walletType = $pointType === self::POINT_TYPE_ROLLING_CHIP
            ? WalletEnum::CODE_ROLLING_CHIP
            : WalletEnum::CODE_GAME;

        // type=2 已廢除（決議⑬）只查不建。GAME 僅在多錢包（死路徑）或 prize_contents
        // 直接配 point_type=3 時可達——2026-09-01 六站實測活的 point_type=3 全掛
        // action_id=55 儲值抽獎（歸 PrizeService），本服務（action_id=15）吃不到。
        $wallet = $this->walletService->getLockedWallet($member->id, $walletType)
            ?? throw new \LogicException("已廢除的錢包類型查無存量，member_id={$member->id}，wallet_type={$walletType->value}");
        $beforeBalance = Common::numAdd('0', (string) $wallet->balance, 4);
        $afterBalance = Common::numAdd($beforeBalance, $point, 4);
        $wallet->update(['balance' => $afterBalance]);

        if ($pointType === self::POINT_TYPE_ROLLING_CHIP) {
            return (int) RollingChipLog::create([
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'type' => RollingChipLogTypeEnum::REWARD->value,
                'amount' => $point,
                'before_balance' => $beforeBalance,
                'balance' => $afterBalance,
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'remark' => 'New Invite Reward',
                'admin' => 0,
            ])->id;
        }

        return (int) WalletTransactionLog::create([
            'transaction_no' => $prizeMemberId,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'point' => $point,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'type' => WalletTransactionEnum::GAME_WALLET_EVENT_REWARD->value,
            'operator' => 'system',
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'admin' => 0,
        ])->id;
    }

    /**
     * 週期起訖（以 UTC+8 為界，回傳系統時間）
     *
     * @return array{0: string, 1: string}
     */
    private function getTimeRange(int $period): array
    {
        $now = now()->toFrontendTime();

        $range = match ($period) {
            1 => [$now->copy()->startOfDay(), $now->copy()->startOfDay()->setTime(23, 59, 59)],
            2 => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()->setTime(23, 59, 59)],
            3 => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()->setTime(23, 59, 59)],
            default => null,
        };

        if ($range === null) {
            // 沿用舊版：period 非 1/2/3 時起訖皆為當下，等同查不到任何區間內資料
            $current = now()->toDateTimeString();

            return [$current, $current];
        }

        return [
            $range[0]->toSystemTime()->toDateTimeString(),
            $range[1]->toSystemTime()->toDateTimeString(),
        ];
    }

    private function resolvePeriod(?string $prizeInfo): int
    {
        $info = json_decode((string) $prizeInfo, true);

        return (int) ($info['period'] ?? 1);
    }

    private function filled(int|string|null $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function acquireLock(string $key): bool
    {
        // 讀寫分離下 GET_LOCK 必走 write PDO，否則會與後續 transaction 不同連線
        $locked = DB::selectFromWriteConnection('SELECT GET_LOCK(?, ?) AS locked', [$key, self::LOCK_TIMEOUT]);

        return (bool) ($locked[0]->locked ?? false);
    }

    private function releaseLock(string $key): void
    {
        DB::selectFromWriteConnection('SELECT RELEASE_LOCK(?)', [$key]);
    }
}
