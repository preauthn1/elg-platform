<?php

declare(strict_types=1);

namespace App\Services\Frontend\EventProgressiveJackpot;

use App\Data\EventV2ListItemData;
use App\Data\UserData;
use App\Enums\EventProgressiveJackpot\EventProgressiveJackpotRewardTypeEnum;
use App\Enums\EventProgressiveJackpot\EventProgressiveJackpotUserJoinLogTypeEnum;
use App\Enums\EventProgressiveJackpot\EventProgressiveJackpotUserJoinStatusEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\EventProgressiveJackpot;
use App\Models\EventProgressiveJackpotBetSetting;
use App\Models\EventProgressiveJackpotExcludeAgent;
use App\Models\EventProgressiveJackpotImage;
use App\Models\EventProgressiveJackpotName;
use App\Models\EventProgressiveJackpotSetting;
use App\Models\EventProgressiveJackpotUserDetail;
use App\Models\EventProgressiveJackpotUserJoin;
use App\Models\EventProgressiveJackpotUserJoinLog;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Services\AgentService;
use App\Services\RealnameValidationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 成長彩金計劃（漸進式彩金活動）。規則詳見 docs/ticket_3927/ticket_3927.md。
 *
 * 目前只實作前台查詢/領取這條路徑：
 * - 檔位/解鎖比例（event_progressive_jackpot_settings、event_progressive_jackpot_bet_settings）比照
 *   event_invite_bet_settings 的慣例，全平台共用一套，不分代理。
 * - 檔位升等（依累積儲值開新檔位）與投注解鎖 point 的計算，改走排程 SP（比照
 *   SP_calc_event_invite_bet_records），不在這個 Service 裡做，本檔案只負責讀取 SP 算好的結果
 *   （event_progressive_jackpot_user_joins/user_details）並組成前端要的格式，以及處理領取。
 * - status=COMPLETED(2) 代表解鎖金額打滿了（point >= reward），不代表彩金已經領完；
 *   IN_PROGRESS(0) 跟 COMPLETED(2) 都能領取，差別只在於還會不會繼續累積 point。
 *   point 打滿轉 COMPLETED、啟用下一個等待解鎖的檔位、把滿溢的解鎖量連鎖疊加到下一檔，
 *   這幾件事都在 SP（SP_calc_event_progressive_jackpot_bet_records）裡一次處理完，不是 PHP 端懶惰檢查；
 *   PHP 端只在 receiveOne() 裡處理「COMPLETED 且領完 → CLOSED(5)，之後不再變動」這個轉換。
 *
 * UI 對照（Adobe XD 截圖）：list() 要一次回傳全部檔位（含尚未達標、還沒有 user_join 紀錄的），
 * 不是只回傳已經參加過的檔位；假設 level=0 是「首充」特殊檔位（deposit_point=0），1~4 對應 Lv1~Lv4，
 * 這個編號規則文件沒寫死，只是照畫面「首充 + Lv1~Lv4 共 5 檔」推的，之後如果編號方式不同要再調整。
 */
class EventProgressiveJackpotService
{
    /** event/list 的 tp_event 固定代號，比照 NewbieChallengeService::TP_EVENT_CODE 的慣例 */
    private const int TP_EVENT_CODE = 3;

    public function __construct(
        private AgentService $agentService
    ) {}

    /**
     * 查詢會員目前的成長彩金狀態：讀取 SP 算好的檔位/累積儲值結果，組成設定、頂部摘要卡、
     * 全部檔位卡片（含尚未達標的）與解鎖比例表
     *
     * @return array{setting: array<string, mixed>, summary: array<string, mixed>, levels: Collection<int, array<string, mixed>>, bet_settings: Collection<int, EventProgressiveJackpotBetSetting>}
     */
    public function list(Member $member, int $languageId = 0): array
    {
        $this->expireOverdueJoins($member);
        $this->activateNextWaitingJoin($member);

        $agent = $member->agent;
        $jackpot = $agent ? $this->resolveActiveJackpot($agent) : null;

        $levelSettings = EventProgressiveJackpotSetting::orderBy('level')->get();
        $joins = EventProgressiveJackpotUserJoin::where('member_id', $member->id)
            ->get()
            ->keyBy('level');

        $detail = EventProgressiveJackpotUserDetail::where('member_id', $member->id)->first();
        $totalDepositPoint = $detail->total_deposit_point ?? '0.0000';

        $levelCards = $this->buildLevelCards($levelSettings, $joins, $totalDepositPoint);

        return [
            'setting' => $this->buildSetting($jackpot, $languageId),
            'summary' => $this->buildSummary($this->resolveCurrentJoin($joins), $joins, $levelSettings->count()),
            'levels' => $levelCards,
            'bet_settings' => EventProgressiveJackpotBetSetting::all()->each(
                fn (EventProgressiveJackpotBetSetting $betSetting) => $betSetting->rate = Common::numMul((string) $betSetting->rate, '100', 4)
            ),
        ];
    }

