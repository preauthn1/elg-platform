<?php

declare(strict_types=1);

namespace App\Services\Frontend\EventInvite;

use App\Data\EventV2ListItemData;
use App\Data\UserData;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\OrderStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\EventInviteExcludeAgent;
use App\Models\EventInviteImage;
use App\Models\EventInvitePointLog;
use App\Models\EventInviteSetting;
use App\Models\EventInviteTitle;
use App\Models\EventInviteUserJoin;
use App\Models\Member;
use App\Models\Order;
use App\Models\TransactionLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventInviteService
{
    /**
     * 註冊時建立邀請關係紀錄，並依 event_invite_settings 的設定檢查 IP/手機是否重複
     * block_type = 1：檢查對象為同代理底下所有 members；block_type = 2：檢查對象改為 event_invite_user_joins（僅限已參加此活動的會員）
     * 註冊當下不查真實姓名——當下填的姓名未經驗證，不一定是本人真實資料，得等後續完成真實姓名驗證才算數，
     * 真實姓名重複改在 settleInviteeReward() 發放實名獎勵時才查一次
     */
    public function registerInvite(Member $member, Member $sponsor): void
    {
        $agent = $member->agent;
        $settings = $agent ? $this->resolveActiveSetting($agent) : null;

        // 活動未設定、已停用（status=0）、或該代理被排除，不建立參加紀錄
        if (!$settings) {
            return;
        }

        [$blockIpMemberId, $blockMobileMemberId] = $this->resolveBlocks($member, $settings);

        $hasDuplicate = $blockIpMemberId > 0 || $blockMobileMemberId > 0;

        EventInviteUserJoin::create([
            'member_id' => $member->id,
            'invite_member_id' => $sponsor->id,
            'register_ip' => $member->register_ip,
            'register_mobile' => $member->mobile,
            'register_realname' => $member->first_name,
            'block_ip_member_id' => $blockIpMemberId,
            'block_mobile_member_id' => $blockMobileMemberId,
            'status' => $hasDuplicate ? 2 : 0,
        ]);
    }

    /**
     * 找出目前對這個會員生效的邀請好友設定：依 hierarchy_array（自己往上所有上級代理 id）找到
     * 代理啟用中的那一列，而非 general_agent_id——general_agent_id 是靠 role_id=GENERAL_AGENT
     * 往上找，測試代理初期可能還沒設好這個角色，但 event_invite_settings 已經直接設在該代理自己身上，
     * 這樣才撈得到；且該代理（或其上層，即被排除的那個層級）沒有被 event_invite_exclude_agents 排除
     */
    private function resolveActiveSetting(Agent $agent): ?EventInviteSetting
    {
        $settings = EventInviteSetting::whereIn('agent_id', $agent->hierarchy_array)
            ->where('status', 1)
            ->first();

        if (!$settings) {
            return null;
        }

        $isExcluded = EventInviteExcludeAgent::where('event_invite_setting_id', $settings->id)
            ->whereIn('agent_id', $agent->hierarchy_array)
            ->exists();

        return $isExcluded ? null : $settings;
    }

    /**
     * 依 block_type 決定用哪種範圍檢查 IP/手機是否重複；各欄位是否要檢查則由
     * resolveBlocksByAgentMembers()/resolveBlocksByEventJoins() 內部依 ip_block/mobile_block 決定
     *
     * @return array{0: int, 1: int} [blockIpMemberId, blockMobileMemberId]
     */
    private function resolveBlocks(Member $member, EventInviteSetting $settings): array
    {
        if ($settings->block_type == 1) {
            return $this->resolveBlocksByAgentMembers($member, $settings, $settings->agent_id);
        }

        if ($settings->block_type == 2) {
            return $this->resolveBlocksByEventJoins($member, $settings);
        }

        return [0, 0];
    }

    /**
     * 只查真實姓名是否重複（依 realname_block 設定決定要不要查），依 block_type 決定檢查範圍。
     * 用於 settleInviteeReward() 對已驗證的真實姓名重新檢查一次，故意不跟 IP/手機一起查——
     * 這個時間點只在意真實姓名，混進 IP/手機查詢除了浪費查詢外，也容易讓人誤以為結果跟 IP/手機有關
     */
    private function resolveRealnameBlock(Member $member, EventInviteSetting $settings): int
    {
        if (!$settings->realname_block || !$member->first_name) {
            return 0;
        }

        if ($settings->block_type == 1) {
            $settingsAgent = Agent::find($settings->agent_id);
            if (!$settingsAgent) {
                return 0;
            }

            $agentIds = Agent::where('hierarchy_path', 'like', $settingsAgent->hierarchy_path . '%')->pluck('id');

            return (int) (Member::whereIn('parent', $agentIds)
                ->where('id', '!=', $member->id)
                ->where('first_name', $member->first_name)
                ->value('id') ?? 0);
        }

        if ($settings->block_type == 2) {
            return (int) (EventInviteUserJoin::where('member_id', '!=', $member->id)
                ->where('register_realname', $member->first_name)
                ->value('member_id') ?? 0);
        }

        return 0;
    }

    /**
     * block_type = 1：檢查對象為同代理底下所有 members
     *
     * @return array{0: int, 1: int} [blockIpMemberId, blockMobileMemberId]
     */
    private function resolveBlocksByAgentMembers(Member $member, EventInviteSetting $settings, int $settingsAgentId): array
    {
        $blockIpMemberId = 0;
        $blockMobileMemberId = 0;

        $settingsAgent = Agent::find($settingsAgentId);
        if (!$settingsAgent) {
            return [$blockIpMemberId, $blockMobileMemberId];
        }

        $agentIds = Agent::where('hierarchy_path', 'like', $settingsAgent->hierarchy_path . '%')->pluck('id');

        if ($settings->ip_block && $member->register_ip) {
            $blockIpMemberId = (int) (Member::whereIn('parent', $agentIds)
                ->where('id', '!=', $member->id)
                ->where('register_ip', $member->register_ip)
                ->value('id') ?? 0);
        }

        if ($settings->mobile_block && $member->mobile) {
            $blockMobileMemberId = (int) (Member::whereIn('parent', $agentIds)
                ->where('id', '!=', $member->id)
                ->where('mobile', $member->mobile)
                ->value('id') ?? 0);
        }

        return [$blockIpMemberId, $blockMobileMemberId];
    }

    /**
     * block_type = 2：檢查對象改為 event_invite_user_joins（僅限已參加此活動的會員）
     *
     * @return array{0: int, 1: int} [blockIpMemberId, blockMobileMemberId]
     */
    private function resolveBlocksByEventJoins(Member $member, EventInviteSetting $settings): array
    {
        $blockIpMemberId = 0;
        $blockMobileMemberId = 0;

        if ($settings->ip_block && $member->register_ip) {
            $blockIpMemberId = (int) (EventInviteUserJoin::where('member_id', '!=', $member->id)
                ->where('register_ip', $member->register_ip)
                ->value('member_id') ?? 0);
        }

        if ($settings->mobile_block && $member->mobile) {
            $blockMobileMemberId = (int) (EventInviteUserJoin::where('member_id', '!=', $member->id)
                ->where('register_mobile', $member->mobile)
                ->value('member_id') ?? 0);
        }

        return [$blockIpMemberId, $blockMobileMemberId];
    }

    /**
     * 查詢自己邀請的會員清單，並補算尚未結算的實名/儲值獎勵
     *
     * @return array{setting: array<string, mixed>, joins: Collection<int, EventInviteUserJoin>}
     */
    public function list(Member $member, int $languageId = 0): array
    {
        $joins = EventInviteUserJoin::query()
            ->where('invite_member_id', $member->id)
            ->where('status', '!=', 2)
            ->with('member.agent')
            ->get();

        // 用呼叫這個 API 的會員（邀請人）自己的代理查一次「後台開分視同儲值」開關即可，
        // 不用對每個被邀請人各查一次
        $backendDepositCountsAsDeposit = (bool) $member->agent?->getSettingValue(
            AgentCustomizedSettingKeyEnum::BACKEND_DEPOSIT_COUNTS_AS_DEPOSIT
        );

        $joins->each(function (EventInviteUserJoin $join) use ($backendDepositCountsAsDeposit) {
            // status = 1 代表已達最大金額，不用再檢查
            if ($join->status == 0 && ($join->realname_status == 0 || $join->deposit_status == 0)) {
                $this->settleInviteeReward($join, $backendDepositCountsAsDeposit);
            }
        });

        return [
            'setting' => $this->buildSetting($member, $languageId, $joins),
            'joins' => $joins,
        ];
    }

    /**
     * 組合前端顯示用的活動設定：event_invite_settings 的獎勵參數 + event_invite_titles 的多語言標題/內容
     * + 依目前清單算出的邀請人數/累積解鎖/累積領取/可領取 統計
     *
     * @return array<string, mixed>
     */
    private function buildSetting(Member $member, int $languageId, Collection $joins): array
    {
        $agent = $member->agent;
        // 理由同 registerInvite()：用 hierarchy_array 取代 general_agent_id
        $settings = $agent ? EventInviteSetting::whereIn('agent_id', $agent->hierarchy_array)->first() : null;

        $title = EventInviteTitle::where('language_id', $languageId)->first()
            ?? EventInviteTitle::where('language_id', 0)->first();

        $totalPoint = '0.0000';
        $totalReceive = '0.0000';
        foreach ($joins as $join) {
            $totalPoint = Common::numAdd($totalPoint, $join->point, 4);
            $totalReceive = Common::numAdd($totalReceive, $join->receive, 4);
        }

        return [
            'title' => $title?->title,
            'content' => $title?->content,
            'max_reward' => $settings->max_reward ?? '0.0000',
            'invite_count' => $joins->count(),
            'total_point' => $totalPoint,
            'total_receive' => $totalReceive,
            'can_receive' => Common::numSub($totalPoint, $totalReceive, 4),
            'deposit_point' => $settings->deposit_point ?? '0.0000',
            'bet_point' => $settings->bet_point ?? '0.0000',
            'bet_reward' => $settings->bet_reward ?? '0.0000',
            'realname_reward' => $settings->realname_reward ?? '0.0000',
            'deposit_reward' => $settings->deposit_reward ?? '0.0000',
        ];
    }

    /**
     * 檢查單一被邀請人是否符合實名/儲值獎勵條件，符合就加分並標記已結算
     *
     * @param  bool  $backendDepositCountsAsDeposit  邀請人所屬代理是否開啟「後台開分視同儲值」
     *                                               （由 list() 查一次帶入，不對每個被邀請人各查一次）
     */
    private function settleInviteeReward(EventInviteUserJoin $join, bool $backendDepositCountsAsDeposit): void
    {
        DB::transaction(function () use ($join, $backendDepositCountsAsDeposit) {
            $locked = EventInviteUserJoin::query()->lockForUpdate()->find($join->id);
            if (!$locked) {
                return;
            }

            $invitee = Member::with('agent')->find($locked->member_id);
            if (!$invitee || !$invitee->agent) {
                return;
            }

            // 理由同 registerInvite()：用 hierarchy_array 取代 general_agent_id
            $settings = EventInviteSetting::whereIn('agent_id', $invitee->agent->hierarchy_array)->first();
            if (!$settings) {
                return;
            }

            $dirty = false;
            $shouldClose = false;

            if ($locked->realname_status == 0 && $invitee->realname_validate == 1) {
                $beforePoint = $locked->point;
                $locked->point = $this->capPoint(Common::numAdd($locked->point, $settings->realname_reward, 4), $settings->max_reward);
                $locked->realname_status = 1;
                $locked->register_realname = $invitee->first_name;
                $dirty = true;

                EventInvitePointLog::create([
                    'summary_start_time' => now()->format('Y-m-d H:00:00'),
                    'type' => 1,
                    'member_id' => $invitee->id,
                    'before_bet' => 0,
                    'after_bet' => 0,
                    'before_point' => $beforePoint,
                    'after_point' => $locked->point,
                ]);

                // 註冊當下填的真實姓名未經驗證，不一定是本人真實資料，要等真實姓名驗證通過才算數，
                // 這裡才依設定重新檢查一次是否跟其他會員重複；本次獎勵仍照發，只是之後不再繼續累積其他獎勵（先派完獎勵再關閉）
                // 這裡只查真實姓名，不跟著查 IP/手機，避免剛好有其他新註冊會員撞到同 IP/手機而誤判
                $blockRealnameMemberId = $this->resolveRealnameBlock($invitee, $settings);
                if ($blockRealnameMemberId > 0) {
                    $locked->block_realname_member_id = $blockRealnameMemberId;
                    $shouldClose = true;
                }
            }

            if ($locked->deposit_status == 0) {
                $totalDeposit = (string) Order::where('member_id', $invitee->id)
                    ->where('status', OrderStatusEnum::DEPOSIT_FINISHED->value)
                    ->where('created_at', '>=', $invitee->created_at)
                    ->sum('amount');

                // orders 儲值沒達標時，若代理開啟「後台開分視同儲值」，補查 transaction_logs
                // 的開分紀錄一併累計再判斷一次；開關關閉或 orders 已達標則不查，省一次查詢
                if (Common::numCompare($totalDeposit, $settings->deposit_point, 4) < 0
                    && $backendDepositCountsAsDeposit
                ) {
                    $backendCreditTotal = (string) TransactionLog::where('member_id', $invitee->id)
                        ->where('type', TransactionEnum::DEPOSIT->value)
                        ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
                        ->where('created_at', '>=', $invitee->created_at)
                        ->sum('point');

                    $totalDeposit = Common::numAdd($totalDeposit, $backendCreditTotal, 4);
                }

                if (Common::numCompare($totalDeposit, $settings->deposit_point, 4) >= 0) {
                    $beforePoint = $locked->point;
                    $locked->point = $this->capPoint(Common::numAdd($locked->point, $settings->deposit_reward, 4), $settings->max_reward);
                    $locked->deposit_status = 1;
                    $dirty = true;

                    EventInvitePointLog::create([
                        'summary_start_time' => now()->format('Y-m-d H:00:00'),
                        'type' => 2,
                        'member_id' => $invitee->id,
                        'before_bet' => 0,
                        'after_bet' => 0,
                        'before_point' => $beforePoint,
                        'after_point' => $locked->point,
                    ]);
                }
            }

            if ($dirty) {
                if ($shouldClose) {
                    // 真實姓名重複，關閉此參加紀錄，之後不再結算
                    $locked->status = 2;
                } elseif (Common::numCompare($settings->max_reward, '0', 4) > 0 && Common::numCompare($locked->point, $settings->max_reward, 4) >= 0) {
                    // 累加後金額達到上限，標記為已完成，之後不用再檢查
                    $locked->status = 1;
                }

                $locked->save();
                $join->point = $locked->point;
                $join->realname_status = $locked->realname_status;
                $join->deposit_status = $locked->deposit_status;
                $join->register_realname = $locked->register_realname;
                $join->status = $locked->status;
            }
        });
    }

    /**
     * point 不得超過 event_invite_settings.max_reward，max_reward <= 0 視為未設定上限
     */
    private function capPoint(string $point, string $maxReward): string
    {
        if (Common::numCompare($maxReward, '0', 4) > 0 && Common::numCompare($point, $maxReward, 4) > 0) {
            return $maxReward;
        }

        return $point;
    }

    /**
     * 領取自己邀請的所有會員目前累積可領取的獎勵（point > receive），逐筆結算並歸戶到自己的餘額
     * 被邀請人的真實姓名獎勵未通過（realname_status != 1）前，即使已有其他獎勵（如儲值）累積的 point 也不能領取
     *
     * @return array{setting: array<string, mixed>, joins: Collection<int, EventInviteUserJoin>}
     */
    public function receive(Member $member, int $languageId = 0): array
    {
        $agent = $member->agent;
        $settings = $agent ? $this->resolveActiveSetting($agent) : null;

        // 活動未設定、已停用（status != 1）、或該代理被排除，不允許領獎
        if (!$settings) {
            throw new ApiException('Event is not active');
        }

        $joins = EventInviteUserJoin::query()
            ->where('invite_member_id', $member->id)
            ->where('status', '!=', 2)
            ->where('realname_status', 1)
            ->whereColumn('point', '>', 'receive')
            ->with('member.agent')
            ->get();

        $joins = $joins->map(function (EventInviteUserJoin $join) use ($member) {
            try {
                return $this->receiveOne($member, $join);
            } catch (ApiException) {
                return $join;
            }
        });

        return [
            'setting' => $this->buildSetting($member, $languageId, $joins),
            'joins' => $joins,
        ];
    }

    /**
     * 併入活動列表（EventV2Controller::list）用：邀請好友活動只有一筆固定卡片，
     * 依 event_invite_settings.status 決定該代理是否啟用此活動，未啟用（或未設定）就不回傳任何項目，
     * 讓前端單純依清單有無這張卡片來決定要不要顯示，不需額外欄位判斷。
     */
    public function getEventInviteList(UserData $userData): Collection
    {
        $languageId = $userData->languageId;
        $member = $userData->member;

        $collection = new Collection;

        $agent = $member?->agent;
        $settings = $agent ? $this->resolveActiveSetting($agent) : null;

        if (!$settings) {
            return $collection;
        }

        $title = EventInviteTitle::where('language_id', $languageId)->first()
            ?? EventInviteTitle::where('language_id', 0)->first();

        $image = EventInviteImage::where('language_id', $languageId)->first()
            ?? EventInviteImage::where('language_id', 0)->first();

        $collection->push(EventV2ListItemData::from([
            'event_v1_id' => null,
            'event_v2_id' => null,
            'tp_event' => 2,
            'name' => $title?->title ?? '',
            'image' => $image?->image,
            'params' => null,
            'game_type_ids' => [],
            'sort' => $settings->sort,
            'created_at' => $settings->created_at?->toDateTimeString(),
            'end_time' => null,
        ]));

        return $collection;
    }

    private function receiveOne(Member $member, EventInviteUserJoin $join): EventInviteUserJoin
    {
        try {
            DB::beginTransaction();

            $locked = EventInviteUserJoin::query()
                ->lockForUpdate()
                ->find($join->id);

            if (!$locked) {
                throw new ApiException('Event participation not found');
            }

            $availablePoint = Common::numSub($locked->point, $locked->receive, 4);
            if (Common::numCompare($availablePoint, '0', 4) <= 0) {
                throw new ApiException('Nothing available to receive');
            }

            $beforeReceive = $locked->receive;
            $afterReceive = Common::numAdd($beforeReceive, $availablePoint, 4);

            $this->giveMemberReward($member, $availablePoint);

            $locked->update(['receive' => $afterReceive]);

            EventInvitePointLog::create([
                'summary_start_time' => now(),
                'type' => 4,
                'member_id' => $locked->member_id,
                'before_bet' => $locked->bet,
                'after_bet' => $locked->bet,
                'before_point' => $locked->point,
                'after_point' => $locked->point,
                'before_receive' => $beforeReceive,
                'after_receive' => $afterReceive,
            ]);

            DB::commit();

            // 保留原本已 eager load 的 member/agent 關聯，只同步實際變動的欄位
            $join->receive = $locked->receive;

            return $join;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function giveMemberReward(Member $member, string $point): int
    {
        $wallet = Member::lockForUpdate()->where('id', $member->id)->first();
        if (!is_object($wallet)) {
            throw new \Exception('Member not found');
        }

        $beforeBalance = $wallet->balance;
        $afterBalance = Common::numAdd($beforeBalance, $point, 4);
        $wallet->update([
            'balance' => $afterBalance,
        ]);

        $logResult = TransactionLog::create([
            'transaction_no' => Common::getTransactionNo(),
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'point' => $point,
            'vendor_point' => 0,
            'rate' => 0,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'type' => TransactionEnum::ACTIVITY_AWARD->value,
            'operator' => 'system',
            'remark' => 'Event invite reward',
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
            'admin' => 0,
        ]);

        if (!$logResult || !$logResult->id) {
            throw new \Exception('Transaction log create failed');
        }

        return $logResult->id;
    }
}
