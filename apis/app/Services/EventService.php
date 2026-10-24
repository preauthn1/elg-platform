<?php

declare(strict_types=1);

namespace App\Services;

use App\CacheKeys\CacheKeys;
use App\Data\EventCodeData;
use App\Data\EventSummaryData;
use App\Data\EventV2ListItemData;
use App\Data\UserData;
use App\Enums\Event\BlockTypeEnum;
use App\Enums\Event\EventActionIdEnum;
use App\Enums\Event\PrizeTypeEnum;
use App\Enums\General\CacheTtl;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventCode;
use App\Models\EventDetail;
use App\Models\EventInviteRecord;
use App\Models\EventPayoutCancel;
use App\Models\EventProcess;
use App\Models\Member;
use App\Models\MemberCode;
use App\Models\MemberTelegramRel;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\SP\SP_payout_event_invite;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EventService
{
    public function getEventList(UserData $userData): Collection
    {
        $now = Carbon::now();
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $list = Event::query()
            ->with([
                'prize',
                'eventName',
                'eventImage',
                'eventDefaultImage',
            ])
            ->whereIn('agent_id', $agent->hierarchy_array)
            ->where('status', 1)
            ->where('start_time', '<=', $now)
            ->whereDoesntHave('eventExcludeAgents', function ($query) use ($agent) {
                $query->whereIn('agent_id', $agent->hierarchy_array);
            })
            ->withWhereHas('eventDetail', function ($query) use ($now) {
                $query->where('status', 1)
                    ->where(function ($query) use ($now) {
                        $query->where('start_time', '<=', $now)
                            ->where('end_time', '>=', $now);
                    })
                    ->whereNotIn('action_id', [8]);
            })
            ->whereHas('prize', function ($query) use ($now) {
                $query->where('status', 1)
                    ->where('expire_date', '>', $now);
            })
            ->get();

        $collection = new Collection;
        foreach ($list as $item) {
            $gameTypeIds = [];
            if (!empty($item->eventDetail->game_type_id)) {
                $gameTypeIds[] = $item->eventDetail->game_type_id;
            }
            $collection->push(EventV2ListItemData::from([
                'event_v1_id' => $item->id,
                'event_v2_id' => null,
                'tp_event' => null,
                'name' => $item->eventName?->title ?: $item->title,
                'image' => $item->eventImage?->image ?: $item->eventDefaultImage?->image,
                'params' => [
                    'type' => $item->prize?->type_id,
                    'category' => $item->category,
                ],
                'game_type_ids' => $gameTypeIds,
                'sort' => $item->sort,
                'created_at' => $item->created_at?->toDateTimeString(),
                'end_time' => $item->end_time,
            ]));
        }

        return $collection;
    }

    public function getEventSummary(int $event_id, Agent $agent, bool $check_number = true): ?EventSummaryData
    {
        $agentIds = $agent->hierarchy_array;

        $event = Event::query()
            ->with([
                'eventDetail' => function ($query) {
                    $query->select([
                        'id',
                        'event_id',
                        'bind_unlimited',
                        'ip_block',
                        'global_ip_block',
                        'phone_block',
                        'global_phone_block',
                        'account_block',
                        'global_account_block',
                        'realname_block',
                        'tgid_block',
                    ]);
                },
                'prize' => function ($query) {
                    $query->select([
                        'id',
                        'event_id',
                    ]);
                },
                'prize.prizeContents' => function ($query) {
                    $query->select([
                        'id',
                        'prize_id',
                        'name',
                        'number',
                        'sent',
                        'point',
                    ]);
                },
            ])
            ->where('id', $event_id)
            ->where('status', 1)
            ->whereIn('agent_id', $agentIds);

        $event->select([
            'id',
            'auto_payout',
        ]);

        $event->whereHas('eventDetail', function ($query) {
            $query->where('status', 1);
        });

        $event->whereHas('prize', function ($query) {
            $query->where('status', 1);
        });

        $event->whereHas('prize.prizeContents', function ($query) use ($check_number) {
            $query->where('status', 1);
            if ($check_number) {
                $query->where(function ($query) {
                    $query->where('number', -1)
                        ->orWhereRaw('(number - distribute) > 0');
                });
            }
        });

        $result = $event->first();

        if (!$result) {
            return null;
        }

        // lad('$result', $result);

        return EventSummaryData::from([
            'id' => $result->id,
            'auto_payout' => $result->auto_payout,
            'event_detail_id' => $result->eventDetail->id,
            'event_bind_unlimited' => $result->eventDetail->bind_unlimited,
            'ip_block' => $result->eventDetail->ip_block,
            'global_ip_block' => $result->eventDetail->global_ip_block,
            'phone_block' => $result->eventDetail->phone_block,
            'global_phone_block' => $result->eventDetail->global_phone_block,
            'account_block' => $result->eventDetail->account_block,
            'global_account_block' => $result->eventDetail->global_account_block,
            'realname_block' => $result->eventDetail->realname_block,
            'tgid_block' => $result->eventDetail->tgid_block,
            'prize_id' => $result->prize->id,
            'prize_content_id' => $result->prize->prizeContents[0]->id,
            'prize_content_name' => $result->prize->prizeContents[0]->name,
            'prize_content_number1' => $result->prize->prizeContents[0]->number,
            'prize_content_sent' => $result->prize->prizeContents[0]->sent,
            'prize_content_point' => $result->prize->prizeContents[0]->point,
        ]);
    }

    public function getEventCode($invitationCode, int|array $actionId): ?EventCodeData
    {
        // 1. 嘗試以活動邀請碼查詢
        if (
            is_string($invitationCode) && strlen($invitationCode) == 10
            && strtoupper(substr($invitationCode, 0, 2)) == config('custom.event_code_prefix')
        ) {

            $eventCode = EventCode::query()
                ->where('code', $invitationCode)
                ->first();
            if ($eventCode && $eventCode->member) {
                return EventCodeData::from([
                    'agentId' => $eventCode->member->parent,
                    'eventId' => $eventCode->event_id,
                    'memberId' => $eventCode->member->id,
                ]);
            }
        }

        // 2. 嘗試以會員推廣碼查詢
        if ($invitationCode != '') {
            $memberCode = MemberCode::where('agent_code', $invitationCode)->first();
            if ($memberCode && $memberCode->member) {
                $eventId = $this->getEventIdByMember($memberCode->member, $actionId);
                if ($eventId) {
                    return EventCodeData::from([
                        'agentId' => $memberCode->member->parent,
                        'eventId' => $eventId,
                        'memberId' => $memberCode->member->id,
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * 取得邀請活動
     */
    public function getEventIdByMember(Member $member, int|array $actionId): ?int
    {
        $agentIds = $member->agent->hierarchy_array;

        $event = Event::query()
            ->whereIn('agent_id', $agentIds)
            ->where('status', 1)
            ->where('start_time', '<=', now())
            ->where('end_time', '>=', now())
            ->whereHas('eventDetail', function ($query) use ($actionId) {
                if (is_array($actionId) === false) {
                    $actionId = [$actionId];
                }

                $query->whereIn('action_id', $actionId)->where('status', 1);
            })
            ->select(['id'])
            ->first();

        return $event ? $event->id : null;
    }

    /**
     * 添加獎勵前檢查派獎條件
     *
     * @return bool 是否符合派獎條件 true: 符合, false: 不符合
     */
    public function checkBlockRule(Member $member, EventSummaryData $event, EventCodeData $eventCodeData): bool
    {
        $is_block = false;
        $block_type = 0;
        $block_member_id = 0;

        // 檢查真實姓名
        if ($event->realname_block && $member->realname_validate == 0) {
            // 直接回傳false就好，會在後台通過真實姓名審核的時候派發
            return false;
        }

        // 檢查真實姓名
        if ($event->global_account_block && !$is_block) { // @phpstan-ignore booleanNot.alwaysTrue (首個條件，後續同 pattern 做互斥保護)
            if (empty($member->first_name)) {
                $is_block = true;
                $block_type = BlockTypeEnum::GLOBAL_ACCOUNT_BLOCK->value;
            } else {
                $check = Member::query()
                    ->where('first_name', $member->first_name)
                    ->where('id', '!=', $member->id)
                    ->first();
                if (is_object($check)) {
                    $is_block = true;
                    $block_member_id = $check->id;
                    $block_type = BlockTypeEnum::GLOBAL_ACCOUNT_BLOCK->value;
                }
            }
        }

        // 檢查 ip
        if ($event->global_ip_block && !$is_block) {
            if (empty($member->register_ip)) {
                $is_block = true;
                $block_type = BlockTypeEnum::GLOBAL_IP_BLOCK->value;
            } else {
                $check = Member::query()
                    ->where('register_ip', $member->register_ip)
                    ->where('id', '!=', $member->id)
                    ->first();
                if (is_object($check)) {
                    $is_block = true;
                    $block_member_id = $check->id;
                    $block_type = BlockTypeEnum::GLOBAL_IP_BLOCK->value;
                }
            }
        }

        // 檢查手機
        if ($event->global_phone_block && !$is_block) {
            if (empty($member->mobile)) {
                $is_block = true;
                $block_type = BlockTypeEnum::GLOBAL_PHONE_BLOCK->value;
            } else {
                $check = Member::query()
                    ->where('mobile', $member->mobile)
                    ->where('id', '!=', $member->id)
                    ->first();
                if (is_object($check)) {
                    $is_block = true;
                    $block_member_id = $check->id;
                    $block_type = BlockTypeEnum::GLOBAL_PHONE_BLOCK->value;
                }
            }
        }

        // 檢查 Telegram ID
        if ($event->tgid_block && !$is_block) {
            $member_telegram_rel = MemberTelegramRel::query()
                ->where('member_id', $member->id)
                ->first();
            if (is_object($member_telegram_rel)) {
                $check_tgid = MemberTelegramRel::query()
                    ->where('tg_user_id', $member_telegram_rel->tg_user_id)
                    ->where('member_id', '!=', $member->id)
                    ->first();
                if (is_object($check_tgid)) {
                    $is_block = true;
                    $block_member_id = $check_tgid->member_id;
                    $block_type = BlockTypeEnum::TGID_BLOCK->value;
                }
            }
        }

        if ($is_block) {
            EventPayoutCancel::create([
                'event_id' => $event->id,
                'prize_content_id' => $event->prize_content_id,
                'from_member_id' => $member->id,
                'member_id' => $eventCodeData->memberId,
                'type' => $block_type,
                'block_member_id' => $block_member_id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * 寫入派獎紀錄
     * 派獎狀態 status: 0=未達標, 1=已達標, 2=已派獎
     */
    public function updateEventProcess($eventDetailId, $memberId): EventProcess
    {
        $process = EventProcess::firstOrCreate(
            [
                'detail_id' => $eventDetailId,
                'member_id' => $memberId,
            ],
            [
                'point' => 0,
                'status' => 0,
                'admin' => 0,
            ]
        );

        $process->increment('point');

        return $process->fresh();
    }

    /**
     * 寫入領獎紀錄
     */
    public function createPrizeMemberRecord($member_id, $prize_id, $event_detail_id)
    {
        $data = [
            'member_id' => $member_id,
            'prize_id' => $prize_id,
            'event_detail_id' => $event_detail_id,
            'status' => 0,
            'admin' => 51,
        ];

        return PrizeMember::create($data);
    }

    public function findAvailableEventDetailIds(array $agentIds): array
    {
        return Cache::safeRemember(
            CacheKeys::availableEventDetailIds($agentIds),
            CacheTtl::ONE_MINUTE->value,
            function () use ($agentIds) {
                $now = now();

                return Event::query()
                    ->whereIn('agent_id', $agentIds)
                    ->where('status', 1)
                    ->where('start_time', '<=', $now)
                    ->whereDoesntHave('eventExcludeAgents', function ($query) use ($agentIds) {
                        $query->whereIn('agent_id', $agentIds);
                    })
                    ->whereHas('prize', function ($query) use ($now) {
                        $query->where('status', 1)
                            ->where('expire_date', '>', $now);
                    })
                    ->whereHas('eventDetail', function ($query) {
                        $query->where('status', 1)
                            ->whereNotIn('action_id', [8])
                            ->whereIn('action_id', [53, 54, 55]);
                    })
                    ->with([
                        'eventDetail:id,event_id',
                    ])
                    ->get()
                    ->pluck('eventDetail')
                    ->flatten()
                    ->pluck('id')
                    ->toArray();
            }
        );
    }

    public function getPrizeCount(array $detailIds, int $memberId): int
    {
        $now = now();

        return PrizeMember::query()
            ->whereIn('event_detail_id', $detailIds)
            ->where('member_id', $memberId)
            ->where('status', 0)
            ->where(function ($query) use ($now) {
                $query->where('expired_time', '>=', $now)
                    ->orWhereNull('expired_time');
            })
            ->groupBy(['event_detail_id'])
            ->count('event_detail_id');
    }

    /**
     * 派發邀請玩家獎勵
     */
    public function distributeInviteEvent(Member $member): bool
    {
        // 檢查是否符合邀請獎勵
        $invitationCode = $member->invitation_code;
        $eventCodeData = $this->getEventCode($invitationCode, EventActionIdEnum::INVITE_PLAYER_OFFERS->value);

        // 符合邀請獎勵
        if (is_null($eventCodeData)) {
            return false;
        }

        $event = $this->getEventSummary($eventCodeData->eventId, $member->agent, false);
        if (is_null($event)) {
            return false;
        }

        // 檢查是否符合派獎條件
        $isAllow = $this->checkBlockRule($member, $event, $eventCodeData);
        if (!$isAllow) {
            return false;
        }

        if ($event->auto_payout == 1) {
            // 直接派獎
            App::call([new SP_payout_event_invite, 'handle'], [
                'memberId' => $eventCodeData->memberId,
                'eventId' => $event->id,
                'eventDetailId' => $event->event_detail_id,
                'prizeId' => $event->prize_id,
            ]);
        } else {
            // 排程派獎
            // 寫入派獎紀錄; 派獎狀態: 0=未達標, 1=已達標, 2=已派獎
            $this->updateEventProcess($event->event_detail_id, $eventCodeData->memberId);
            // 寫入領獎紀錄
            $this->createPrizeMemberRecord($eventCodeData->memberId, $event->prize_id, $event->event_detail_id);
        }

        return true;
    }

    /**
     * 寫入下線儲值獎勵註冊紀錄
     */
    public function insertEventInviteRecord(Member $member)
    {
        // 檢查是否符合邀請條件
        $invitationCode = $member->invitation_code;
        $eventCodeData = $this->getEventCode($invitationCode, [
            EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            EventActionIdEnum::TPOWER_INVITE_PLAYER->value,
        ]);

        if (is_null($eventCodeData)) {
            return false;
        }

        $event = $this->getEventSummary($eventCodeData->eventId, $member->agent, false);
        if (is_null($event)) {
            return false;
        }

        // 寫入邀請紀錄
        $this->createEventInviteRecord($member, $member->agent, $event, $eventCodeData);
    }

    public function createEventInviteRecord(Member $member, Agent $agent, EventSummaryData $event, EventCodeData $eventCodeData)
    {
        $ip_duplicate = $phone_duplicate = $realname_duplicate = $telegram_id_duplicate = 0;
        $block_member_id = [];

        // 檢查帳號重複（真實姓名）
        if (!empty($member->first_name)) {
            $duplicateMember = $this->findDuplicateMemberInAgentHierarchy($member, $agent, 'first_name', $member->first_name);
            if ($duplicateMember) {
                $realname_duplicate = 1;
                $block_member_id['realname'] = $duplicateMember->id;
            }
        }

        // 檢查 IP 重複
        if (!empty($member->register_ip)) {
            $duplicateMember = $this->findDuplicateMemberInAgentHierarchy($member, $agent, 'register_ip', $member->register_ip);
            if ($duplicateMember) {
                $ip_duplicate = 1;
                $block_member_id['register_ip'] = $duplicateMember->id;
            }
        }

        // 檢查手機重複
        if (!empty($member->mobile)) {
            $duplicateMember = $this->findDuplicateMemberInAgentHierarchy($member, $agent, 'mobile', $member->mobile);
            if ($duplicateMember) {
                $phone_duplicate = 1;
                $block_member_id['mobile'] = $duplicateMember->id;
            }
        }

        // 檢查 Telegram ID 重複
        $memberTelegramRel = MemberTelegramRel::query()
            ->where('member_id', $member->id)
            ->first();
        if ($memberTelegramRel) {
            $duplicateMember = Member::query()
                ->join('member_telegram_rels as mtr', 'members.id', '=', 'mtr.member_id')
                ->where('mtr.tg_user_id', $memberTelegramRel->tg_user_id)
                ->where('members.id', '!=', $member->id)
                ->whereHas('agent', function ($query) use ($agent) {
                    $query->where('hierarchy_path', 'like', $agent->hierarchy_path . '%');
                })
                ->select('members.id')
                ->first();
            if ($duplicateMember) {
                $telegram_id_duplicate = 1;
                $block_member_id['tg_user_id'] = $duplicateMember->id;
            }
        }

        return EventInviteRecord::create([
            'event_id' => $event->id,
            'member_id' => $member->id,
            'invite_member_id' => $eventCodeData->memberId,
            'ip_duplicate' => $ip_duplicate,
            'phone_duplicate' => $phone_duplicate,
            'realname_duplicate' => $realname_duplicate,
            'telegram_id_duplicate' => $telegram_id_duplicate,
            'block_member_id' => json_encode($block_member_id),
        ]);
    }

    /**
     * 在代理商層級中查找重複的會員
     *
     * @param  Member  $member  當前會員
     * @param  Agent  $agent  代理商
     * @param  string  $field  要檢查的欄位名稱
     * @param  mixed  $value  要檢查的欄位值
     * @return Member|null 找到的重複會員，如果沒有則返回 null
     */
    private function findDuplicateMemberInAgentHierarchy(Member $member, Agent $agent, string $field, $value): ?Member
    {
        return Member::query()
            ->where($field, $value)
            ->where('id', '!=', $member->id)
            ->whereHas('agent', function ($query) use ($agent) {
                $query->where('hierarchy_path', 'like', $agent->hierarchy_path . '%');
            })
            ->first();
    }

    /**
     * 以 UNION ALL 合併 Event V1（prize_members）與 Event V2（event_v2_user_joins）領獎紀錄，依領獎時間新到舊排序後分頁。
     *
     * 每筆 object 欄位：
     * - record_source: "eventv1" or "eventv2"
     * - record_id: 來源資料列 id
     * - event_name: 活動名稱（依語系；V2 無語系時退回英文 language_id=1）
     * - content_name: V1 為獎項名稱（prize_contents.name）；V2 與 getEventV2ClaimRecords 之 name 相同（語系活動名稱）
     * - point: 獎勵點數／金額
     * - claimed_at: 領獎時間 UTC
     */
    public function getClaimRecords(Member $member, string $start, string $end, int $languageId): Collection
    {
        $memberId = $member->id;
        // 先寫死2,  因為別的分支改了 EventV2UserJoinStatusEnum 的位置, 等分支合併後再改回來
        // $v2CompleteStatus = EventV2UserJoinStatusEnum::COMPLETE->value;
        $v2CompleteStatus = 2;

        $v1 = DB::table('prize_members as m')
            ->join('prize_contents as c', 'c.id', '=', 'm.content_id')
            ->join('prizes as p', 'p.id', '=', 'c.prize_id')
            ->join('events as e', 'e.id', '=', 'p.event_id')
            ->leftJoin('event_names as n', function ($join) use ($languageId) {
                $join->on('n.event_id', '=', 'e.id')
                    ->where('n.language_id', '=', $languageId);
            })
            ->where('m.member_id', '=', $memberId)
            ->where('m.status', '=', 1)
            ->where('m.updated_at', '>=', $start)
            ->where('m.updated_at', '<=', $end)
            ->select(
                DB::raw('"eventv1" as record_source'),
                'm.id as record_id',
                DB::raw('(COALESCE(n.title, e.title)) as event_name'),
                DB::raw('c.name as prize_name'),
                DB::raw('(CASE WHEN c.type = 1 THEN TRUNCATE(m.lottery_point, 2) ELSE c.point END) as point'),
                DB::raw('m.updated_at as claimed_at')
            );

        $v2 = DB::table('event_v2_user_joins as j')
            ->leftJoin('event_v2_details as d', 'd.id', '=', 'j.event_v2_detail_id')
            ->leftJoin('event_v2_detail_langs as dl', function ($join) use ($languageId) {
                $join->on('dl.event_v2_detail_id', '=', 'd.id')
                    ->where('dl.language_id', '=', $languageId);
            })
            ->leftJoin('event_v2_detail_langs as lang_default', function ($join) {
                $join->on('lang_default.event_v2_detail_id', '=', 'd.id')
                    ->where('lang_default.language_id', '=', 1);
            })
            ->where('j.member_id', '=', $memberId)
            ->where('j.status', '=', $v2CompleteStatus)
            ->where('j.end_time', '>=', $start)
            ->where('j.end_time', '<=', $end)
            ->select(
                DB::raw('"eventv2" as record_source'),
                'j.id as record_id',
                DB::raw('COALESCE(dl.name, lang_default.name) as event_name'),
                DB::raw('"Points" as prize_name'),
                DB::raw('j.withdraw as point'),
                DB::raw('j.end_time as claimed_at')
            );

        $combined = $v1->unionAll($v2);
        $wrapped = DB::raw('(' . $combined->toSql() . ') as merged_claims');

        $items = DB::table($wrapped)
            ->mergeBindings($combined)
            ->orderByDesc('claimed_at')
            ->get();

        return $items;
    }

    /**
     * events - 舊版活動列表（V1）
     *
     * 遷移自 API_Frontend EventController::new_list。tpower-master 與 vw-master 互有獨有邏輯，
     * 本次取聯集：tpower 的 action 17/56/59、prize type_id=10、times_a_day、活動時間過濾，
     * 與 vw 的 flow_limit_game_type 並存，皆由 action_id / prize 設定自然分流。
     *
     * @return array{data: list<object>, is_reddeem: int, total: int}
     */
    public function getLegacyEventList(Member $member, Agent $agent, int $languageId): array
    {
        $agentIds = $agent->hierarchy_array;
        $now = now()->toDateTimeString();

        $events = Event::query()
            ->join('event_details as ed', function ($join) {
                $join->on('ed.event_id', 'events.id')
                    ->where('ed.status', 1)
                    ->whereRaw('ed.start_time <= NOW()')
                    ->whereRaw('ed.end_time >= NOW()')
                    ->whereNotIn('ed.action_id', [EventActionIdEnum::SLOT_MACHINE_COMPETITION->value]);
            })
            ->leftJoin('event_images as ei', function ($join) use ($languageId) {
                $join->on('ei.event_id', 'events.id')->where('ei.language_id', $languageId);
            })
            ->leftJoin('event_images as eid', function ($join) {
                $join->on('eid.event_id', 'events.id')->where('eid.language_id', 1);
            })
            ->leftJoin('event_names as en', function ($join) use ($languageId) {
                $join->on('en.event_id', 'events.id')->where('en.language_id', $languageId);
            })
            ->join('prizes as p', function ($join) use ($now) {
                $join->on('p.event_id', 'events.id')
                    ->where('p.status', 1)
                    ->where('p.expire_date', '>', $now);
            })
            ->leftJoin('event_exclude_agents as eea', function ($join) use ($agentIds) {
                $join->on('eea.event_id', 'events.id')->whereIn('eea.agent_id', $agentIds);
            })
            ->whereIn('events.agent_id', $agentIds)
            ->where('events.status', 1)
            ->whereRaw('events.start_time <= NOW()')
            ->whereNull('eea.id')
            ->groupBy('events.id')
            ->orderByDesc('events.created_at')
            ->select([
                'events.id',
                'events.category',
                'events.auto_payout',
                'ed.id as event_detail_id',
                'ed.point as event_detail_point',
                'ed.expire_hour as event_detail_expire_hour',
                DB::raw('IFNULL(en.title, events.title) as title'),
                DB::raw('IFNULL(en.content, events.content) as content'),
                'events.type_id',
                'events.agent_id',
                'p.id as prize_id',
                'p.number as prize_number',
                'p.is_repeatedly as prize_is_repeatedly',
                DB::raw('DATE_ADD(p.expire_date, INTERVAL 8 HOUR) as prize_expire_date'),
                DB::raw('DATE_ADD(p.expire_date, INTERVAL 8 HOUR) as expire_date'),
                DB::raw('DATE_ADD(p.draw_date, INTERVAL 8 HOUR) as prize_draw_date'),
                DB::raw('DATE_ADD(events.start_time, INTERVAL 8 HOUR) as event_start_time'),
                DB::raw('DATE_ADD(events.end_time, INTERVAL 8 HOUR) as event_end_time'),
                'p.type_id as type',
                'ed.action_id as action_id',
                DB::raw('IFNULL(ei.image, eid.image) as image_url'),
                DB::raw('IFNULL(ei.image_pc, eid.image_pc) as image_pc_url'),
            ])
            ->get();

        $total = $events->count();
        $isRedeem = 0;
        $buckets = [];

        foreach ($events as $row) {
            $state = $this->buildLegacyEventRow($row, $member, $agent, $languageId);
            $isRedeem = $isRedeem || $state['is_redeem'] ? 1 : 0;
            $buckets[$state['sort']][] = $row;
        }

        ksort($buckets);
        $data = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $item) {
                $data[] = $item;
            }
        }

        return [
            'data' => $data,
            'is_reddeem' => $isRedeem,
            'total' => $total,
        ];
    }

    /**
     * eventsByDomain - 舊版活動列表（免登入，依 domain 判定品牌）
     *
     * 遷移自 API_Frontend EventController::listByDomain（tpower-master / vw-master 完全相同）。
     * 為 getLegacyEventList 的未登入版：不帶任何會員狀態，兌換數、進度、今日達成皆為固定值。
     *
     * @return array{data: list<object>, is_reddeem: int, total: int}
     */
    public function getLegacyEventListByDomain(Agent $agent, int $languageId): array
    {
        $agentIds = $agent->hierarchy_array;
        $now = now()->toDateTimeString();

        $events = Event::query()
            ->join('event_details as ed', function ($join) {
                $join->on('ed.event_id', 'events.id')
                    ->where('ed.status', 1)
                    ->whereNotIn('ed.action_id', [EventActionIdEnum::SLOT_MACHINE_COMPETITION->value]);
            })
            ->leftJoin('event_images as ei', function ($join) use ($languageId) {
                $join->on('ei.event_id', 'events.id')->where('ei.language_id', $languageId);
            })
            ->leftJoin('event_images as eid', function ($join) {
                $join->on('eid.event_id', 'events.id')->where('eid.language_id', 1);
            })
            ->leftJoin('event_names as en', function ($join) use ($languageId) {
                $join->on('en.event_id', 'events.id')->where('en.language_id', $languageId);
            })
            ->join('prizes as p', function ($join) use ($now) {
                $join->on('p.event_id', 'events.id')
                    ->where('p.status', 1)
                    ->where('p.expire_date', '>', $now);
            })
            ->whereIn('events.agent_id', $agentIds)
            ->where('events.status', 1)
            ->groupBy('events.id')
            ->orderByDesc('events.id')
            ->select([
                'events.id',
                'ed.id as event_detail_id',
                'ed.point as event_detail_point',
                DB::raw('IFNULL(en.title, events.title) as title'),
                DB::raw('IFNULL(en.content, events.content) as content'),
                'events.type_id',
                'events.agent_id',
                'p.id as prize_id',
                'p.number as prize_number',
                'p.is_repeatedly as prize_is_repeatedly',
                DB::raw('DATE_ADD(p.expire_date, INTERVAL 8 HOUR) as prize_expire_date'),
                DB::raw('DATE_ADD(p.expire_date, INTERVAL 8 HOUR) as expire_date'),
                DB::raw('DATE_ADD(p.draw_date, INTERVAL 8 HOUR) as prize_draw_date'),
                DB::raw('DATE_ADD(events.start_time, INTERVAL 8 HOUR) as event_start_time'),
                DB::raw('DATE_ADD(events.end_time, INTERVAL 8 HOUR) as event_end_time'),
                'p.type_id as type',
                'ed.action_id as action_id',
                DB::raw('IFNULL(ei.image, eid.image) as image_url'),
                DB::raw('IFNULL(ei.image_pc, eid.image_pc) as image_pc_url'),
            ])
            ->get();

        $total = $events->count();
        $buckets = [];

        foreach ($events as $row) {
            $sort = $this->buildLegacyEventRowByDomain($row, $agent, $languageId);
            $buckets[$sort][] = $row;
        }

        ksort($buckets);
        $data = [];
        foreach ($buckets as $bucket) {
            foreach ($bucket as $item) {
                $data[] = $item;
            }
        }

        return [
            'data' => $data,
            // 未登入無可兌換的活動
            'is_reddeem' => 0,
            'total' => $total,
        ];
    }

    /**
     * 免登入版單一活動的按鈕狀態與附屬資料（直接寫回 $row）
     */
    private function buildLegacyEventRowByDomain(object $row, Agent $agent, int $languageId): int
    {
        $now = now()->toDateTimeString();
        $sort = 2;
        $text = __('param.prize_button.exchanged');

        // 未登入無會員兌換紀錄，沿用舊版固定值
        $prize = DB::table('prizes as p')
            ->where('p.event_id', $row->id)
            ->where('p.status', 1)
            ->select([
                'p.id',
                'p.name',
                'p.expire_date',
                DB::raw('1 as exchanged'),
                DB::raw('0 as exchange'),
                DB::raw('1 as expired'),
                DB::raw('COUNT(*) as count'),
            ])
            ->first();

        $row->prize_name = $prize->name;
        $row->count = $prize->count;

        // 每日領取一次：未登入不會有活動，直接帶 0
        if ($row->action_id == EventActionIdEnum::PICK_UP_ONCE_A_DAY->value) {
            $row->reached_today = 0;
        }

        if ($prize->expire_date < $now && $prize->expire_date != '') {
            $sort = 4;
            $text = __('param.prize_button.expired');
        } elseif ($prize->exchanged > 0 && $row->prize_is_repeatedly == 0) {
            $sort = 3;
            $text = __('param.prize_button.exchanged');
        }

        $row->show_exchange = 0;
        $row->show_exchange_text = $text;
        $row->remaining_number = 0;

        $this->attachLegacyEventDetailsByDomain($row, $agent, $languageId);

        if ($row->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
            $this->attachSlotRebatePayoutDetailsByDomain($row, $languageId);
        }

        $row->prize_content = $this->getLegacyPrizeContent($row, $languageId);

        unset($row->event_detail_id, $row->event_detail_point);

        return $sort;
    }

    /**
     * 免登入版活動達成條件明細（無會員進度，now_point 固定為 0）
     */
    private function attachLegacyEventDetailsByDomain(object $row, Agent $agent, int $languageId): void
    {
        $now = now()->toDateTimeString();

        $query = DB::table('event_details as d')
            ->leftJoin('order_type_names as otn', function ($join) use ($languageId) {
                $join->on('otn.type_id', 'd.order_type_id')->where('otn.language_id', $languageId);
            })
            ->leftJoin('game_types as gt', 'gt.id', 'd.game_type_id')
            ->leftJoin('game_type_names as gtn', function ($join) use ($languageId) {
                $join->on('gtn.game_type_id', 'd.game_type_id')->where('gtn.language_id', $languageId);
            });

        $query = $row->type == PrizeTypeEnum::W_JACKPOT->value
            ? $query->leftJoin('w_jackpots as ld', 'ld.event_detail_id', 'd.id')
            : $query->leftJoin('lucky_draws as ld', 'ld.event_detail_id', 'd.id');

        $query = $query->leftJoin('vendors as v', 'v.id', 'd.vendor_id')
            ->where('d.event_id', $row->id)
            ->where('d.status', 1);

        $point = 'd.point';
        if ($row->type == PrizeTypeEnum::V_JACKPOT->value) {
            $query->where('d.start_time', '<=', $now)->where('d.end_time', '>', $now);
            $point = DB::raw("CASE WHEN NOW() between '2020-11-10 16:00:00' and '2020-11-15 10:59:59' THEN 100 ELSE d.point END as point");
        } elseif ($row->type == PrizeTypeEnum::W_JACKPOT->value) {
            $query->where('d.start_time', '<=', $now)->where('d.end_time', '>', $now);
        }

        if ($row->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
            $query->where(function ($sub) use ($now) {
                $sub->where('d.start_time', '<=', $now)
                    ->orWhere('d.draw_time', '>', DB::raw('DATE_SUB(NOW(), INTERVAL d.expire_hour HOUR)'));
            })->limit(1);
        }

        $details = $query->select([
            'd.id',
            'd.action_id',
            'd.order_type_id',
            'otn.name as order_type_name',
            'd.vendor_id',
            'v.name as vendor_name',
            'd.game_id',
            'd.game_type_id',
            DB::raw('IFNULL(gtn.name, gt.name) as game_type_name'),
            'd.rate',
            $point,
            DB::raw('DATE_ADD(d.start_time, INTERVAL 8 HOUR) as start_time'),
            DB::raw('DATE_ADD(d.end_time, INTERVAL 8 HOUR) as end_time'),
            DB::raw('0 as now_point'),
            DB::raw("IFNULL(ld.no, '') as lucky_draw_no"),
            DB::raw('DATE_ADD(ld.draw_time, INTERVAL 8 HOUR) as prize_draw_date'),
        ])->get();

        if ($details->isEmpty()) {
            return;
        }

        $startTime = '';
        $endTime = '';
        $frontendNow = now()->toFrontendTime();

        foreach ($details as $detail) {
            // 邀請玩家優惠：未登入不需要真實邀請碼
            if ($detail->action_id == EventActionIdEnum::INVITE_PLAYER_OFFERS->value) {
                $detail->code = '-';
            }

            $detail->action_name = __('param.event_action.' . $detail->action_id);

            if ($detail->lucky_draw_no != '') {
                if (Carbon::parse($detail->start_time)->lte($frontendNow) && $frontendNow->lte(Carbon::parse($detail->end_time))) {
                    $row->title .= ' - ' . str_replace('{N}', $detail->lucky_draw_no, __('param.periods'));
                    $row->prize_draw_date = $detail->prize_draw_date;
                    $startTime = $detail->start_time;
                    $endTime = $detail->end_time;
                }
            } elseif ($detail->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
                $startTime = $row->event_start_time;
                $endTime = $row->event_end_time;
            } else {
                if ($startTime == '' || $startTime > $detail->start_time) {
                    $startTime = $detail->start_time;
                }
                if ($endTime == '' || $endTime < $detail->end_time) {
                    $endTime = $detail->end_time;
                }
            }

            $detail->vendor_maintenances = $this->getVendorMaintenance($detail->vendor_id, $agent);
        }

        $row->start_time = $startTime;
        $row->end_time = $endTime;
        $row->detail = $details;
    }

    /**
     * 免登入版老虎機輸分回扣派彩明細（無會員領取狀態，一律不可兌換）
     */
    private function attachSlotRebatePayoutDetailsByDomain(object $row, int $languageId): void
    {
        $payoutDetails = DB::table('event_details as d')
            ->leftJoin('game_types as gt', 'gt.id', 'd.game_type_id')
            ->leftJoin('game_type_names as gtn', function ($join) use ($languageId) {
                $join->on('gtn.game_type_id', 'd.game_type_id')->where('gtn.language_id', $languageId);
            })
            ->leftJoin('vendors as v', 'v.id', 'd.vendor_id')
            ->where('d.event_id', $row->id)
            ->where('d.status', 1)
            ->where('d.draw_time', '>', DB::raw('DATE_SUB(now(), INTERVAL expire_hour HOUR)'))
            ->where('d.draw_time', '<=', DB::raw('now()'))
            ->select([
                'd.id',
                'd.action_id',
                'd.vendor_id',
                'v.name as vendor_name',
                'd.game_id',
                'd.game_type_id',
                DB::raw('IFNULL(gtn.name, gt.name) as game_type_name'),
                'd.rate',
                'd.point',
                DB::raw('DATE_ADD(d.start_time, INTERVAL 8 HOUR) as start_time'),
                // 前台以 end_time 當派獎日期
                DB::raw('DATE_ADD(d.draw_time, INTERVAL 8 HOUR) as end_time'),
                DB::raw('0 as now_point'),
            ])
            ->orderByDesc('end_time')
            ->get();

        foreach ($payoutDetails as $payoutDetail) {
            $payoutDetail->show_exchange = 0;
            $payoutDetail->show_exchange_text = __('param.prize_button.can_not_exchange');
        }

        $row->pay_out_detail = $payoutDetails;
    }

    /**
     * 計算單一活動的按鈕狀態與附屬資料（直接寫回 $row）
     *
     * @return array{sort: int, is_redeem: bool}
     */
    private function buildLegacyEventRow(object $row, Member $member, Agent $agent, int $languageId): array
    {
        $memberId = $member->id;
        $now = now()->toDateTimeString();

        // 需儲值才可達成的活動，預設顯示「去儲值」
        if (in_array($row->action_id, [
            EventActionIdEnum::DEPOSIT->value,
            EventActionIdEnum::FIRST_DEPOSIT->value,
            EventActionIdEnum::FIRST_DEPOSIT_AUTO->value,
            EventActionIdEnum::DEPOSIT_AUTO->value,
            EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value,
            EventActionIdEnum::DEPOSIT_MANUAL->value,
            EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
            EventActionIdEnum::PICK_UP_ONCE_A_DAY_AUTO->value,
            EventActionIdEnum::DEPOSIT_CYCLE_TIME->value,
            EventActionIdEnum::DAILY_CONSECUTIVE_DEPOSIT->value,
            EventActionIdEnum::PERIODIC_CLAIM->value,
        ])) {
            $showExchange = 2;
            $sort = 2;
            $text = __('param.prize_button.deposit');
        } else {
            $showExchange = 0;
            $sort = 2;
            $text = __('param.prize_button.exchanged');
        }

        $prize = DB::table('prizes as p')
            ->leftJoin('prize_members as pm', function ($join) use ($memberId, $row) {
                $join->on('pm.prize_id', 'p.id')
                    ->when($row->action_id == EventActionIdEnum::BET_ONCE_A_DAY->value, function ($query) use ($row) {
                        $query->where('pm.event_detail_id', $row->event_detail_id);
                    })
                    ->where('pm.member_id', $memberId);
            })
            ->where('p.event_id', $row->id)
            ->where('p.status', 1)
            ->select([
                'p.id',
                'p.name',
                'p.expire_date',
                'p.type_id',
                'p.number',
                'p.max_point',
                'example.invalid',
                DB::raw('SUM((CASE WHEN pm.STATUS = 1 THEN 1 ELSE 0 END)) as exchanged'),
                DB::raw('SUM((CASE WHEN pm.STATUS = 0 AND (expired_time IS NULL OR expired_time >= NOW()) THEN 1 ELSE 0 END)) as exchange'),
                DB::raw('SUM((CASE WHEN pm.STATUS = 0 AND expired_time IS NOT NULL AND expired_time < NOW() THEN 1 ELSE 0 END)) as expired'),
                DB::raw('SUM((CASE WHEN pm.STATUS = 3 THEN 1 ELSE 0 END)) as cancel'),
                DB::raw('COUNT(*) as count'),
            ])
            ->first();

        $row->prize_name = $prize->name;
        $row->count = $prize->count;
        $info = json_decode((string) $prize->info);
        $achievePeople = $info->achieve_people ?? 0;
        $timesADay = $info->times_a_day ?? 1;

        // vw-master 獨有：流水限制的遊戲分類
        $flowLimitGameType = isset($info->flow_limit_game_type) ? (array) $info->flow_limit_game_type : 0;
        $row->flow_limit_game_type = (is_array($flowLimitGameType) && ($flowLimitGameType[0] ?? '') != '') ? $flowLimitGameType : 0;

        $dailyPrizeMember = null;

        // 每日領取一次（56 為 tpower 獨有）
        if (in_array($row->action_id, [EventActionIdEnum::PICK_UP_ONCE_A_DAY->value, EventActionIdEnum::PICK_UP_ONCE_A_DAY_AUTO->value])) {
            $dailyQuery = fn () => DB::table('prize_members')
                ->where('prize_id', $row->prize_id)
                ->where('member_id', $memberId)
                ->whereRaw('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))')
                ->where('status', 1);

            if ($prize->type_id == PrizeTypeEnum::MULTI_TIER_ACHIEVEMENT->value) {
                // 達標多次獎勵：次數以最多配發次數為準
                $row->reached_today = $dailyQuery()->count() > $prize->number ? 1 : 0;
            } else {
                $dailyPrizeMember = $dailyQuery()->first();
                if ($timesADay > 1) {
                    $row->reached_today = $dailyQuery()->count() >= $timesADay ? 1 : 0;
                } else {
                    $row->reached_today = $dailyPrizeMember ? 1 : 0;
                }
            }
        }

        // 登入送：沒有 prize_members 即視為未領獎
        if ($row->action_id == EventActionIdEnum::LOGIN->value) {
            $exists = DB::table('prize_members')
                ->where('prize_id', $prize->id)
                ->where('member_id', $memberId)
                ->exists();
            if (!$exists) {
                $prize->exchange = 1;
                $prize->exchanged = 0;
                $prize->is_repeatedly = 0; // 以防開錯這邊強制改
            }
        }

        // 下線充值獎金
        if ($row->action_id == EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value) {
            $canReceive = 0;
            $hasReceive = 0;
            $counts = DB::table('prize_members')
                ->where('prize_id', $prize->id)
                ->where('member_id', $memberId)
                ->select('status', DB::raw('COUNT(*) as cnt'))
                ->groupBy('status')
                ->get();

            foreach ($counts as $cnt) {
                if ($cnt->status == 0) {
                    $canReceive = $cnt->cnt;
                } else {
                    $hasReceive += $cnt->cnt;
                }
            }

            if (($canReceive + $hasReceive) >= $achievePeople) {
                $prize->exchange = $canReceive > 0 ? 1 : 0;
                $prize->exchanged = $canReceive > 0 ? 0 : 1;
            } else {
                $prize->exchange = 0;
                $prize->exchanged = 0;
            }
            $prize->is_repeatedly = 0; // 以防開錯這邊強制改

            $row->has_receive = $hasReceive;
            $row->can_receive = $canReceive;
        }

        // 每周救援金（tpower 獨有）
        if ($row->action_id == EventActionIdEnum::WEEKLY_RESCUE_FUND->value) {
            $prizeMember = DB::table('prize_members')
                ->where('prize_id', $row->prize_id)
                ->where('member_id', $memberId)
                ->where(function ($query) use ($now) {
                    $query->where('expired_time', '>=', $now)->orWhereNull('expired_time');
                })
                ->where('status', 0)
                ->first();

            $rewardPoint = 0;
            if ($prizeMember) {
                $prizeContent = PrizeContent::find($prizeMember->content_id);
                $calculated = Common::numMul((string) $prizeMember->lottery_point, (string) $prizeContent->point_percentage, 4);
                $capped = Common::numCompare($calculated, (string) $prize->max_point, 4) > 0
                    ? (string) $prize->max_point
                    : $calculated;
                $rewardPoint = $prizeContent->type == 1 ? $capped : $prizeContent->point;
            }
            $row->reward_point = number_format((float) $rewardPoint, 2);
        }

        // 每日連續儲值活動（tpower 獨有）
        if ($row->action_id == EventActionIdEnum::DAILY_CONSECUTIVE_DEPOSIT->value) {
            $this->applyConsecutiveDepositState($row, $prize, $memberId);
        }

        // 週期性領取
        if ($row->action_id == EventActionIdEnum::PERIODIC_CLAIM->value) {
            $prizeStatus = $this->checkPrizeStatus($memberId, $row, $info);
            $prize->exchange = $prizeStatus['exchange'];
            $prize->exchanged = $prizeStatus['exchanged'];
            $row->prize_is_repeatedly = $prizeStatus['prize_is_repeatedly'];
        }

        // 註冊送：列表顯示為可領取時同步檢查實名認證是否在期限內完成，逾期直接取消（避免列表仍顯示可領取）
        if ($row->action_id == EventActionIdEnum::REG_BONUS->value && $prize->exchange > 0 && $row->event_detail_expire_hour > 0) {
            $this->cancelExpiredRegBonusIfUnverified($member, $prize, (int) $row->event_detail_expire_hour);
        }

        if ($prize->expire_date < $now && $prize->expire_date != '') {
            $sort = 4;
            $showExchange = 0;
            $text = __('param.prize_button.expired');
        } elseif ($prize->exchange > 0) {
            $sort = 1;
            $showExchange = 1;
        } elseif ($prize->exchanged > 0 && $row->prize_is_repeatedly == 0) {
            $sort = 3;
            $showExchange = 0;
            $text = __('param.prize_button.exchanged');
        } elseif (!in_array($row->action_id, [EventActionIdEnum::DEPOSIT_AUTO->value, EventActionIdEnum::DEPOSIT_MANUAL->value]) && $prize->cancel > 0) {
            $sort = 5;
            $showExchange = 3;
            $text = __('param.prize_button.canceled');
            if ($row->action_id == EventActionIdEnum::PICK_UP_ONCE_A_DAY->value) {
                if (($row->reached_today ?? 0) == 0) {
                    $showExchange = 2;
                    $sort = 2;
                    $text = __('param.prize_button.deposit');
                } elseif ($row->reached_today == 1 && $dailyPrizeMember?->status == 1) {
                    $sort = 3;
                    $showExchange = 0;
                    $text = __('param.prize_button.exchanged');
                }
            }
        } elseif (in_array($row->action_id, [EventActionIdEnum::PICK_UP_ONCE_A_DAY->value, EventActionIdEnum::PICK_UP_ONCE_A_DAY_AUTO->value]) && ($row->reached_today ?? 0) == 1) {
            // 自動派彩：有 prize_members 即代表發過了
            $sort = 3;
            $showExchange = 0;
            $text = __('param.prize_button.exchanged');
        } elseif ($row->action_id == EventActionIdEnum::BET_ONCE_A_DAY->value && $prize->exchanged == 0) {
            // show_exchange != 1 && prize_is_repeatedly: 1 前台才會顯示未達成
            $row->prize_is_repeatedly = 1;
        } elseif (in_array($row->action_id, [EventActionIdEnum::DEPOSIT_AUTO->value, EventActionIdEnum::DEPOSIT_MANUAL->value]) && $prize->type_id == PrizeTypeEnum::MULTI_TIER_ACHIEVEMENT->value) {
            // issues_2375（tpower 獨有）
            $claimed = DB::table('prize_members')
                ->where('prize_id', $row->prize_id)
                ->where('member_id', $memberId)
                ->whereRaw('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))')
                ->where('status', 1)
                ->count();
            if ($claimed > $prize->number) {
                $sort = 3;
                $showExchange = 0;
                $text = __('param.prize_button.exchanged');
            }
        }

        $row->show_exchange = $showExchange;
        $row->show_exchange_text = $text;
        $row->remaining_number = $prize->exchange ? (int) $prize->exchange : 0;

        $sort = $this->attachLegacyEventDetails($row, $prize, $member, $agent, $languageId, $showExchange, $sort);

        if ($row->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
            $this->attachSlotRebatePayoutDetails($row, $prize, $memberId, $languageId);
        }

        if ($row->show_exchange == 2 && $this->checkRewardClaimRecord($member, (int) $row->event_detail_id)) {
            $row->show_exchange = 3;
            $row->show_exchange_text = __('param.prize_button.exchanged');
        }

        $row->prize_content = $this->getLegacyPrizeContent($row, $languageId);

        unset($row->event_detail_id, $row->event_detail_point, $row->event_detail_expire_hour);

        return ['sort' => $sort, 'is_redeem' => $showExchange == 1];
    }

    /**
     * 註冊送（action 11）：實名認證通過時間須不晚於 members.created_at + event_details.expire_hour，否則取消。
     *
     * 領取本身沒有時間限制，只看「還有沒有機會在期限內通過」：尚未通過但期限未到時不動（維持可領取顯示，
     * 屆時通過再判斷）；通過時間晚於期限，或期限已過仍未通過（已不可能再趕上）才取消
     * （prize_members.status 改 2）並讓本次列表不再顯示為可領取。
     */
    private function cancelExpiredRegBonusIfUnverified(Member $member, object $prize, int $expireHour): void
    {
        $deadline = Carbon::parse($member->created_at)->addHours($expireHour);
        $verifiedAt = RealnameValidationService::getEarliestVerifiedAt($member);

        if ($verifiedAt === null && now()->lessThanOrEqualTo($deadline)) {
            // 尚未通過實名認證，但期限未到，仍有機會通過，維持可領取顯示
            return;
        }

        if ($verifiedAt !== null && $verifiedAt->lessThanOrEqualTo($deadline)) {
            // 通過時間在期限內
            return;
        }

        PrizeMember::query()
            ->where('prize_id', $prize->id)
            ->where('member_id', $member->id)
            ->where('status', 0)
            ->update(['status' => 2]); // prize_members.status：2=取消（已不可能在期限內通過實名認證）

        $prize->exchange = 0;
    }

    /**
     * 每日連續儲值活動（action_id 59）的連續天數與已達標狀態
     */
    private function applyConsecutiveDepositState(object $row, object $prize, int $memberId): void
    {
        $row->consecutive_day = 0;
        $row->max_consecutive_day = 0;

        $order = DB::table('event_member_orders')
            ->where('event_id', $row->id)
            ->where('member_id', $memberId)
            ->first();

        if (!$order || is_null($order->last_deposit_time)) {
            return;
        }

        $row->consecutive_day = $order->consecutive_times;
        $row->max_consecutive_day = $order->max_consecutive_times;

        $prizeContent = PrizeContent::query()
            ->where('prize_id', $prize->id)
            ->where('level', $row->max_consecutive_day)
            ->where('status', 1)
            ->first();

        if (!$prizeContent) {
            return;
        }

        $maxLevel = PrizeContent::query()->where('prize_id', $prize->id)->where('status', 1)->max('level');

        $today = now()->toFrontendTime();
        $nextUpdateStart = Carbon::parse($order->last_deposit_time)->addHours(8)->startOfDay()->addDay();

        if ($today->gte($nextUpdateStart) && $order->consecutive_times > 0) {
            // 今天沒儲值過；昨天若未達累積金額可能降階，需調整進度
            if ($order->consecutive_times < $order->max_consecutive_times
                && Common::numCompare((string) $order->accumulated_amount, (string) $prizeContent->achieve_point, 4) < 0
            ) {
                $candidates = PrizeContent::query()
                    ->where('prize_id', $prize->id)
                    ->where('level', '<=', $order->max_consecutive_times)
                    ->where('status', 1)
                    ->orderByDesc('level')
                    ->get();

                // 先預設未達標，有達標則於迴圈補上
                $beforeConsecutiveDay = $row->consecutive_day;
                $row->consecutive_day = 0;
                $row->max_consecutive_day = 0;
                foreach ($candidates as $candidate) {
                    if (Common::numCompare((string) $order->accumulated_amount, (string) $candidate->achieve_point, 4) >= 0) {
                        $row->consecutive_day = $beforeConsecutiveDay + 1;
                        $row->max_consecutive_day = $candidate->level;
                        break;
                    }
                }
            }
        }

        $reachedToday = $order->is_first_day == 0
            && $today->lt($nextUpdateStart)
            && Common::numCompare((string) $order->accumulated_amount, (string) $prizeContent->achieve_point, 4) >= 0;

        if ($reachedToday || ($order->is_first_day == 1 && $order->max_consecutive_times == $maxLevel)) {
            $prize->exchanged = 1;
            $row->prize_is_repeatedly = 0;
        }
    }

    /**
     * 掛上活動達成條件明細（event_details），並回傳可能被下修的 sort
     */
    private function attachLegacyEventDetails(
        object $row,
        object $prize,
        Member $member,
        Agent $agent,
        int $languageId,
        int $showExchange,
        int $sort,
    ): int {
        $now = now()->toDateTimeString();

        $query = DB::table('event_details as d')
            ->leftJoin('event_processes as ep', function ($join) use ($member) {
                $join->on('ep.detail_id', 'd.id')->where('ep.member_id', $member->id);
            })
            ->leftJoin('order_type_names as otn', function ($join) use ($languageId) {
                $join->on('otn.type_id', 'd.order_type_id')->where('otn.language_id', $languageId);
            })
            ->leftJoin('game_types as gt', 'gt.id', 'd.game_type_id')
            ->leftJoin('game_type_names as gtn', function ($join) use ($languageId) {
                $join->on('gtn.game_type_id', 'd.game_type_id')->where('gtn.language_id', $languageId);
            });

        // type 6 為 W-Jackpot，其餘抽獎券走 lucky_draws
        $query = $row->type == PrizeTypeEnum::W_JACKPOT->value
            ? $query->leftJoin('w_jackpots as ld', 'ld.event_detail_id', 'd.id')
            : $query->leftJoin('lucky_draws as ld', 'ld.event_detail_id', 'd.id');

        $query = $query->leftJoin('vendors as v', 'v.id', 'd.vendor_id')
            ->where('d.event_id', $row->id)
            ->where('d.status', 1);

        $point = 'd.point';
        if ($row->type == PrizeTypeEnum::V_JACKPOT->value) {
            // v-jackpot
            $query->where('d.start_time', '<=', $now)->where('d.end_time', '>', $now);
            $point = DB::raw("CASE WHEN NOW() between '2020-11-10 16:00:00' and '2020-11-15 10:59:59' THEN 100 ELSE d.point END as point");
        } elseif ($row->type == PrizeTypeEnum::W_JACKPOT_6D->value || $row->action_id == EventActionIdEnum::BET_ONCE_A_DAY->value) {
            $query->where('d.start_time', '<=', $now)->where('d.end_time', '>', $now);
        } elseif ($row->type == PrizeTypeEnum::W_JACKPOT->value) {
            $query->where('d.start_time', '<=', $now)
                ->where('d.end_time', '>', now()->subHours(48)->toDateTimeString());
        }

        if ($row->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
            // 老虎機輸分回扣：找已開始的 detail 或派發有效期限內的遊戲
            $query->where(function ($sub) use ($now) {
                $sub->where('d.start_time', '<=', $now)
                    ->orWhere('d.draw_time', '>', DB::raw('DATE_SUB(NOW(), INTERVAL d.expire_hour HOUR)'));
            })->limit(1);
        }

        $details = $query->select([
            'd.id',
            'd.action_id',
            'd.order_type_id',
            'otn.name as order_type_name',
            'd.vendor_id',
            'v.name as vendor_name',
            'd.game_id',
            'd.game_type_id',
            DB::raw('IFNULL(gtn.name, gt.name) as game_type_name'),
            'd.rate',
            $point,
            DB::raw('DATE_ADD(d.start_time, INTERVAL 8 HOUR) as start_time'),
            DB::raw('DATE_ADD(d.end_time, INTERVAL 8 HOUR) as end_time'),
            DB::raw('(CASE WHEN ep.point is null THEN 0 ELSE (ep.point - ep.received) END) as now_point'),
            DB::raw("IFNULL(ld.no, '') as lucky_draw_no"),
            DB::raw('DATE_ADD(ld.draw_time, INTERVAL 8 HOUR) as prize_draw_date'),
        ])->get();

        if ($details->isEmpty()) {
            return $sort;
        }

        $startTime = '';
        $endTime = '';
        $frontendNow = now()->toFrontendTime();

        foreach ($details as $detail) {
            // 邀請玩家優惠：沒有邀請碼就補建一組
            if ($detail->action_id == EventActionIdEnum::INVITE_PLAYER_OFFERS->value) {
                $eventCode = EventCode::query()
                    ->where('event_id', $row->id)
                    ->where('member_id', $member->id)
                    ->first();

                if (!$eventCode) {
                    $eventCode = EventCode::create([
                        'event_id' => $row->id,
                        'member_id' => $member->id,
                        'code' => $this->eventCodeEncrypt((int) $row->id, $member->id),
                    ]);
                }
                $detail->code = $eventCode->code;
            }

            $detail->action_name = __('param.event_action.' . $detail->action_id);
            $detail->now_point = $detail->now_point > 0
                ? floor($detail->now_point - ($row->event_detail_point * $prize->expired))
                : 0;

            // 5X: 充值活動, 12:登入送, 16:下線充值獎金（58/14 為 tpower 獨有）
            $noScheduleActionId = [
                EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value,
                EventActionIdEnum::DEPOSIT_MANUAL->value,
                EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
                EventActionIdEnum::DEPOSIT_CYCLE_TIME->value,
                EventActionIdEnum::LOGIN->value,
                EventActionIdEnum::BET_ONCE_A_DAY->value,
                EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value,
            ];
            // 進度未達標時改為不可兌換
            if ($detail->now_point < $detail->point && $showExchange == 1 && !in_array($detail->action_id, $noScheduleActionId)) {
                $sort = 2;
                $row->show_exchange = 0;
            }

            if ($detail->lucky_draw_no != '') {
                if (Carbon::parse($detail->start_time)->lte($frontendNow) && $frontendNow->lte(Carbon::parse($detail->end_time))) {
                    $row->title .= ' - ' . str_replace('{N}', $detail->lucky_draw_no, __('param.periods'));
                    $row->prize_draw_date = $detail->prize_draw_date;
                    $startTime = $detail->start_time;
                    $endTime = $detail->end_time;
                }
            } elseif ($detail->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
                $startTime = $row->event_start_time;
                $endTime = $row->event_end_time;
            } else {
                if ($startTime == '' || $startTime > $detail->start_time) {
                    $startTime = $detail->start_time;
                }
                if ($endTime == '' || $endTime < $detail->end_time) {
                    $endTime = $detail->end_time;
                }
            }

            $detail->vendor_maintenances = $this->getVendorMaintenance($detail->vendor_id, $agent);

            if (in_array($detail->action_id, [EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value, EventActionIdEnum::DEPOSIT_MANUAL->value, EventActionIdEnum::PICK_UP_ONCE_A_DAY->value]) && $detail->now_point >= $detail->point) {
                break;
            }
        }

        $row->start_time = $startTime;
        $row->end_time = $endTime;
        $row->detail = $details;

        return $sort;
    }

    /**
     * 老虎機輸分回扣（action_id 9）的派彩明細
     */
    private function attachSlotRebatePayoutDetails(object $row, object $prize, int $memberId, int $languageId): void
    {
        $payoutDetails = DB::table('event_details as d')
            ->leftJoin('event_processes as ep', function ($join) use ($memberId) {
                $join->on('ep.detail_id', 'd.id')->where('ep.member_id', $memberId);
            })
            ->leftJoin('game_types as gt', 'gt.id', 'd.game_type_id')
            ->leftJoin('game_type_names as gtn', function ($join) use ($languageId) {
                $join->on('gtn.game_type_id', 'd.game_type_id')->where('gtn.language_id', $languageId);
            })
            ->leftJoin('vendors as v', 'v.id', 'd.vendor_id')
            ->where('d.event_id', $row->id)
            ->where('d.status', 1)
            ->where(function ($query) {
                $query->where('d.draw_time', '>', DB::raw('DATE_SUB(now(), INTERVAL expire_hour HOUR)'))
                    ->orWhere('expire_hour', 0);
            })
            ->where('d.draw_time', '<=', DB::raw('now()'))
            ->select([
                'd.id',
                'd.action_id',
                'd.vendor_id',
                'v.name as vendor_name',
                'd.game_id',
                'd.game_type_id',
                DB::raw('IFNULL(gtn.name, gt.name) as game_type_name'),
                'd.rate',
                'd.point',
                DB::raw('DATE_ADD(d.start_time, INTERVAL 8 HOUR) as start_time'),
                // 前台以 end_time 當派獎日期
                DB::raw('DATE_ADD(d.draw_time, INTERVAL 8 HOUR) as end_time'),
                DB::raw('(CASE WHEN ep.point is null THEN 0 ELSE (ep.point - ep.received) END) as now_point'),
            ])
            ->orderByDesc('end_time')
            ->get();

        foreach ($payoutDetails as $payoutDetail) {
            $showExchange = 0;
            $text = __('param.prize_button.can_not_exchange');

            $prizeMember = DB::table('prize_members')
                ->where('prize_id', $prize->id)
                ->where('member_id', $memberId)
                ->where('event_detail_id', $payoutDetail->id)
                ->first();

            if ($prizeMember) {
                if ($prizeMember->status == 0) {
                    $showExchange = 1;
                    $text = __('param.prize_button.exchange');
                } elseif ($prizeMember->status == 1) {
                    $text = __('param.prize_button.exchanged');
                }
            }

            $payoutDetail->show_exchange = $showExchange;
            $payoutDetail->show_exchange_text = $text;
        }

        $row->pay_out_detail = $payoutDetails;
    }

    /**
     * 活動獎項內容（含語系名稱）
     */
    private function getLegacyPrizeContent(object $row, int $languageId): Collection
    {
        $contents = PrizeContent::query()
            ->leftJoin('prize_content_names as pcn', function ($join) use ($languageId) {
                $join->on('pcn.prize_content_id', 'prize_contents.id')
                    ->where('pcn.language_id', $languageId);
            })
            ->where('prize_id', $row->prize_id)
            ->where('prize_contents.status', 1)
            ->select([
                'prize_contents.*',
                DB::raw('IFNULL(pcn.name, prize_contents.name) as name'),
            ])
            ->get();

        $contents->each(function ($item) use ($row) {
            unset($item->percentage);
            if ($row->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
                // 轉換成 %
                $item->point_percentage = $item->point_percentage * 100;
            }
        });

        return $contents;
    }

    /**
     * 遊戲商是否維護中（1: 維護中, 0: 正常）
     *
     * 遷移自 API_Frontend EventController::getVendorMaintenance
     */
    public function getVendorMaintenance(?int $vendorId, Agent $agent): int
    {
        if (!$vendorId) {
            return 0;
        }

        $agentIds = $agent->hierarchy_array;
        $now = now()->toDateTimeString();

        $exists = DB::table('vendor_maintenances')
            ->where('vendor_id', $vendorId)
            ->where('status', 1)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>=', $now)
            ->whereIn('agent_id', $agentIds)
            ->whereNotIn('exclude_agent_id', $agentIds)
            ->exists();

        return $exists ? 1 : 0;
    }

    /**
     * 檢查是否已有他人以相同identity（真實姓名 / IP / 手機 / TG）領過同一活動獎勵
     *
     * 遷移自 API_Frontend EventController::checkRewardClaimRecord
     */
    public function checkRewardClaimRecord(Member $member, int $eventDetailId): bool
    {
        $eventDetail = EventDetail::find($eventDetailId);
        if (!$eventDetail) {
            return false;
        }

        // 55 / 56 為每日型活動，僅比對當日（UTC+8）紀錄
        $isDaily = in_array($eventDetail->action_id, [EventActionIdEnum::PICK_UP_ONCE_A_DAY->value, EventActionIdEnum::PICK_UP_ONCE_A_DAY_AUTO->value]);

        $checks = [];
        if ($eventDetail->account_block == 1) {
            $checks[] = [1, $member->first_name];
        }
        if ($eventDetail->ip_block == 1) {
            $checks[] = [2, $member->register_ip];
            $loginIp = explode(', ', Common::getIp());
            $checks[] = [4, $loginIp[0]];
        }
        if ($eventDetail->phone_block == 1) {
            $checks[] = [3, $member->mobile];
        }
        if ($eventDetail->tgid_block == 1) {
            $rel = DB::table('member_telegram_rels')->where('member_id', $member->id)->first();
            if ($rel) {
                $checks[] = [5, $rel->tg_user_id];
            }
        }

        foreach ($checks as [$type, $value]) {
            $exists = DB::table('reward_claim_records')
                ->where('event_detail_id', $eventDetail->id)
                ->where('member_id', '!=', $member->id)
                ->where('type', $type)
                ->where('value', $value)
                ->when($isDaily, function ($query) {
                    $query->whereRaw('DATE(DATE_ADD(reward_claim_records.created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))');
                })
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * action_id 61（週期性領取）的獎勵狀態
     *
     * 遷移自 API_Frontend EventService::checkPrizeStatus
     *
     * @return array{exchange: int, exchanged: int, prize_is_repeatedly: int}
     */
    public function checkPrizeStatus(int $memberId, object $event, ?object $prizeInfo): array
    {
        $status = [
            'exchange' => 0,
            'exchanged' => 0,
            'prize_is_repeatedly' => 0,
        ];

        // 週期 0: 限定一次, 1: 每日, 2: 每週, 3: 每月
        $period = (int) ($prizeInfo->period ?? 0);
        $status['prize_is_repeatedly'] = $period === 0 ? 0 : (int) $event->prize_is_repeatedly;

        if ($period > 0) {
            [$startTime, $endTime] = $this->getPeriodRange($period);
        } else {
            // 只能領一次：直接用活動起訖（欄位為 UTC+8，需轉回系統時間）
            $startTime = Carbon::parse($event->event_start_time)->subHours(8)->toDateTimeString();
            $endTime = Carbon::parse($event->event_end_time)->subHours(8)->toDateTimeString();
        }

        $prizeMember = PrizeMember::query()
            ->where('prize_id', $event->prize_id)
            ->where('event_detail_id', $event->event_detail_id)
            ->where('member_id', $memberId)
            ->where('created_at', '>=', $startTime)
            ->where('created_at', '<=', $endTime)
            ->first();

        if ($prizeMember) {
            if (in_array($prizeMember->status, [1, 2])) {
                $status['exchanged'] = 1;
                $status['prize_is_repeatedly'] = 0;
            } else {
                $status['exchange'] = 1;
            }
        }

        return $status;
    }

    /**
     * 週期時間範圍（以 UTC+8 為界，回傳系統時間）
     *
     * @return array{0: string, 1: string}
     */
    private function getPeriodRange(int $period): array
    {
        $now = now()->toFrontendTime();

        $range = match ($period) {
            1 => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            2 => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
            3 => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
        };

        return [
            $range[0]->toSystemTime()->toDateTimeString(),
            $range[1]->toSystemTime()->toDateTimeString(),
        ];
    }

    /**
     * event - 舊版活動詳情
     *
     * 遷移自 API_Frontend EventController::view（tpower-master / vw-master 完全相同）。
     *
     * @return list<object> 舊版固定回傳陣列，查無活動時為空陣列
     */
    public function getLegacyEventDetail(Member $member, Agent $agent, int $eventId, int $languageId): array
    {
        $now = now()->toDateTimeString();

        $event = Event::query()
            ->join('event_details as ed', function ($join) {
                $join->on('ed.event_id', 'events.id')
                    ->where('ed.status', 1)
                    ->whereNotIn('ed.action_id', [EventActionIdEnum::SLOT_MACHINE_COMPETITION->value]);
            })
            ->leftJoin('event_names as en', function ($join) use ($languageId) {
                $join->on('en.event_id', 'events.id')->where('en.language_id', $languageId);
            })
            ->join('prizes as p', function ($join) use ($now) {
                $join->on('p.event_id', 'events.id')
                    ->where('p.status', 1)
                    ->where('p.expire_date', '>', $now);
            })
            ->leftJoin('prize_members as pm', function ($join) use ($member) {
                $join->on('pm.prize_id', 'p.id')
                    ->where('pm.status', 0)
                    ->where('pm.member_id', $member->id);
            })
            ->where('events.id', $eventId)
            ->where('events.status', 1)
            // 舊版未限制品牌，任一會員可讀他牌活動；此處補上與列表一致的代理層級限制
            ->whereIn('events.agent_id', $agent->hierarchy_array)
            ->whereRaw('events.start_time <= NOW()')
            ->groupBy('events.id')
            ->orderByDesc('events.created_at')
            ->select([
                'events.id',
                'events.category',
                'ed.id as event_detail_id',
                'ed.point as event_detail_point',
                DB::raw('IFNULL(en.title, events.title) as title'),
                DB::raw('IFNULL(en.content, events.content) as content'),
                'events.type_id',
                'events.agent_id',
                'p.id as prize_id',
                'p.number as prize_number',
                'p.is_repeatedly as prize_is_repeatedly',
                DB::raw('DATE_ADD(p.expire_date, INTERVAL 8 HOUR) as prize_expire_date'),
                DB::raw('DATE_ADD(p.expire_date, INTERVAL 8 HOUR) as expire_date'),
                DB::raw('DATE_ADD(p.draw_date, INTERVAL 8 HOUR) as prize_draw_date'),
                DB::raw('DATE_ADD(events.start_time, INTERVAL 8 HOUR) as event_start_time'),
                DB::raw('DATE_ADD(events.end_time, INTERVAL 8 HOUR) as event_end_time'),
                'p.type_id as type',
                'ed.action_id as action_id',
            ])
            ->first();

        if (!$event) {
            return [];
        }

        // 需儲值才可達成的活動，預設顯示「去儲值」
        if (in_array($event->action_id, [
            EventActionIdEnum::DEPOSIT->value,
            EventActionIdEnum::FIRST_DEPOSIT->value,
            EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value,
            EventActionIdEnum::DEPOSIT_MANUAL->value,
        ])) {
            $showExchange = 2;
            $text = __('param.prize_button.deposit');
        } else {
            $showExchange = 0;
            $text = __('param.prize_button.exchanged');
        }

        $prize = DB::table('prizes as p')
            ->leftJoin('prize_members as pm', function ($join) use ($member) {
                $join->on('pm.prize_id', 'p.id')->where('pm.member_id', $member->id);
            })
            ->where('p.event_id', $event->id)
            ->where('p.status', 1)
            ->where('pm.member_id', $member->id)
            ->select([
                'p.id',
                'p.expire_date',
                DB::raw('SUM((CASE WHEN pm.STATUS = 1 THEN 1 ELSE 0 END)) as exchanged'),
                DB::raw('SUM((CASE WHEN pm.STATUS = 0 AND (expired_time IS NULL OR expired_time >= NOW()) THEN 1 ELSE 0 END)) as exchange'),
                DB::raw('SUM((CASE WHEN pm.STATUS = 0 AND expired_time IS NOT NULL AND expired_time < NOW() THEN 1 ELSE 0 END)) as expired'),
                DB::raw('SUM((CASE WHEN pm.STATUS = 3 THEN 1 ELSE 0 END)) as cancel'),
                DB::raw('COUNT(*) as count'),
            ])
            ->first();

        $event->count = $prize->count;
        $dailyPrizeMember = null;

        // 每日領取一次
        if ($event->action_id == EventActionIdEnum::PICK_UP_ONCE_A_DAY->value) {
            $dailyPrizeMember = DB::table('prize_members')
                ->where('prize_id', $event->prize_id)
                ->where('member_id', $member->id)
                ->whereRaw('DATE(DATE_ADD(created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))')
                ->first();
            $event->reached_today = $dailyPrizeMember ? 1 : 0;
        }

        // 登入送：沒有 prize_members 即視為未領獎
        if ($event->action_id == EventActionIdEnum::LOGIN->value) {
            $exists = DB::table('prize_members')
                ->where('prize_id', $prize->id)
                ->where('member_id', $member->id)
                ->exists();
            if (!$exists) {
                $prize->exchange = 1;
                $prize->exchanged = 0;
                $prize->is_repeatedly = 0; // 以防開錯這邊強制改
            }
        }

        if ($prize->expire_date < $now && $prize->expire_date != '') {
            // 已過期
            $showExchange = 0;
            $text = __('param.prize_button.expired');
        } elseif ($prize->exchange > 0) {
            // 可兌獎
            $showExchange = 1;
        } elseif ($prize->exchanged > 0) {
            // 全部已兌獎
            $showExchange = 0;
            $text = __('param.prize_button.exchanged');
        } elseif ($event->action_id != EventActionIdEnum::DEPOSIT_AUTO->value
            && $event->action_id != EventActionIdEnum::DEPOSIT_MANUAL->value
            && $prize->cancel > 0
        ) {
            // 已被取消
            $showExchange = 3;
            $text = __('param.prize_button.canceled');
            if ($event->action_id == EventActionIdEnum::PICK_UP_ONCE_A_DAY->value) {
                if ($event->reached_today == 0) {
                    $showExchange = 2;
                    $text = __('param.prize_button.deposit');
                } elseif ($event->reached_today == 1 && $dailyPrizeMember->status == 1) {
                    $showExchange = 0;
                    $text = __('param.prize_button.exchanged');
                }
            }
        }

        $event->show_exchange = $showExchange;
        $event->show_exchange_text = $text;
        $event->remaining_number = $prize->exchange ? (int) $prize->exchange : 0;

        $this->attachLegacyEventDetailWithGames($event, $prize, $member, $agent, $languageId, $showExchange);

        if ($event->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
            $this->attachSlotRebatePayoutDetails($event, $prize, $member->id, $languageId);
        }

        $contents = DB::table('prize_contents as pc')
            ->leftJoin('prize_content_names as pcn', function ($join) use ($languageId) {
                $join->on('pcn.prize_content_id', 'pc.id')->where('pcn.language_id', $languageId);
            })
            ->where('prize_id', $event->prize_id)
            ->where('pc.status', 1)
            ->orderBy('pc.id')
            ->select([
                'pc.id',
                DB::raw('IFNULL(pcn.name, pc.name) as name'),
                'pc.type',
                'pc.point',
                'pc.point_percentage',
                'pc.number',
            ])
            ->get();

        if ($contents->isNotEmpty()) {
            if ($event->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
                // 轉換成 %
                $contents->each(fn ($item) => $item->point_percentage = $item->point_percentage * 100);
                $event->prize_content = $contents;
            } else {
                // Event 有唯讀的 prize relation，改以 attribute 覆寫（回應欄位名沿用舊版）
                $event->setAttribute('prize', $contents);
            }
        }

        return [$event];
    }

    /**
     * 確認活動屬於該代理層級；不屬於直接拋 1901（防跨白標／跨代理以 event_id 越權）
     *
     * 只比對代理層級、不查 event_exclude_agents：被排除的代理仍屬同白標，越權風險已由層級擋下，
     * 而代理事後才被排除時，玩家手上既有的待領獎勵不應因此領不到。
     */
    public function assertEventAccessible(int $eventId, Agent $agent): void
    {
        $accessible = Event::query()
            ->where('id', $eventId)
            ->whereIn('agent_id', $agent->hierarchy_array)
            ->exists();

        if (!$accessible) {
            throw new ApiException(__('error_message.1901'));
        }
    }

    /**
     * event/validate - 舊版活動資格驗證並預先建立待領獎勵
     *
     * 遷移自 API_Frontend EventV1Controller::validateEvent（tpower-master 獨有，vw-master 無此 controller / 路由）。
     * 目前僅支援 action 60（tpower 邀請玩家活動）。
     */
    public function validateLegacyEvent(Member $member, Agent $agent, int $eventId): void
    {
        $agentIds = $agent->hierarchy_array;

        $event = Event::query()
            ->leftJoin('event_details', 'events.id', 'event_details.event_id')
            ->leftJoin('prizes', 'events.id', 'prizes.event_id')
            ->leftJoin('prize_contents', 'prizes.id', 'prize_contents.prize_id')
            ->leftJoin('event_exclude_agents as eea', function ($join) use ($agentIds) {
                $join->on('eea.event_id', 'events.id')->whereIn('eea.agent_id', $agentIds);
            })
            ->where('events.id', $eventId)
            ->whereIn('events.agent_id', $agentIds)
            ->whereNull('eea.id')
            ->where('events.status', 1)
            ->select([
                'events.id',
                'event_details.id as event_detail_id',
                'event_details.action_id as action_id',
                'prizes.id as prizes_id',
                'prize_contents.id as prize_contents_id',
            ])
            ->first();

        // 舊版此處寫成 new \Exception() 未 throw，查無活動時會在下一行讀 null 屬性致命
        if (!$event) {
            throw new ApiException(__('error_message.1901'));
        }

        match ((int) $event->action_id) {
            EventActionIdEnum::TPOWER_INVITE_PLAYER->value => $this->getInvitedMembers($member, $eventId),
            default => throw new ApiException(__('error_message.1901')),
        };

        // 舊版無交易與鎖，併發下會重複派發待領獎勵
        DB::transaction(function () use ($member, $event) {
            $exists = PrizeMember::query()
                ->where('member_id', $member->id)
                ->where('event_detail_id', $event->event_detail_id)
                ->where('prize_id', $event->prizes_id)
                ->where('content_id', $event->prize_contents_id)
                ->where('status', 0)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return;
            }

            PrizeMember::create([
                'member_id' => $member->id,
                'event_detail_id' => $event->event_detail_id,
                'prize_id' => $event->prizes_id,
                'content_id' => $event->prize_contents_id,
                'status' => 0,
                'admin' => 0,
            ]);
        });
    }

    /**
     * 取得達標的被邀請會員（action 60）
     *
     * 遷移自 API_Frontend EventV1Service::getInvitedMembers；未達 achieve_people 直接拋錯。
     * 領獎時需以回傳的 id 回寫 event_invite_records.prize_member_id（見舊版 PrizeController）。
     *
     * @return Collection<int, mixed>
     */
    public function getInvitedMembers(Member $member, int $eventId): Collection
    {
        $prize = Prize::query()
            ->leftJoin('prize_contents', 'prizes.id', 'prize_contents.prize_id')
            ->where('prizes.event_id', $eventId)
            ->where('prize_contents.status', 1)
            ->select(['prizes.id', 'example.invalid'])
            ->first();

        $eventDetail = EventDetail::where('event_id', $eventId)->first();

        // 舊版未檢查，缺獎項或明細時會讀 null 屬性致命
        if (!$prize || !$eventDetail) {
            throw new ApiException(__('error_message.1901'));
        }

        $prizeInfo = json_decode((string) $prize->info, true) ?: [];
        $achievePeople = (int) ($prizeInfo['achieve_people'] ?? 0);

        // 舊版未檢查，達標人數為 0 時 limit(0) 取不到資料卻仍判定達標，等同無條件發獎
        if ($achievePeople <= 0) {
            throw new ApiException(__('error_message.1901'));
        }

        if ($eventDetail->global_ip_block == 1 || $eventDetail->global_phone_block == 1 || $eventDetail->global_account_block == 1) {
            $blocked = PrizeMember::query()
                ->leftJoin('members', 'prize_members.member_id', 'members.id')
                ->where('prize_members.event_detail_id', $eventDetail->id)
                ->where('prize_members.member_id', '!=', $member->id)
                ->where(function ($query) use ($eventDetail, $member) {
                    if ($eventDetail->global_ip_block == 1) {
                        $query->orWhere('prize_members.ip', Common::getIp());
                    }
                    if ($eventDetail->global_phone_block == 1) {
                        $query->orWhere('members.mobile', $member->mobile);
                    }
                    if ($eventDetail->global_account_block == 1) {
                        $query->orWhere('members.first_name', $member->first_name);
                    }
                })
                ->exists();

            if ($blocked) {
                throw new ApiException(__('error_message.1937'));
            }
        }

        // 舊版未以 event_id 過濾，會把該會員在其他活動累積的邀請紀錄一併計入本活動達標人數
        $invitedMembers = EventInviteRecord::query()
            ->leftJoin('events', 'event_invite_records.event_id', 'events.id')
            ->leftJoin('members', 'event_invite_records.member_id', 'members.id')
            ->where('event_invite_records.event_id', $eventId)
            ->where('event_invite_records.invite_member_id', $member->id)
            ->when(isset($prizeInfo['first_deposit_days']), function ($query) use ($prizeInfo) {
                $query->join('orders', function ($join) use ($prizeInfo) {
                    $join->on('members.id', 'orders.member_id')
                        ->whereRaw('orders.created_at <= DATE_ADD(members.created_at, INTERVAL ? DAY)', [(int) $prizeInfo['first_deposit_days']])
                        // 首儲金額條件
                        ->when(isset($prizeInfo['first_deposit_amount']), function ($join) use ($prizeInfo) {
                            $join->where('orders.amount', '>=', $prizeInfo['first_deposit_amount']);
                        })
                        ->where('orders.status', 3);
                });
            })
            // 過濾掉重複 IP
            ->when($eventDetail->ip_block == 1, fn ($query) => $query->where('event_invite_records.ip_duplicate', 0))
            // 過濾掉重複手機
            ->when($eventDetail->phone_block == 1, fn ($query) => $query->where('event_invite_records.phone_duplicate', 0))
            // 過濾掉重複姓名
            ->when($eventDetail->account_block == 1, fn ($query) => $query->where('event_invite_records.realname_duplicate', 0))
            ->where('members.realname_validate', 1) // 真實姓名已審核
            ->where('members.mobile_validate', 1) // 手機號已審核
            ->where('members.status', 1)
            ->whereNull('event_invite_records.prize_member_id')
            ->orderBy('event_invite_records.created_at')
            ->limit($achievePeople)
            ->select(['event_invite_records.id', 'event_invite_records.invite_member_id'])
            ->get();

        if ($invitedMembers->count() < $achievePeople) {
            // 邀請人數不足
            throw new ApiException(__('error_message.1990', ['invite' => $invitedMembers->count()]));
        }

        return $invitedMembers;
    }

    /**
     * 領獎後將本次使用掉的邀請紀錄綁定到 prize_members
     *
     * 遷移自 API_Frontend EventV1Service::updateInviteRecord
     *
     * @param  array<int, int>  $ids
     */
    public function updateInviteRecord(array $ids, int $prizeMemberId): void
    {
        if (empty($ids)) {
            return;
        }

        EventInviteRecord::whereIn('id', $ids)->update(['prize_member_id' => $prizeMemberId]);
    }

    /**
     * 活動詳情的明細列表（含參與活動的遊戲列表）
     *
     * 與列表用的 attachLegacyEventDetails 共用同一段舊版查詢，差別在詳情頁另外帶出 games。
     */
    private function attachLegacyEventDetailWithGames(
        object $event,
        object $prize,
        Member $member,
        Agent $agent,
        int $languageId,
        int $showExchange,
    ): void {
        $now = now()->toDateTimeString();

        $query = DB::table('event_details as d')
            ->leftJoin('event_processes as ep', function ($join) use ($member) {
                $join->on('ep.detail_id', 'd.id')->where('ep.member_id', $member->id);
            })
            ->leftJoin('order_type_names as otn', function ($join) use ($languageId) {
                $join->on('otn.type_id', 'd.order_type_id')->where('otn.language_id', $languageId);
            })
            ->leftJoin('game_types as gt', 'gt.id', 'd.game_type_id')
            ->leftJoin('game_type_names as gtn', function ($join) use ($languageId) {
                $join->on('gtn.game_type_id', 'd.game_type_id')->where('gtn.language_id', $languageId);
            });

        // type 6 為 W-Jackpot，其餘抽獎券走 lucky_draws
        $query = $event->type == PrizeTypeEnum::W_JACKPOT->value
            ? $query->leftJoin('w_jackpots as ld', 'ld.event_detail_id', 'd.id')
            : $query->leftJoin('lucky_draws as ld', 'ld.event_detail_id', 'd.id');

        $query = $query->leftJoin('vendors as v', 'v.id', 'd.vendor_id')
            ->where('d.event_id', $event->id)
            ->where('d.status', 1);

        $point = 'd.point';
        if ($event->type == PrizeTypeEnum::V_JACKPOT->value) {
            // v-jackpot
            $query->where('d.start_time', '<=', $now)->where('d.end_time', '>', $now);
            $point = DB::raw("CASE WHEN NOW() between '2020-11-10 16:00:00' and '2020-11-15 10:59:59' THEN 100 ELSE d.point END as point");
        } elseif ($event->type == PrizeTypeEnum::W_JACKPOT_6D->value) {
            $query->where('d.start_time', '<=', $now)->where('d.end_time', '>', $now);
        } elseif ($event->type == PrizeTypeEnum::W_JACKPOT->value) {
            $query->where('d.start_time', '<=', $now)
                ->where('d.end_time', '>', now()->subHours(48)->toDateTimeString());
        }

        if ($event->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
            // 老虎機輸分回扣：找已開始的 detail 或派發有效期限內的遊戲
            $query->where(function ($sub) use ($now) {
                $sub->where('d.start_time', '<=', $now)
                    ->orWhere('d.draw_time', '>', DB::raw('DATE_SUB(NOW(), INTERVAL d.expire_hour HOUR)'));
            })->limit(1);
        }

        $details = $query->select([
            'd.id',
            'd.action_id',
            'd.order_type_id',
            'otn.name as order_type_name',
            'd.vendor_id',
            'v.name as vendor_name',
            'd.game_id',
            'd.game_type_id',
            DB::raw('IFNULL(gtn.name, gt.name) as game_type_name'),
            'd.rate',
            $point,
            DB::raw('DATE_ADD(d.start_time, INTERVAL 8 HOUR) as start_time'),
            DB::raw('DATE_ADD(d.end_time, INTERVAL 8 HOUR) as end_time'),
            DB::raw('(CASE WHEN ep.point is null THEN 0 ELSE (ep.point - ep.received) END) as now_point'),
            DB::raw("IFNULL(ld.no, '') as lucky_draw_no"),
            DB::raw('DATE_ADD(ld.draw_time, INTERVAL 8 HOUR) as prize_draw_date'),
        ])->get();

        if ($details->isEmpty()) {
            return;
        }

        $startTime = '';
        $endTime = '';
        $frontendNow = now()->toFrontendTime();

        foreach ($details as $detail) {
            // 邀請玩家優惠：沒有邀請碼就補建一組
            if ($detail->action_id == EventActionIdEnum::INVITE_PLAYER_OFFERS->value) {
                $eventCode = EventCode::query()
                    ->where('event_id', $event->id)
                    ->where('member_id', $member->id)
                    ->first();

                if (!$eventCode) {
                    $eventCode = EventCode::create([
                        'event_id' => $event->id,
                        'member_id' => $member->id,
                        'code' => $this->eventCodeEncrypt((int) $event->id, $member->id),
                    ]);
                }
                $detail->code = $eventCode->code;
            }

            $detail->action_name = __('param.event_action.' . $detail->action_id);
            $detail->now_point = $detail->now_point > 0
                ? floor($detail->now_point - ($event->event_detail_point * $prize->expired))
                : 0;

            // 5X: 充值活動, 12: 登入送
            $noScheduleActionId = [
                EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value,
                EventActionIdEnum::DEPOSIT_MANUAL->value,
                EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
                EventActionIdEnum::LOGIN->value,
            ];
            // 進度未達標時改為不可兌換
            if ($detail->now_point < $detail->point && $showExchange == 1 && !in_array($detail->action_id, $noScheduleActionId)) {
                $event->show_exchange = 0;
            }

            if ($detail->lucky_draw_no != '') {
                if (Carbon::parse($detail->start_time)->lte($frontendNow) && $frontendNow->lte(Carbon::parse($detail->end_time))) {
                    $event->title .= ' - ' . str_replace('{N}', $detail->lucky_draw_no, __('param.periods'));
                    $event->prize_draw_date = $detail->prize_draw_date;
                    $startTime = $detail->start_time;
                    $endTime = $detail->end_time;
                }
            } elseif ($detail->action_id == EventActionIdEnum::SLOT_LOSE_REBATE->value) {
                $startTime = $event->event_start_time;
                $endTime = $event->event_end_time;
            } else {
                if ($startTime == '' || $startTime > $detail->start_time) {
                    $startTime = $detail->start_time;
                }
                if ($endTime == '' || $endTime < $detail->end_time) {
                    $endTime = $detail->end_time;
                }
            }

            // 參與活動的遊戲列表
            $games = $this->getLegacyEventDetailGames((int) $detail->id, $member, $agent, $languageId);
            if ($games !== null) {
                $detail->games = $games;
            }

            $detail->vendor_maintenances = $this->getVendorMaintenance($detail->vendor_id, $agent);

            if (in_array($detail->action_id, [
                EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value,
                EventActionIdEnum::DEPOSIT_MANUAL->value,
                EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
            ]) && $detail->now_point >= $detail->point) {
                break;
            }
        }

        $event->start_time = $startTime;
        $event->end_time = $endTime;
        $event->detail = $details;
    }

    /**
     * 活動明細可參與的遊戲列表
     *
     * 遷移自 API_Frontend EventController::getGameByDetailId；
     * 舊版的 vendorList / agentGameList 改用 apis 既有的 VendorService / AgentGameService。
     * 不適用（無綁定遊戲商、該遊戲商不在會員可玩清單）時回傳 null，維持舊版不帶 games 欄位的行為。
     *
     * @return Collection<int, mixed>|null
     */
    private function getLegacyEventDetailGames(int $eventDetailId, Member $member, Agent $agent, int $languageId): ?Collection
    {
        $eventDetail = EventDetail::query()
            ->where('id', $eventDetailId)
            ->where('vendor_id', '>', 0)
            ->where('status', 1)
            ->first();

        if (!$eventDetail) {
            return null;
        }

        $vendorService = new VendorService;
        $vendorId = (int) $eventDetail->vendor_id;

        if (!in_array($vendorId, $vendorService->getVendorIds($agent))) {
            return null;
        }

        $vendorInfoIds = $vendorService->getVendorInfoIds($agent, $vendorId);
        $gameIds = (new AgentGameService)->getGameIdList($agent, [$vendorId]);

        return DB::table('games as g')
            ->join('vendor_info_games as vig', function ($join) use ($eventDetail, $vendorInfoIds) {
                $join->on('vig.game_id', 'g.id')
                    ->where('vig.status', 1)
                    ->whereIn('vig.vendor_info_id', $vendorInfoIds);
                // 判斷遊戲是否可參加此類型活動，2: 投注、3: 贏分；
                // 其餘 action_id 舊版原碼在此 return response()->json()（closure 內為死碼），等同不加條件
                if ($eventDetail->action_id == EventActionIdEnum::BET->value) {
                    $join->where('vig.join_bet_event', 1);
                } elseif ($eventDetail->action_id == EventActionIdEnum::WIN->value) {
                    $join->where('vig.join_win_event', 1);
                }
            })
            ->leftJoin('game_names as gn', function ($join) use ($languageId) {
                $join->on('gn.game_id', 'g.id')->where('gn.language_id', $languageId);
            })
            ->join('vendors as v', 'v.id', 'g.vendor_id')
            ->leftJoin('favourite_games as f', function ($join) use ($member) {
                $join->on('f.game_id', 'g.id')
                    ->where('f.member_id', $member->id)
                    ->where('f.status', 1);
            })
            ->leftJoin('game_types as t', 't.id', 'g.type_id')
            ->leftJoin('game_type_names as tn', function ($join) use ($languageId) {
                $join->on('t.id', 'tn.game_type_id')->where('tn.language_id', $languageId);
            })
            ->when($eventDetail->game_type_id > 0, fn ($query) => $query->where('g.type_id', $eventDetail->game_type_id))
            ->whereIn('g.id', $gameIds)
            ->select([
                'g.id',
                'g.vendor_id',
                'v.name as vendor_name',
                'g.type_id',
                DB::raw('IFNULL(tn.name, t.name) AS type_name'),
                DB::raw('IFNULL(gn.name, g.name) as name'),
                'g.lottery_pool',
                'g.image',
                'vig.is_new',
                'vig.is_hot',
                DB::raw('(CASE WHEN f.status is NULL THEN 0 ELSE 1 END) as is_favourite'),
            ])
            ->get();
    }

    /**
     * event/vendorGames - 依遊戲商列出目前進行中、可參與的活動與遊戲
     *
     * 遷移自 API_Frontend EventController::gamesByVendor（兩分支逐行完全相同）；
     * 共用 getLegacyEventDetailGames()（與序號 22 活動詳情共用同一支舊版 getGameByDetailId）。
     *
     * @return Collection<int, mixed>
     */
    public function getEventsByVendor(Member $member, Agent $agent, int $vendorId, int $languageId): Collection
    {
        $vendorService = new VendorService;

        if (!in_array($vendorId, $vendorService->getVendorIds($agent))) {
            // 沒有此遊戲商的權限
            throw new ApiException(__('error_message.1426'));
        }

        // 逐筆活動查一次遊戲清單（N+1）是舊版行為，舊版以 Redis 快取攤提（key 同樣帶
        // member/language/vendor，TTL 3600），此處沿用相同 key 組成與 TTL
        return Cache::safeRemember(
            CacheKeys::eventGamesByVendor($member->id, $languageId, $vendorId),
            CacheTtl::ONE_HOUR->value,
            function () use ($member, $agent, $vendorId, $languageId) {
                $now = now()->toDateTimeString();
                $agentIds = $agent->hierarchy_array;

                $events = DB::table('events as e')
                    ->join('event_details as d', function ($join) use ($vendorId, $now) {
                        $join->on('d.event_id', 'e.id')
                            ->where('d.status', 1)
                            ->where('d.start_time', '<', $now)
                            ->where('d.end_time', '>', $now)
                            ->where('d.vendor_id', $vendorId);
                    })
                    ->leftJoin('event_names as n', function ($join) use ($languageId) {
                        $join->on('n.event_id', 'e.id')->where('n.language_id', $languageId);
                    })
                    ->where(function ($query) use ($agentIds) {
                        // 只能取自己營運商、營運商上線及平台的活動
                        $query->whereIn('e.agent_id', $agentIds)->orWhere('e.agent_id', 0)->orWhereNull('e.agent_id');
                    })
                    ->where('e.status', 1)
                    ->select([
                        'e.id',
                        DB::raw('(CASE WHEN n.title is NULL THEN e.title ELSE n.title END) as title'),
                        'd.id as detail_id',
                    ])
                    ->get();

                foreach ($events as $event) {
                    $games = $this->getLegacyEventDetailGames((int) $event->detail_id, $member, $agent, $languageId);
                    if ($games !== null) {
                        $event->games = $games;
                    }
                }

                return $events;
            },
        );
    }

    /**
     * 產生活動邀請碼（IE + 活動ID(4碼) + 玩家ID(7碼) 以 32 進位編碼）
     *
     * 遷移自 API_Frontend Controller::eventCodeEncrypt
     */
    public function eventCodeEncrypt(int $eventId, int $memberId): string
    {
        $str = substr('0000' . $eventId, -4) . substr('0000000' . $memberId, -7);
        // 補齊 8 碼；因為 99999999999 加密為 2T47DPVV, 10000001 加密為 9H5K1
        $param = substr('0000' . strtoupper(base_convert($str, 10, 32)), -8);

        return config('custom.event_code_prefix') . $param;
    }

    public function checkV1PrizeCount(array $agentIds, int $memberId): int
    {
        $detailIds = $this->findAvailableEventDetailIdsExcludeSlotCompetition($agentIds);

        return $this->getPrizeCount($detailIds, $memberId);
    }

    private function findAvailableEventDetailIdsExcludeSlotCompetition(array $agentIds): array
    {
        return Cache::safeRemember(
            CacheKeys::availableEventDetailIdsExcludeSlotCompetition($agentIds),
            CacheTtl::ONE_MINUTE->value,
            function () use ($agentIds) {
                $now = now();

                return Event::query()
                    ->whereIn('agent_id', $agentIds)
                    ->where('status', 1)
                    ->where('start_time', '<=', $now)
                    ->whereDoesntHave('eventExcludeAgents', function ($query) use ($agentIds) {
                        $query->whereIn('agent_id', $agentIds);
                    })
                    ->whereHas('prize', function ($query) use ($now) {
                        $query->where('status', 1)
                            ->where('expire_date', '>', $now);
                    })
                    ->whereHas('eventDetail', function ($query) {
                        $query->where('status', 1)
                            ->whereNotIn('action_id', [EventActionIdEnum::SLOT_MACHINE_COMPETITION->value]);
                    })
                    ->with([
                        'eventDetail:id,event_id',
                    ])
                    ->get()
                    ->pluck('eventDetail')
                    ->flatten()
                    ->pluck('id')
                    ->toArray();
            }
        );
    }
}