    /**
     * 併入活動列表（EventV2Controller::list）用：成長彩金計劃只有一筆固定卡片，依 resolveActiveJackpot()
     * 判斷該代理是否啟用此活動，未啟用（或會員沒有代理）就不回傳任何項目，讓前端單純依清單有無這張卡片
     * 決定要不要顯示，不需額外欄位判斷。tp_event 固定用 self::TP_EVENT_CODE（比照
     * EventInviteService::getEventInviteList() 直接用 2 的慣例，1:新人挑戰 NewbieChallengeService、
     * 2:邀請好友、3:成長彩金）
     */
    public function getEventListItems(UserData $userData): Collection
    {
        $languageId = $userData->languageId;
        $member = $userData->member;

        $collection = new Collection;

        $agent = $member?->agent;
        $jackpot = $agent ? $this->resolveActiveJackpot($agent) : null;

        if (!$jackpot) {
            return $collection;
        }

        $name = EventProgressiveJackpotName::where('language_id', $languageId)->first()
            ?? EventProgressiveJackpotName::where('language_id', 0)->first();

        $image = EventProgressiveJackpotImage::where('language_id', $languageId)->first()
            ?? EventProgressiveJackpotImage::where('language_id', 0)->first();

        $collection->push(EventV2ListItemData::from([
            'event_v1_id' => null,
            'event_v2_id' => null,
            'tp_event' => self::TP_EVENT_CODE,
            'name' => $name?->title ?? '',
            'image' => $image?->image,
            'params' => null,
            'game_type_ids' => [],
            'sort' => $jackpot->sort,
            'created_at' => $jackpot->created_at?->toDateTimeString(),
            'end_time' => null,
        ]));

        return $collection;
    }

    /**
     * 檔位到期自動結束（風控規則：活動到期自動結束）：進行中(0)/等待解鎖(1)的檔位如果 expire_time
     * 已經過了，改成已過期(3)；過期的那一檔如果剛好有下一檔(level+1)且還在等待解鎖(1)，補啟用成
     * 進行中(0)，比照 SP_calc_event_progressive_jackpot_bet_records 補啟用下一檔的邏輯——
     * 補啟用時 expire_time 也要重新計算（= 現在 + 該檔位 expire_day），不能沿用建立當下的舊值，
     * 否則等待解鎖期間可能等了很久，新啟用的檔位一啟用就已經過期
     */
    private function expireOverdueJoins(Member $member): void
    {
        $expiredJoinIds = EventProgressiveJackpotUserJoin::where('member_id', $member->id)
            ->whereIn('status', [
                EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value,
                EventProgressiveJackpotUserJoinStatusEnum::WAITING_UNLOCK->value,
            ])
            ->whereNotNull('expire_time')
            ->where('expire_time', '<', now())
            ->pluck('id');

        if ($expiredJoinIds->isEmpty()) {
            return;
        }

        try {
            DB::beginTransaction();

            foreach ($expiredJoinIds as $expiredJoinId) {
                $locked = EventProgressiveJackpotUserJoin::query()->lockForUpdate()->find($expiredJoinId);

                // lockForUpdate 之後重新確認一次，避免在拿到鎖之前狀態或到期時間已經被別的請求改掉
                if (!$locked
                    || !in_array($locked->status, [
                        EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value,
                        EventProgressiveJackpotUserJoinStatusEnum::WAITING_UNLOCK->value,
                    ], true)
                    || $locked->expire_time === null
                    || $locked->expire_time->isFuture()
                ) {
                    continue;
                }

                $beforeStatus = $locked->status;
                $locked->update(['status' => EventProgressiveJackpotUserJoinStatusEnum::EXPIRED->value]);

                EventProgressiveJackpotUserJoinLog::create([
                    'member_id' => $member->id,
                    'level' => $locked->level,
                    'type' => EventProgressiveJackpotUserJoinLogTypeEnum::CHANGE_STATUS->value,
                    'game_type' => 0,
                    'rate' => 0,
                    'bet' => 0,
                    'before_point' => $locked->point,
                    'after_point' => $locked->point,
                    'before_receive' => $locked->receive,
                    'after_receive' => $locked->receive,
                    'before_status' => $beforeStatus,
                    'after_status' => EventProgressiveJackpotUserJoinStatusEnum::EXPIRED->value,
                ]);

                $nextJoin = EventProgressiveJackpotUserJoin::query()
                    ->lockForUpdate()
                    ->where('member_id', $member->id)
                    ->where('level', $locked->level + 1)
                    ->first();

                if (!$nextJoin || $nextJoin->status !== EventProgressiveJackpotUserJoinStatusEnum::WAITING_UNLOCK->value) {
                    continue;
                }

                // expire_time 在檔位真正轉成進行中的當下才重新計算，不沿用建立當下的舊值，
                // 比照 activateNextWaitingJoin() 與 SP_calc_event_progressive_jackpot_bet_records
                // 補啟用下一檔時的算法
                $nextLevelSetting = EventProgressiveJackpotSetting::where('level', $nextJoin->level)->first();

                $nextJoin->update([
                    'status' => EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value,
                    'expire_time' => $nextLevelSetting && $nextLevelSetting->expire_day > 0
                        ? now()->addDays($nextLevelSetting->expire_day)
                        : null,
                ]);

                EventProgressiveJackpotUserJoinLog::create([
                    'member_id' => $member->id,
                    'level' => $nextJoin->level,
                    'type' => EventProgressiveJackpotUserJoinLogTypeEnum::CHANGE_STATUS->value,
                    'game_type' => 0,
                    'rate' => 0,
                    'bet' => 0,
                    'before_point' => $nextJoin->point,
                    'after_point' => $nextJoin->point,
                    'before_receive' => $nextJoin->receive,
                    'after_receive' => $nextJoin->receive,
                    'before_status' => EventProgressiveJackpotUserJoinStatusEnum::WAITING_UNLOCK->value,
                    'after_status' => EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value,
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 補啟用等待解鎖的檔位：list() 每次查詢時順便檢查，若該會員目前完全沒有進行中(0)的檔位
     * （正常情況下 SP 在點數打滿轉 COMPLETED 的同時就會補啟用下一檔，這裡是保底：例如
     * assertNotDuplicateOnFirstReceive() 把 IN_PROGRESS 鎖定成 LOCKED(4) 後沒有連帶啟用下一檔，
     * 或其他原因造成的斷檔），就把 level 最小的等待解鎖(1)檔位改成進行中(0)，避免會員卡在
     * 完全沒有進行中檔位、儲值/投注都無法累加解鎖的狀態。
     *
     * expire_time 依文件規則（docs/ticket_3927/ticket_3927.md）在檔位真正轉成進行中的當下才重新
     * 計算（= 現在 + 該檔位 expire_day，expire_day=0 代表不限時存 null），比照
     * SP_calc_event_progressive_jackpot_bet_records 補啟用下一檔時的算法；
     * expireOverdueJoins() 的到期連鎖啟用同樣要重算 expire_time，兩處各自獨立計算，不共用同一次查詢。
     */
    private function activateNextWaitingJoin(Member $member): void
    {
        $hasInProgress = EventProgressiveJackpotUserJoin::where('member_id', $member->id)
            ->where('status', EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value)
            ->exists();

        if ($hasInProgress) {
            return;
        }

        try {
            DB::beginTransaction();

            // 鎖住 level 最小的等待解鎖(1)檔位，避免跟同一會員的並發請求搶著補啟用
            $waiting = EventProgressiveJackpotUserJoin::where('member_id', $member->id)
                ->where('status', EventProgressiveJackpotUserJoinStatusEnum::WAITING_UNLOCK->value)
                ->orderBy('level')
                ->lockForUpdate()
                ->first();

            if (!$waiting) {
                DB::commit();

                return;
            }

            // 拿到鎖之後重新確認一次，避免拿鎖前那一刻剛好有別的請求已經補啟用過
            $stillNoInProgress = !EventProgressiveJackpotUserJoin::where('member_id', $member->id)
                ->where('status', EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value)
                ->lockForUpdate()
                ->exists();

            if (!$stillNoInProgress) {
                DB::commit();

                return;
            }

            $levelSetting = EventProgressiveJackpotSetting::where('level', $waiting->level)->first();
            $beforeStatus = $waiting->status;

            $waiting->update([
                'status' => EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value,
                'expire_time' => $levelSetting && $levelSetting->expire_day > 0
                    ? now()->addDays($levelSetting->expire_day)
                    : null,
            ]);

            EventProgressiveJackpotUserJoinLog::create([
                'member_id' => $member->id,
                'level' => $waiting->level,
                'type' => EventProgressiveJackpotUserJoinLogTypeEnum::CHANGE_STATUS->value,
                'game_type' => 0,
                'rate' => 0,
                'bet' => 0,
                'before_point' => $waiting->point,
                'after_point' => $waiting->point,
                'before_receive' => $waiting->receive,
                'after_receive' => $waiting->receive,
                'before_status' => $beforeStatus,
                'after_status' => EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value,
            ]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 頂部摘要卡要顯示的「當前檔位」：優先取還在累積中的 IN_PROGRESS；
     * 沒有的話（例如剛好解鎖滿額但下一檔還沒達標開通）改取還有餘額沒領完的 COMPLETED 檔位，
     * 避免玩家彩金已經解鎖滿額、還沒領完，畫面卻完全沒有東西可看
     *
     * @param  Collection<int, EventProgressiveJackpotUserJoin>  $joins
     */
    private function resolveCurrentJoin(Collection $joins): ?EventProgressiveJackpotUserJoin
    {
        $inProgress = $joins->first(fn (EventProgressiveJackpotUserJoin $join) => $join->status === EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value);
        if ($inProgress) {
            return $inProgress;
        }

        return $joins
            ->filter(fn (EventProgressiveJackpotUserJoin $join) => $join->status === EventProgressiveJackpotUserJoinStatusEnum::COMPLETED->value
                && Common::numCompare($join->point, $join->receive, 4) > 0)
            ->sortByDesc('level')
            ->first();
    }

    /**
     * 找出目前對這個會員生效的活動：依 hierarchy_array 找到代理啟用的那一列，
     * 且該代理（或其上層，即被排除的那個層級）沒有被 event_progressive_jackpot_exclude_agents 排除
     */
    private function resolveActiveJackpot(Agent $agent): ?EventProgressiveJackpot
    {
        $jackpot = EventProgressiveJackpot::whereIn('agent_id', $agent->hierarchy_array)
            ->where('status', 1)
            ->first();

        if (!$jackpot) {
            return null;
        }

        $isExcluded = EventProgressiveJackpotExcludeAgent::where('event_progressive_jackpot_id', $jackpot->id)
            ->whereIn('agent_id', $agent->hierarchy_array)
            ->exists();

        return $isExcluded ? null : $jackpot;
    }

    /**
     * 依代理設定 EVENT_REWARD_REALNAME_VALIDATION 決定要不要擋未實名會員領獎，比照 WJackpotService::assertClaimable()
     */
    private function assertRealnameValidated(Member $member): void
    {
        $agent = $member->agent ?? Agent::find($member->parent);
        $agentSettings = $this->agentService->getAgentSettings($agent);

        if (!RealnameValidationService::isEventRewardRealnameValidated($agentSettings, $member)) {
            throw new ApiException(__('error_message.realname_invalid.event_reward'));
        }
    }

    /**
     * 組合前端顯示用的活動設定：多語言標題/內文/圖片
     *
     * @return array<string, mixed>
     */
    private function buildSetting(?EventProgressiveJackpot $jackpot, int $languageId): array
    {
        $name = EventProgressiveJackpotName::where('language_id', $languageId)->first()
            ?? EventProgressiveJackpotName::where('language_id', 0)->first();

        $image = EventProgressiveJackpotImage::where('language_id', $languageId)->first()
            ?? EventProgressiveJackpotImage::where('language_id', 0)->first();

        return [
            'status' => $jackpot ? 1 : 0,
            'title' => $name?->title,
            'content' => $name?->content,
            'image' => $image?->image,
        ];
    }

    /**
     * 組合畫面頂部摘要卡：當前進行中檔位的彩金/進度/可領取/待解鎖，以及整體完成檔位數
     *
     * @param  Collection<int, EventProgressiveJackpotUserJoin>  $joins
     * @return array<string, mixed>
     */
    private function buildSummary(?EventProgressiveJackpotUserJoin $current, Collection $joins, int $totalLevels): array
    {
        // CLOSED 是 COMPLETED 領完後的終態，兩者都算「已完成」
        $completedCount = $joins->filter(
            fn (EventProgressiveJackpotUserJoin $join) => in_array($join->status, [
                EventProgressiveJackpotUserJoinStatusEnum::COMPLETED->value,
                EventProgressiveJackpotUserJoinStatusEnum::CLOSED->value,
            ], true)
        )->count();

        if (!$current) {
            return [
                'level' => null,
                'reward' => null,
                'status' => null,
                'progress' => '0',
                'can_receive' => '0.0000',
                'pending_unlock' => '0.0000',
                'unlimited_time' => false,
                'expire_time' => null,
                'completed_count' => $completedCount,
                'total_count' => $totalLevels,
            ];
        }

        return [
            'level' => $current->level,
            'reward' => $current->reward,
            'status' => $current->status,
            'progress' => $this->calcProgress($current->point, $current->reward),
            'can_receive' => Common::numSub($current->point, $current->receive, 4),
            'pending_unlock' => Common::numSub($current->reward, $current->point, 4),
            'unlimited_time' => $current->expire_time === null,
            'expire_time' => $current->expire_time,
            'completed_count' => $completedCount,
            'total_count' => $totalLevels,
        ];
    }

    /**
     * 組合全部檔位的卡片清單，尚未達標、還沒有 user_join 紀錄的檔位也要回傳（畫面上是鎖住的卡片，
     * 顯示還差多少儲值金額），不是只回傳 event_progressive_jackpot_user_joins 已存在的那幾筆
     *
     * @param  Collection<int, EventProgressiveJackpotSetting>  $levelSettings
     * @param  Collection<int, EventProgressiveJackpotUserJoin>  $joins  依 level 為 key
     * @return Collection<int, array<string, mixed>>
     */
    private function buildLevelCards(Collection $levelSettings, Collection $joins, string $totalDepositPoint): Collection
    {
        // @phpstan-ignore return.type (Collection 的 TValue 非 covariant，兩分支各自推出的 array shape 不滿足宣告型別，屬已知限制)
        return $levelSettings->map(function (EventProgressiveJackpotSetting $levelSetting) use ($joins, $totalDepositPoint) {
            $join = $joins->get($levelSetting->level);

            $remainingDeposit = Common::numSub($levelSetting->deposit_point, $totalDepositPoint, 4);
            if (Common::numCompare($remainingDeposit, '0', 4) < 0) {
                $remainingDeposit = '0.0000';
            }

            if (!$join) {
                return [
                    'level' => (int) $levelSetting->level,
                    'status' => null, // 尚未建立參加紀錄 = 前端顯示「未達標」
                    'reward' => $this->previewReward($levelSetting),
                    'point' => '0.0000',
                    'receive' => '0.0000',
                    'remaining' => null,
                    'progress' => '0',
                    'last_point' => '0.0000',
                    'expire_time' => null,
                    'deposit_point' => (string) $levelSetting->deposit_point,
                    'remaining_deposit' => $remainingDeposit,
                    'reward_type' => (int) $levelSetting->reward_type,
                    'reward_percentage' => Common::numMul((string) $levelSetting->reward_percentage, '100', 4),
                    'max_reward' => (string) $levelSetting->max_reward,
                    'expire_day' => (int) $levelSetting->expire_day,
                ];
            }

            return [
                'level' => (int) $join->level,
                'status' => (int) $join->status,
                'reward' => (string) $join->reward,
                'point' => (string) $join->point,
                'receive' => (string) $join->receive,
                'remaining' => Common::numSub($join->reward, $join->point, 4),
                'progress' => $this->calcProgress($join->point, $join->reward),
                'last_point' => (string) $join->last_point,
                'expire_time' => $join->expire_time,
                'deposit_point' => (string) $levelSetting->deposit_point,
                'remaining_deposit' => '0.0000',
                'reward_type' => (int) $levelSetting->reward_type,
                'reward_percentage' => Common::numMul((string) $levelSetting->reward_percentage, '100', 4),
                'max_reward' => (string) $levelSetting->max_reward,
                'expire_day' => (int) $levelSetting->expire_day,
            ];
        });
    }

    /**
     * 尚未達標、還沒有實際 user_join 的檔位，reward_type=1(固定金額) 直接預覽 reward；
     * reward_type=2(百分比) 還不知道實際觸發金額，只能先預覽 max_reward（畫面上「最高 MYR X」）
     */
    private function previewReward(EventProgressiveJackpotSetting $levelSetting): string
    {
        return $levelSetting->reward_type === EventProgressiveJackpotRewardTypeEnum::PERCENTAGE->value
            ? (string) $levelSetting->max_reward
            : (string) $levelSetting->reward;
    }

    private function calcProgress(string $point, string $reward): string
    {
        if (Common::numCompare($reward, '0', 4) <= 0) {
            return '0';
        }

        return Common::numMul(Common::numDiv($point, $reward, 4), '100', 2);
    }

    /**
     * 領取指定檔位已解鎖但未領取的獎勵（point > receive），歸戶到自己的餘額，
     * 回傳跟 list() 一樣的 summary/levels 結構，讓前端領完直接刷新整個畫面。
     * $level 由前端指定要領取哪一檔（不像 EventInviteService::receive() 自動抓全部可領的）；
     * 只有 IN_PROGRESS（還在累積、但已有部分解鎖可領）跟 COMPLETED（解鎖滿額，可能還沒領完）能領，
     * WAITING_UNLOCK（等待解鎖）/EXPIRED（已過期）/LOCKED（已鎖定）都不能領
     *
     * @return array{summary: array<string, mixed>, levels: Collection<int, array<string, mixed>>}
     */
    public function receive(Member $member, int $level): array
    {
        $this->assertRealnameValidated($member);

        $detail = EventProgressiveJackpotUserDetail::where('member_id', $member->id)->first();
        if ($detail) {
            $this->assertNotDuplicateOnFirstReceive($member, $detail);
        }

        $join = EventProgressiveJackpotUserJoin::where('member_id', $member->id)
            ->where('level', $level)
            ->whereIn('status', [
                EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value,
                EventProgressiveJackpotUserJoinStatusEnum::COMPLETED->value,
            ])
            ->first();

        if (!$join) {
            throw new ApiException('Jackpot participation not found or not receivable');
        }

        $this->receiveOne($member, $join);

        $levelSettings = EventProgressiveJackpotSetting::orderBy('level')->get();
        $joins = EventProgressiveJackpotUserJoin::where('member_id', $member->id)
            ->get()
            ->keyBy('level');

        $totalDepositPoint = $detail->total_deposit_point ?? '0.0000';

        return [
            'summary' => $this->buildSummary($this->resolveCurrentJoin($joins), $joins, $levelSettings->count()),
            'levels' => $this->buildLevelCards($levelSettings, $joins, $totalDepositPoint),
        ];
    }

    /**
     * 領獎前補做一次 IP/手機號/真實姓名重複判斷：SP_calc_event_progressive_jackpot_deposit 建立
     * 首儲/檔位當下已經檢查過一次，但那之後可能有別的會員才用同樣的 IP/手機號/真實姓名加入這個活動，
     * 領獎前要再確認一次；用 user_details.is_receive 記錄「已經檢查過」，只在第一次領獎時做一次，
     * 之後每次領獎都不再重複檢查。查出重複的話，這個會員名下還在進行中(0)/等待解鎖(1)的 user_joins
     * 改成已鎖定(4)——已完成/已過期/已關閉/本來就已鎖定的不動，擋掉這次領獎並拋例外；
     * 沒有重複則標記 is_receive 後正常放行
     */
    private function assertNotDuplicateOnFirstReceive(Member $member, EventProgressiveJackpotUserDetail $detail): void
    {
        $isDuplicate = false;
        $dupIpMemberId = 0;
        $dupMobileMemberId = 0;
        $dupRealnameMemberId = 0;

        try {
            DB::beginTransaction();

            $locked = EventProgressiveJackpotUserDetail::query()->lockForUpdate()->find($detail->id);
            if (!$locked || $locked->is_receive) {
                DB::commit();

                return;
            }

            $agent = $member->agent ?? Agent::find($member->parent);
            $jackpot = $agent ? $this->resolveActiveJackpot($agent) : null;

            if ($jackpot) {
                // 實名存 members.first_name（比照 MemberFieldSettingService::isFieldFilled() 的慣例），
                // 不再查 realname_validation_requests
                $realname = $member->first_name;

                // 建檔位當下（deposit SP）寫進 user_joins.realname 的快照可能是空的或還沒通過驗證的
                // 舊值；領獎前這裡才確定拿到通過驗證的真實姓名，順手把這個會員名下所有檔位的
                // realname 快照補成正確值，確保之後「別人查是否跟自己真實姓名重複」比對到的是準的
                if (!empty($realname)) {
                    EventProgressiveJackpotUserJoin::where('member_id', $member->id)
                        ->update(['realname' => $realname]);
                }

                // 三項各自獨立檢查，不能用 || 短路（會漏掉後面兩項），比照 deposit SP 的作法，
                // 把命中重複的對方 member_id 各自寫回 block_xxx_member_id，不是只記一個布林值
                $dupIpMemberId = ($jackpot->ip_block && $member->register_ip)
                    ? $this->findDuplicateJoinMemberId('register_ip', $member->register_ip, $member->id)
                    : 0;
                $dupMobileMemberId = ($jackpot->mobile_block && $member->mobile)
                    ? $this->findDuplicateJoinMemberId('mobile', $member->mobile, $member->id)
                    : 0;
                $dupRealnameMemberId = ($jackpot->realname_block && $realname)
                    ? $this->findDuplicateJoinMemberId('realname', $realname, $member->id)
                    : 0;

                $isDuplicate = $dupIpMemberId > 0 || $dupMobileMemberId > 0 || $dupRealnameMemberId > 0;
            }

            if ($isDuplicate) {
                // block_xxx_member_id 只在查到重複時才寫，保留歷史紀錄：不能不管有沒有查到都寫，
                // 否則這次沒查到（例如後台後來把 ip_block 關掉、或對方那筆重複的 join 被刪了）會把
                // deposit SP 那邊之前已經標記過的重複紀錄蓋掉成 0
                $locked->update([
                    'block_ip_member_id' => $dupIpMemberId,
                    'block_mobile_member_id' => $dupMobileMemberId,
                    'block_realname_member_id' => $dupRealnameMemberId,
                    'is_receive' => true,
                ]);

                // 只鎖還在進行中(0)/等待解鎖(1)的檔位；已完成/已過期/已關閉/本來就已鎖定的不動——
                // 後台可能會手動放開限制讓某一檔重新變成進行中，這裡不該把那些已經是終態或已被
                // 後台介入過的檔位也一起改掉
                EventProgressiveJackpotUserJoin::where('member_id', $member->id)
                    ->whereIn('status', [
                        EventProgressiveJackpotUserJoinStatusEnum::IN_PROGRESS->value,
                        EventProgressiveJackpotUserJoinStatusEnum::WAITING_UNLOCK->value,
                    ])
                    ->update(['status' => EventProgressiveJackpotUserJoinStatusEnum::LOCKED->value]);
            } else {
                $locked->update(['is_receive' => true]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        if ($isDuplicate) {
            throw new ApiException('Jackpot participation locked due to duplicate IP/mobile/realname');
        }
    }

    /**
     * 找出跟自己 $column 值相同的其他會員（任一筆即可，不用管有幾筆），回傳對方 member_id；
     * 沒有重複回傳 0（比照 deposit SP 的 DupIpMemberId/DupMobileMemberId/DupRealnameMemberId 慣例）
     *
     * 只認對方 user_details.is_receive = 1（已完成過這個檢查）的紀錄，否則會有互相鎖定的順序問題：
     * A、B 真實姓名重複，A 先儲值、B 後儲值（B 儲值時 deposit SP 查到 A 已存在的 join 直接把 B 鎖定），
     * 若不限定 is_receive，A 之後領獎時查到 B 的 join 一樣是真實姓名重複，會把本來合法的 A 也一併鎖住——
     * B 從未通過這個檢查（is_receive 仍是 0），不該回頭拖累先到、已合法在領獎的 A
     *
     * @param  int|string  $value
     */
    private function findDuplicateJoinMemberId(string $column, $value, int $excludeMemberId): int
    {
        return (int) (EventProgressiveJackpotUserJoin::where($column, $value)
            ->where('member_id', '!=', $excludeMemberId)
            ->whereIn('member_id', function ($query) {
                $query->select('member_id')
                    ->from('event_progressive_jackpot_user_details')
                    ->where('is_receive', true);
            })
            ->value('member_id') ?? 0);
    }

    /**
     * 處理領取金額異動；COMPLETED 檔位如果這次剛好領完全部彩金（after_receive >= reward，
     * 也就等於 == point == reward，因為 receive 不會超過 point），轉成 CLOSED，之後不再變動、
     * 也不會再出現在 list()/receive() 的可領取清單裡。IN_PROGRESS 檔位這次沒領完（還在累積 point）則不動 status
     */
    private function receiveOne(Member $member, EventProgressiveJackpotUserJoin $join): EventProgressiveJackpotUserJoin
    {
        try {
            DB::beginTransaction();

            $locked = EventProgressiveJackpotUserJoin::query()
                ->lockForUpdate()
                ->find($join->id);

            if (!$locked) {
                throw new ApiException('Jackpot participation not found');
            }

            $availablePoint = Common::numSub($locked->point, $locked->receive, 4);
            if (Common::numCompare($availablePoint, '0', 4) <= 0) {
                throw new ApiException('Nothing available to receive');
            }

            $beforeReceive = $locked->receive;
            $afterReceive = Common::numAdd($beforeReceive, $availablePoint, 4);
            $beforeStatus = $locked->status;

            // 領取後的累積金額不可超過這檔的彩金總額上限：reward_type=2(百分比) 的檔位在建立時
            // 已經用 LEAST(deposit_amount * percentage, max_reward) 封頂過，reward 本身就是「不超過
            // max_reward」後的值，point 理論上也不會超過 reward（見下方 afterStatus 的假設）；這裡
            // 在真的把錢發出去之前再守一道，避免因未知原因（例如 point 被其他來源異常改大）超額派彩
            if (Common::numCompare($afterReceive, $locked->reward, 4) > 0) {
                throw new ApiException('Receive amount exceeds reward limit');
            }

            $this->giveMemberReward($member, $availablePoint);

            // receive 不會超過 point，point 又封頂在 reward，所以 afterReceive >= reward 等於「已解鎖金額全數領完」
            $afterStatus = Common::numCompare($afterReceive, $locked->reward, 4) >= 0
                ? EventProgressiveJackpotUserJoinStatusEnum::CLOSED->value
                : $beforeStatus;

            $locked->update([
                'receive' => $afterReceive,
                'status' => $afterStatus,
            ]);

            EventProgressiveJackpotUserJoinLog::create([
                'member_id' => $member->id,
                'level' => $locked->level,
                'type' => EventProgressiveJackpotUserJoinLogTypeEnum::RECEIVE->value,
                'game_type' => 0,
                'rate' => 0,
                'bet' => 0,
                'before_point' => $locked->point,
                'after_point' => $locked->point,
                'before_receive' => $beforeReceive,
                'after_receive' => $afterReceive,
                'before_status' => $beforeStatus,
                'after_status' => $afterStatus,
            ]);

            DB::commit();

            $join->receive = $locked->receive;
            $join->status = $locked->status;

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
            'remark' => '成長彩金活動',
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
            'admin' => 0,
        ]);

        if (!$logResult || !$logResult->id) {
            throw new \Exception('Transaction log create failed');
        }

        return $logResult->id;
    }
}
