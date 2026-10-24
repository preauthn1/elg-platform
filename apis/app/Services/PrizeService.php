<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Event\EventActionIdEnum;
use App\Enums\FlowLimitTypeEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Helpers\Traits\DistributedLock;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventPayoutCancel;
use App\Models\EventProcess;
use App\Models\FlowLimit;
use App\Models\FlowLimitRestriction;
use App\Models\Member;
use App\Models\MemberBankAccount;
use App\Models\NewbieChallengeProgress;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\PromotionWallet;
use App\Models\PromotionWalletLog;
use App\Models\RewardClaimRecord;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 舊版活動獎項（V1）
 *
 * 遷移自 API_Frontend PrizeController。
 */
class PrizeService
{
    use DistributedLock;

    /** prize_contents.point_type：禮碼 */
    private const int POINT_TYPE_ROLLING_CHIP = 2;

    /** prize_contents.point_type：優惠錢包 */
    private const int POINT_TYPE_PROMOTION_WALLET = 4;

    /** promotion_wallet_logs.type：活動獎勵（舊版 TransactionEnum::TYPE_PROMOTION_WALLET_EVENT_REWARD） */
    private const int PROMOTION_WALLET_LOG_TYPE_EVENT_REWARD = 3;

    /** prize_members.status：已取消 */
    private const int PRIZE_MEMBER_STATUS_CANCELLED = 2;

    /** event_payout_cancels.type：玩家自主取消 */
    private const int PAYOUT_CANCEL_TYPE_MEMBER_SELF = 5;

    /** getAllPrize 單次全部領取上限 */
    private const int CLAIM_ALL_LIMIT = 100;

    public function __construct(
        protected EventService $eventService,
        protected AgentService $agentService,
        protected MemberTransferService $memberTransferService,
        protected WalletService $walletService,
    ) {}

    /**
     * prizes - 已領取的獎項紀錄（分頁）
     *
     * 遷移自 API_Frontend PrizeController::list（tpower-master / vw-master 完全相同）。
     *
     * @return array{data: Collection<int, mixed>, total: int}
     */
    public function getClaimedList(Member $member, int $languageId, int $page, int $perPage): array
    {
        // 舊版直接對 groupBy 後的 query 呼叫 count()，回傳的是第一組的筆數（恆為 1），分頁因此失效
        $total = $this->claimedQuery($member, $languageId)->distinct()->count('m.id');

        $data = $this->claimedQuery($member, $languageId)
            ->groupBy('m.id')
            ->orderByDesc('m.updated_at')
            ->orderByDesc('m.id')
            ->select([
                'm.id',
                DB::raw('(CASE WHEN n.title is NULL THEN e.title ELSE n.title END) as event_name'),
                'c.name',
                DB::raw('CASE WHEN c.type = 1 THEN TRUNCATE(m.lottery_point, 2) ELSE c.point END as point'),
                DB::raw('DATE_ADD(m.updated_at, INTERVAL 8 HOUR) as updated_at'),
            ])
            ->forPage($page, $perPage)
            ->get();

        return [
            'data' => $data,
            'total' => $total,
        ];
    }

    /**
     * getPrizeByDetailId - 依活動明細抽出並領取獎項
     *
     * 遷移自 API_Frontend PrizeController::getPrizeByDetailId（tpower-master / vw-master 完全相同）。
     *
     * @return object 舊版的 data（獎勵主檔＋prizes 獎項列表＋prize 中獎項目）
     */
    public function drawByDetail(Member $member, Agent $agent, int $eventId, int $detailId): object
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

        $this->eventService->assertEventAccessible($eventId, $agent);

        return DB::transaction(function () use ($member, $agent, $eventId, $detailId) {
            $prize = Prize::query()
                ->where('event_id', $eventId)
                ->where('status', 1)
                ->where('expire_date', '>=', now()->toDateTimeString())
                ->select(['id', 'event_id', 'type_id', 'name', 'background', 'number', 'expire_date', 'is_repeatedly'])
                ->first();

            if (!$prize) {
                // 查不到活動獎項
                throw new ApiException(__('error_message.1901'));
            }

            $contents = PrizeContent::query()
                ->where('prize_id', $prize->id)
                ->where('status', 1)
                ->where(function ($query) {
                    $query->whereColumn('number', '>', 'sent')->orWhere('number', -1);
                })
                ->lockForUpdate()
                ->select(['id', 'name', 'type', 'point', 'point_percentage', 'percentage'])
                ->get();

            $data = (object) $prize->toArray();
            if ($contents->isNotEmpty()) {
                $data->prizes = $contents;
            }

            // 取出還沒兌換的獎勵
            $effective = PrizeMember::query()
                ->where('prize_id', $prize->id)
                ->where('member_id', $member->id)
                ->where('event_detail_id', $detailId)
                ->where('status', 0)
                ->lockForUpdate()
                ->first();

            if (!$effective) {
                // 沒有可以領取的獎勵
                throw new ApiException(__('error_message.9003'));
            }

            $contentId = $this->resolveDrawnContent($effective, $contents, $data);

            // 檢查是否合法領取，並刷新進度條（只能單次兌換的不刷新）
            if (!$this->refreshProcess($eventId, $member->id, (int) $prize->is_repeatedly, $detailId)) {
                throw new ApiException(__('error_message.2004'));
            }

            $details = EventDetail::query()->where('event_id', $eventId)->where('id', $detailId)->first();
            if (!$details) {
                // 舊版此處無 else，會回傳空 body 且交易懸空
                throw new ApiException(__('error_message.1901'));
            }

            $expiredTime = Carbon::parse($details->draw_time)->addHours((int) $details->expire_hour);
            if ($details->expire_hour != 0 && now()->greaterThanOrEqualTo($expiredTime)) {
                // 已過期
                throw new ApiException(__('error_message.1948'));
            }

            $this->sendPrize($effective, $contentId, $member, $agent, $details, ' ');

            return $data;
        });
    }

    /**
     * 取出（或抽出）本次要派發的獎項內容 id，並掛到回應的 data 上
     *
     * @param  Collection<int, PrizeContent>  $contents
     */
    private function resolveDrawnContent(PrizeMember $effective, Collection $contents, object $data): int
    {
        if ($effective->content_id > 0) {
            // 之前抽出，但還沒領走的
            $row = PrizeContent::select(['id', 'name', 'type', 'point', 'point_percentage'])->find($effective->content_id);
            $row->prize_member = $effective->id;
            $data->prize = $row;

            return (int) $effective->content_id;
        }

        // 還沒抽出獎品，依中獎機率抽取後寫入
        $total = 0;
        foreach ($contents as $row) {
            $total += (int) $row->percentage;
        }

        // 舊版此處 rand(1, 0) 在 PHP 8 會拋 ValueError
        if ($total <= 0) {
            throw new ApiException(__('error_message.2004'));
        }

        $result = rand(1, $total);
        $count = 0;
        foreach ($contents as $row) {
            $count += (int) $row->percentage;
            if ($count < $result) {
                continue;
            }

            PrizeContent::where('id', $row->id)->update(['sent' => DB::raw('sent+1')]);
            $effective->update(['content_id' => $row->id]);
            $row->prize_member = $effective->id;
            $data->prize = $row;

            return (int) $row->id;
        }

        // 取不到獎項
        throw new ApiException(__('error_message.2004'));
    }

    /**
     * cancelPrize - 玩家自主取消未領取的儲值類活動獎勵
     *
     * 遷移自 API_Frontend PrizeController::cancelPrize（tpower-master / vw-master 完全相同）。
     * 對象為 action 53／54／55（首充／次次充／每日充）中尚未領取且未逾期的 prize_members。
     */
    public function cancelPrize(Member $member, Agent $agent): void
    {
        $detailIds = $this->eventService->findAvailableEventDetailIds($agent->hierarchy_array);

        if (empty($detailIds)) {
            throw new ApiException(__('error_message.2001'));
        }

        DB::transaction(function () use ($member, $detailIds) {
            $prizeMembers = PrizeMember::query()
                ->whereIn('event_detail_id', $detailIds)
                ->where('member_id', $member->id)
                ->where('status', 0)
                ->where(function ($query) {
                    $query->where('expired_time', '>=', now()->toDateTimeString())->orWhereNull('expired_time');
                })
                ->lockForUpdate()
                ->get();

            foreach ($prizeMembers as $prizeMember) {
                $prizeMember->update(['status' => self::PRIZE_MEMBER_STATUS_CANCELLED]);

                $prize = Prize::find($prizeMember->prize_id);
                if (!$prize) {
                    continue;
                }

                EventPayoutCancel::create([
                    'event_id' => $prize->event_id,
                    'prize_member_id' => $prizeMember->id,
                    'from_member_id' => $member->id,
                    'member_id' => $member->id,
                    'type' => self::PAYOUT_CANCEL_TYPE_MEMBER_SELF,
                ]);
            }
        });
    }

    /**
     * getAllPrize - 一次領取該活動所有未兌換的獎勵
     *
     * 遷移自 API_Frontend PrizeController::getAllPrize。兩分支的派彩實作互斥，
     * 本次統一走共用的 sendPrize（vw 作法，詳見遷移文件 2-12）。
     *
     * @return array{data: array, prize: list<mixed>}
     */
    public function drawAllPrizes(Member $member, Agent $agent, int $eventId): array
    {
        // 領獎需通過手機驗證
        if ($agent->getSettingValue(AgentCustomizedSettingKeyEnum::EVENT_REWARD_MOBILE_VALIDATION)
            && $member->mobile_validate == 0
        ) {
            throw new ApiException(__('error_message.9007'));
        }

        // 判斷是否需要實名認證
        $agentSettings = $this->agentService->getAgentSettings($agent);
        if (!RealnameValidationService::isEventRewardRealnameValidated($agentSettings, $member)) {
            throw new ApiException(__('error_message.realname_invalid.event_reward'));
        }

        $this->eventService->assertEventAccessible($eventId, $agent);

        $event = Event::where('id', $eventId)->where('status', 1)->first();
        if (!$event) {
            throw new ApiException(__('error_message.1901'));
        }

        $now = now()->toDateTimeString();
        $eventDetail = EventDetail::query()
            ->where('event_id', $eventId)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->where('status', 1)
            ->first();

        if (!$eventDetail) {
            throw new ApiException(__('error_message.1901'));
        }

        // 限制遊戲商入口（鎖分模式）的玩家不能重複領取
        if ($eventDetail->vendor_lock == 1 && $member->event_mode > 0) {
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

        $prize = Prize::query()
            ->where('event_id', $eventId)
            ->where('status', 1)
            ->where('expire_date', '>=', $now)
            ->first();

        if (!$prize) {
            throw new ApiException(__('error_message.1901'));
        }

        // 確認活動領獎是否需要綁定洗分銀行
        throw_if(
            ($check = $this->checkMemberFirstNameAndWithdrawAccount($member, $agent)) > 0,
            new ApiException(match ($check) {
                1 => __('error_message.2041'),
                2 => __('error_message.1095'),
                3 => __('error_message.2040'),
                default => __('error_message.2001'),
            })
        );

        // 重複性檢查改成逐筆進行（見下方 transaction 內 filterClaimableAndCancelDuplicates），
        // 不在這裡對單一 sample 做一次性檢查——批次裡任何一筆被擋下都不該連帶擋住其他合法可領的筆數
        // （target/op_tickets#3231）

        // 鎖分模式的活動，領獎前先把轉帳錢包的遊戲商餘額轉回本地
        if ($eventDetail->vendor_lock == 1) {
            $this->transferBackBeforeClaim($member, $eventId, 'getAllPrize');
        }

        $data = [
            'id' => $prize->id,
            'event_id' => $prize->event_id,
            'type_id' => $prize->type_id,
            'name' => $prize->name,
            'background' => $prize->background,
            'number' => $prize->number,
            'expire_date' => $prize->expire_date,
            'is_repeatedly' => $prize->is_repeatedly,
        ];

        $remark = (string) json_encode([
            'event_id' => $event->id,
            'title' => $event->title,
            'action_id' => $eventDetail->action_id,
            'point' => $eventDetail->point,
        ]);

        $result = DB::transaction(function () use ($member, $agent, $eventDetail, $prize, $eventId, $data, $remark) {
            $this->addRewardClaimRecord($member, $eventDetail);

            $effectives = PrizeMember::query()
                ->where('prize_id', $prize->id)
                ->where('member_id', $member->id)
                ->where(function ($query) {
                    $query->where('expired_time', '>=', now()->toDateTimeString())->orWhereNull('expired_time');
                })
                ->where('status', 0)
                ->orderBy('id')
                ->limit(self::CLAIM_ALL_LIMIT)
                ->lockForUpdate()
                ->get();

            if ($effectives->isEmpty()) {
                // 沒有可以領取的獎勵：回傳 error_message_key 讓 transaction 照常 commit，不能在這裡
                // 直接拋例外——DB::transaction 遇例外會整包 rollback，連同上面 addRewardClaimRecord
                // 這次呼叫本身的稽核寫入都會被撤銷，之後同一批重複偵測會失去這筆紀錄可比對
                return ['error_message_key' => 'error_message.1949'];
            }

            $claimable = $this->filterClaimableAndCancelDuplicates($effectives, $eventDetail, $prize, $member);

            if ($claimable->isEmpty()) {
                // 整批都被重複性檢查擋下（新命中或尚未由後台核准）：同上，要讓 transaction 照常
                // commit，才能留住 filterClaimableAndCancelDuplicates 裡新寫入的 EventPayoutCancel
                // 與 prize_members.status=2 取消紀錄——這裡如果拋例外，這些寫入會被 rollback 掉，
                // 下次再呼叫又會重新判定一次，永遠留不住紀錄
                //
                // 回傳具體的擋下原因（1950~1952），不是通用的 1949：這批 prize_member 全數被排除，
                // 代表每一筆都已經對應到一筆 status=0（尚未由後台核准）的 EventPayoutCancel——
                // 不管是原本就存在、還是這次 checkPrizeBlock 新判定寫入的，任取一筆的 type 就能反映
                // 真正的擋下原因；多筆原因不同時沿用舊版單一 sample 檢查時「取其中一筆」的既有假設，
                // 不特別排序或合併
                $blockedType = (int) EventPayoutCancel::query()
                    ->where('event_id', $eventDetail->event_id)
                    ->where('member_id', $member->id)
                    ->whereIn('prize_member_id', $effectives->pluck('id'))
                    ->where('status', 0)
                    ->value('type');

                return ['error_message_key' => $this->resolveBlockErrorMessageKey($blockedType)];
            }

            $gifts = [];

            foreach ($claimable as $effective) {
                $contentId = $this->resolveClaimAllContent($effective, $prize, $data, $gifts);

                // 檢查是否合法領取，並刷新進度條（只能單次兌換的不刷新）
                if (!$this->refreshProcess($eventId, $member->id, (int) $prize->is_repeatedly)) {
                    throw new ApiException(__('error_message.2004'));
                }

                $this->sendPrize($effective, $contentId, $member, $agent, $eventDetail, $remark);
            }

            return ['data' => $data, 'prize' => $gifts];
        });

        if (isset($result['error_message_key'])) {
            throw new ApiException(__($result['error_message_key']));
        }

        return $result;
    }

    /**
     * getAllPrize 逐筆重複性檢查：沿用 checkPrizeBlock／EventPayoutCancel 既有判定規則，
     * 但改成對批次裡每一筆 prize_member 各自判斷，不是只抽一筆代表全部——舊版只用單一 sample
     * 呼叫一次 assertNotBlocked，只要那一筆被擋下（不論是新命中重複、還是尚未由後台核准），
     * 就會連帶擋住批次裡其他已經合法可領的筆數，包含已經由後台 EventPayoutCancel.status=1
     * 補發核准的那幾筆（target/op_tickets#3231）。
     *
     * 逐筆規則：
     * - 尚無 EventPayoutCancel 紀錄：呼叫 checkPrizeBlock 判定；命中重複的話（內部已寫入
     *   EventPayoutCancel，預設 status=0）把這筆 prize_members.status 改成 2(取消)，不計入
     *   本次可領清單；沒命中則計入可領清單。
     * - 已有 EventPayoutCancel 紀錄：status=0（尚未由後台核准）→ 跳過這筆，不計入可領清單，
     *   也不改動 prize_members.status（留給後續人工處理，不重複判定、不重複取消）；
     *   status=1（已由後台補發核准）→ 計入可領清單，允許正常領取。
     *
     * 沿用單一 sample 檢查時的既有假設：整批共用同一個 $eventDetail 的擋重規則設定
     * （ip_block/phone_block/account_block/global_account_block/tgid_block）與比對範圍，
     * 不因批次裡個別 prize_member 可能屬於不同 event_detail_id 而各自套用不同規則。
     *
     * @param  Collection<int, PrizeMember>  $effectives
     * @return Collection<int, PrizeMember>
     */
    private function filterClaimableAndCancelDuplicates(
        Collection $effectives,
        EventDetail $eventDetail,
        Prize $prize,
        Member $member
    ): Collection {
        $existingCancels = EventPayoutCancel::query()
            ->where('event_id', $eventDetail->event_id)
            ->where('member_id', $member->id)
            ->whereIn('prize_member_id', $effectives->pluck('id'))
            ->get()
            ->keyBy('prize_member_id');

        return $effectives->filter(function (PrizeMember $effective) use ($existingCancels, $eventDetail, $prize, $member) {
            $existing = $existingCancels->get($effective->id);

            if ($existing) {
                return (int) $existing->status === 1;
            }

            if ($this->checkPrizeBlock($eventDetail, $prize, (int) $effective->id, $member)) {
                $effective->update(['status' => self::PRIZE_MEMBER_STATUS_CANCELLED]);

                return false;
            }

            return true;
        })->values();
    }

    /**
     * 領獎前把遊戲商餘額轉回本地
     *
     * 必須與 MemberTransferController 共用同一把會員層級轉帳鎖（`member:{id}:transfer`），
     * 否則玩家一邊領獎一邊自行轉帳時，兩邊會同時對同一筆遊戲商餘額做轉回。
     */
    private function transferBackBeforeClaim(Member $member, int $eventId, string $tag): void
    {
        $this->withLock(sprintf('member:%d:transfer', $member->id), 120, function () use ($member, $eventId, $tag) {
            try {
                $this->memberTransferService->transferBackRecentVendors($member);
            } catch (Throwable $e) {
                Log::error("[{$tag}] 轉回遊戲商餘額失敗", [
                    'member_id' => $member->id,
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);

                throw new ApiException(__('error_message.1957'));
            }
        });
    }

    /**
     * getAllPrize 逐筆取出（或抽出）獎項內容
     *
     * @param  list<mixed>  $gifts
     */
    private function resolveClaimAllContent(PrizeMember $effective, Prize $prize, array &$data, array &$gifts): int
    {
        if ($effective->content_id > 0) {
            // 之前抽出，但還沒領走的
            $row = PrizeContent::select(['id', 'name', 'type', 'point', 'point_percentage'])->find($effective->content_id);
            $row->prize_member = $effective->id;
            $data['prize'] = $row;
            $gifts[] = $row;

            return (int) $effective->content_id;
        }

        $contents = PrizeContent::query()
            ->where('prize_id', $prize->id)
            ->where('status', 1)
            ->where(function ($query) {
                $query->whereColumn('number', '>', 'sent')->orWhere('number', -1);
            })
            ->lockForUpdate()
            ->select(['id', 'name', 'type', 'point', 'point_percentage', 'percentage'])
            ->get();

        if ($contents->isNotEmpty()) {
            $data['prizes'] = $contents;
        }

        $total = 0;
        foreach ($contents as $row) {
            $total += (int) $row->percentage;
        }

        // 舊版此處 rand(1, 0) 在 PHP 8 會拋 ValueError
        if ($total <= 0) {
            throw new ApiException(__('error_message.2004'));
        }

        $result = rand(1, $total);
        $count = 0;
        foreach ($contents as $row) {
            $count += (int) $row->percentage;
            if ($count < $result) {
                continue;
            }

            PrizeContent::where('id', $row->id)->update(['sent' => DB::raw('sent+1')]);
            $effective->update(['content_id' => $row->id]);
            $row->prize_member = $effective->id;
            $data['prize'] = $row;
            $gifts[] = $row;

            return (int) $row->id;
        }

        // 取不到獎項
        throw new ApiException(__('error_message.2004'));
    }

    /**
     * getPrize - 抽出並領取活動獎項
     *
     * 遷移自 API_Frontend PrizeController::getPrize，取 tpower-master 與 vw-master 的聯集。
     *
     * @return array 舊版的 data（獎勵主檔＋prizes 獎項列表＋prize 中獎項目）
     */
    public function drawPrize(Member $member, Agent $agent, int $eventId, int $languageId): array
    {
        // 領獎需通過手機驗證
        if ($agent->getSettingValue(AgentCustomizedSettingKeyEnum::EVENT_REWARD_MOBILE_VALIDATION)
            && ($member->mobile_validate != 1 || $member->mobile == '')
        ) {
            throw new ApiException(__('error_message.9007'));
        }

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

        $this->eventService->assertEventAccessible($eventId, $agent);

        $event = Event::where('id', $eventId)->where('status', 1)->first();
        if (!$event) {
            throw new ApiException(__('error_message.1901'));
        }

        $now = now()->toDateTimeString();
        $eventDetail = EventDetail::query()
            ->where('event_id', $eventId)
            ->where('start_time', '<=', $now)
            ->where('end_time', '>', $now)
            ->where('status', 1)
            ->first();

        if (!$eventDetail) {
            throw new ApiException(__('error_message.1901'));
        }

        $prize = Prize::query()
            ->where('event_id', $eventId)
            ->where('status', 1)
            ->where('expire_date', '>=', $now)
            ->first();

        if (!$prize) {
            throw new ApiException(__('error_message.1901'));
        }

        $isLoginGift = $eventDetail->action_id == EventActionIdEnum::LOGIN->value;

        $prizeMember = PrizeMember::query()
            ->where('prize_id', $prize->id)
            ->where('event_detail_id', $eventDetail->id)
            ->where('member_id', $member->id)
            ->where(function ($query) use ($now) {
                $query->where('expired_time', '>=', $now)->orWhereNull('expired_time');
            })
            ->where('status', 0)
            ->first();

        if (!$prizeMember && !$isLoginGift) {
            throw new ApiException(__('error_message.1949'));
        }

        // 確認活動領獎是否需要綁定洗分銀行
        throw_if(
            ($check = $this->checkMemberFirstNameAndWithdrawAccount($member, $agent)) > 0,
            new ApiException(match ($check) {
                1 => __('error_message.2041'),
                2 => __('error_message.1095'),
                3 => __('error_message.2040'),
                default => __('error_message.2001'),
            })
        );

        // 登入送要確認完 IP、手機、真實姓名，才會派發獎勵
        $prizeMemberId = $isLoginGift ? 0 : (int) $prizeMember->id;
        $this->assertNotBlocked($eventDetail, $prize, $prizeMemberId, $member);

        // 鎖分模式的活動，領獎前先把轉帳錢包的遊戲商餘額轉回本地
        if ($eventDetail->vendor_lock == 1) {
            $this->transferBackBeforeClaim($member, $eventId, 'getPrize');
        }

        $data = [
            'id' => $prize->id,
            'event_id' => $prize->event_id,
            'type_id' => $prize->type_id,
            'name' => $prize->name,
            'background' => $prize->background,
            'number' => $prize->number,
            'expire_date' => $prize->expire_date,
            'is_repeatedly' => $prize->is_repeatedly,
        ];

        $remark = (string) json_encode([
            'event_id' => $event->id,
            'title' => $event->title,
            'action_id' => $eventDetail->action_id,
            'point' => $eventDetail->point,
        ]);

        return DB::transaction(function () use ($member, $agent, $event, $eventDetail, $prize, $prizeMemberId, $isLoginGift, $languageId, $data, $remark) {
            // 登入送沒有事前建好的 prize_members，下方 exists → create 之間有空窗；
            // 以 member 列把同一會員的併發領取序列化（prize_members 唯一索引另案分階段補）
            Member::where('id', $member->id)->lockForUpdate()->first();

            $this->addRewardClaimRecord($member, $eventDetail);

            $contents = PrizeContent::query()
                ->where('prize_id', $prize->id)
                ->where(function ($query) {
                    $query->whereColumn('number', '>', 'sent')->orWhere('number', -1);
                })
                ->where('status', 1)
                ->lockForUpdate()
                ->select(['id', 'name', 'type', 'point', 'point_percentage', 'percentage'])
                ->get();

            if ($contents->isNotEmpty()) {
                $data['prizes'] = $contents;
            }

            if ($isLoginGift) {
                // 登入送：前面的 IP／手機／真實姓名都通過才建立領獎資格
                $exists = PrizeMember::where('prize_id', $prize->id)->where('member_id', $member->id)->exists();
                if (!$exists) {
                    $prizeMemberId = (int) PrizeMember::create([
                        'member_id' => $member->id,
                        'prize_id' => $prize->id,
                        'content_id' => 0,
                        'status' => 0,
                        'admin' => 51,
                    ])->id;
                }
            }

            $effective = PrizeMember::query()
                ->where('id', $prizeMemberId)
                ->where('status', 0)
                ->lockForUpdate()
                ->first();

            if (!$effective) {
                // 沒有可以領取的獎勵
                throw new ApiException(__('error_message.1949'));
            }

            // 註冊送：實名認證須在 members.created_at + event_details.expire_hour 期限內完成，逾期取消
            $this->assertRegBonusRealnameVerifiedInTime($member, $eventDetail, $effective);

            $contentId = $this->resolveDrawnPrizeContent($effective, $contents, $eventDetail, $languageId, $data);

            if ($this->isLegalClaim($eventDetail, $prize, $member, $event->id)) {
                $sendResult = $this->sendPrize($effective, $contentId, $member, $agent, $eventDetail, $remark);
                $data['prize']['get_point'] = $sendResult['content_point'];
                $data['prize']['point_type'] = $sendResult['content_point_type'];

                return $data;
            }

            // 不合法
            throw new ApiException(__('error_message.2004'));
        });
    }

    /**
     * 取出（或抽出）getPrize 要派發的獎項內容，並掛到回應的 data 上
     *
     * @param  Collection<int, PrizeContent>  $contents
     */
    private function resolveDrawnPrizeContent(
        PrizeMember $effective,
        Collection $contents,
        EventDetail $eventDetail,
        int $languageId,
        array &$data,
    ): int {
        if ($effective->content_id > 0) {
            $row = PrizeContent::query()
                ->leftJoin('prize_content_names as pcn', function ($join) use ($languageId) {
                    $join->on('pcn.prize_content_id', 'prize_contents.id')->where('pcn.language_id', $languageId);
                })
                ->where('prize_contents.id', $effective->content_id)
                ->select([
                    'prize_contents.id',
                    DB::raw('IFNULL(pcn.name, prize_contents.name) as name'),
                    'prize_contents.type',
                    'prize_contents.point',
                    'prize_contents.point_percentage',
                ])
                ->first();

            $row->prize_member = $effective->id;
            $data['prize'] = $row;

            // 儲值活動與註冊送會在 SP 增加，所以這邊不增加（action 11 為 vw 獨有）
            $sentBySp = [
                EventActionIdEnum::REG_BONUS->value,
                EventActionIdEnum::WEEKLY_RESCUE_FUND->value,
                EventActionIdEnum::FIRST_DEPOSIT_AUTO->value,
                EventActionIdEnum::DEPOSIT_AUTO->value,
                EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value,
                EventActionIdEnum::DEPOSIT_MANUAL->value,
                EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
                EventActionIdEnum::PICK_UP_ONCE_A_DAY_AUTO->value,
                EventActionIdEnum::DEPOSIT_CYCLE_TIME_AUTO->value,
                EventActionIdEnum::DEPOSIT_CYCLE_TIME->value,
                EventActionIdEnum::DAILY_CONSECUTIVE_DEPOSIT->value,
            ];

            if (!in_array($eventDetail->action_id, $sentBySp)) {
                PrizeContent::where('id', $row->id)->increment('sent');
            }

            return (int) $effective->content_id;
        }

        // 還沒抽出獎品，依中獎機率抽取後寫入
        $total = 0;
        foreach ($contents as $row) {
            $total += (int) $row->percentage;
        }

        // 舊版此處 rand(1, 0) 在 PHP 8 會拋 ValueError
        if ($total <= 0) {
            throw new ApiException(__('error_message.2004'));
        }

        $result = rand(1, $total);
        $count = 0;
        foreach ($contents as $row) {
            $count += (int) $row->percentage;
            if ($count < $result) {
                continue;
            }

            PrizeContent::where('id', $row->id)->update(['sent' => DB::raw('sent+1')]);

            $updateData = ['content_id' => $row->id, 'ip' => explode(', ', Common::getIp())[0]];
            if ($eventDetail->action_id == EventActionIdEnum::LOGIN->value) {
                // 登入送在這邊把送的獎勵分數寫進去；舊版誤寫成不存在的 point 欄位（見遷移文件 2-10）
                $updateData['lottery_point'] = $row->point;
            }
            $effective->update($updateData);

            $row->prize_member = $effective->id;
            $data['prize'] = $row;

            return (int) $row->id;
        }

        // 取不到獎項
        throw new ApiException(__('error_message.2004'));
    }

    /**
     * 是否達成領取條件；登入送與 tpower 邀請玩家活動沒有進度條，直接視為通過
     */
    private function isLegalClaim(EventDetail $eventDetail, Prize $prize, Member $member, int $eventId): bool
    {
        $noProcessActions = [
            EventActionIdEnum::LOGIN->value,
            EventActionIdEnum::TPOWER_INVITE_PLAYER->value,
        ];

        if (in_array($eventDetail->action_id, $noProcessActions)) {
            return true;
        }

        // 有多筆 event_details 的活動要指定 id
        $manyDetailActions = [EventActionIdEnum::BET_ONCE_A_DAY->value];
        $legalDetailId = in_array($eventDetail->action_id, $manyDetailActions) ? (int) $eventDetail->id : 0;

        return $this->refreshProcess($eventId, $member->id, (int) $prize->is_repeatedly, $legalDetailId);
    }

    /**
     * choosePrize - 玩家自選獎項後領取（tpower-master 獨有）
     *
     * 遷移自 API_Frontend PrizeController::choosePrize；vw-master 無此方法也無路由。
     * 僅處理 prize_members.content_id = -1（待玩家自選）的待領獎勵。
     */
    public function choosePrize(Member $member, Agent $agent, int $eventId, int $prizeContentId): void
    {
        // 領獎需通過手機驗證
        if ($agent->getSettingValue(AgentCustomizedSettingKeyEnum::EVENT_REWARD_MOBILE_VALIDATION)
            && $member->mobile_validate == 0
        ) {
            throw new ApiException(__('error_message.9007'));
        }

        $this->eventService->assertEventAccessible($eventId, $agent);

        // 查詢待自選的 prize_member，並確認狀態
        $prizeMember = PrizeMember::query()
            ->join('prizes', 'prize_members.prize_id', 'prizes.id')
            ->join('events', function ($join) use ($eventId) {
                $join->on('prizes.event_id', 'events.id')->where('events.id', $eventId);
            })
            ->where('prize_members.member_id', $member->id)
            ->where('prize_members.status', 0)
            ->where('prize_members.content_id', -1)
            ->where(function ($query) {
                $query->where('prize_members.expired_time', '>=', now()->toDateTimeString())
                    ->orWhereNull('prize_members.expired_time');
            })
            ->select(['prize_members.id', 'prize_members.event_detail_id'])
            ->first();

        if (!$prizeMember) {
            throw new ApiException(__('error_message.1949'));
        }

        // 舊版另以 $request->id 查活動（僅供 remark 使用），缺參數時會致命；此處統一用 event_id
        $event = Event::find($eventId);
        $eventDetail = EventDetail::query()
            ->where('event_id', $eventId)
            ->where('id', $prizeMember->event_detail_id)
            ->first();

        if (!$event || !$eventDetail) {
            throw new ApiException(__('error_message.1901'));
        }

        // 限制遊戲商入口（鎖分模式）的玩家不能重複領取
        if ($eventDetail->vendor_lock == 1 && $member->event_mode > 0) {
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

        $prize = Prize::query()
            ->where('event_id', $eventId)
            ->where('status', 1)
            ->where('expire_date', '>=', now()->toDateTimeString())
            ->first();

        if (!$prize) {
            throw new ApiException(__('error_message.1901'));
        }

        // 舊版直接把 $request 傳來的 prize_content_id 當派發依據，可指定任一活動的高額獎項；此處綁回本活動獎項
        $chosen = PrizeContent::query()
            ->where('id', $prizeContentId)
            ->where('prize_id', $prize->id)
            ->where('status', 1)
            ->exists();

        if (!$chosen) {
            throw new ApiException(__('error_message.1901'));
        }

        throw_if(
            ($check = $this->checkMemberFirstNameAndWithdrawAccount($member, $agent)) > 0,
            new ApiException(match ($check) {
                1 => __('error_message.2041'),
                2 => __('error_message.1095'),
                3 => __('error_message.2040'),
                default => __('error_message.2001'),
            })
        );

        // 檢查重複 IP、手機、真實姓名
        $this->assertNotBlocked($eventDetail, $prize, (int) $prizeMember->id, $member);

        $remark = json_encode([
            'event_id' => $event->id,
            'title' => $event->title,
            'action_id' => $eventDetail->action_id,
            'point' => $eventDetail->point,
        ]);

        DB::transaction(function () use ($member, $agent, $eventDetail, $prizeMember, $prizeContentId, $remark) {
            $this->addRewardClaimRecord($member, $eventDetail);

            // 舊版無鎖也不重驗狀態，同一筆待領獎勵併發自選時會重複派彩
            $row = PrizeMember::query()
                ->where('id', $prizeMember->id)
                ->where('status', 0)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                throw new ApiException(__('error_message.1949'));
            }

            $row->update(['content_id' => $prizeContentId]);

            $this->sendPrize($row, $prizeContentId, $member, $agent, $eventDetail, (string) $remark);
        });
    }

    /**
     * 重複性檢查；命中即拋出對應錯誤
     */
    private function assertNotBlocked(EventDetail $eventDetail, Prize $prize, int $prizeMemberId, Member $member): void
    {
        if (!$this->checkPrizeBlock($eventDetail, $prize, $prizeMemberId, $member)) {
            return;
        }

        $blockData = EventPayoutCancel::query()
            ->where('event_id', $eventDetail->event_id)
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

        throw new ApiException(__($this->resolveBlockErrorMessageKey((int) $blockData->type)));
    }

    /**
     * event_payout_cancels.type 對應的錯誤訊息代碼，供 assertNotBlocked／drawAllPrizes 共用。
     */
    private function resolveBlockErrorMessageKey(int $type): string
    {
        return match ($type) {
            1, 7 => 'error_message.1952',  // 真實姓名 / 全站真實姓名
            2, 4, 8 => 'error_message.1950',  // 註冊 IP 或領獎 IP
            3, 9 => 'error_message.1951',  // 手機號碼
            default => 'error_message.1949',
        };
    }

    /**
     * 註冊送（action 11）：實名認證通過時間須不晚於 members.created_at + event_details.expire_hour，否則取消。
     *
     * 非註冊送、或 expire_hour 未設定（<=0）時不套用此規則，直接放行。
     * 領取本身沒有時間限制，只看「還有沒有機會在期限內通過」：
     * 尚未通過實名認證、但期限未到 → 擋下領取但不取消（屆時通過再依時間點判斷）；
     * 通過時間晚於期限，或期限已過仍未通過（已不可能再趕上）→ 取消獎勵（prize_members.status = 2）。
     */
    private function assertRegBonusRealnameVerifiedInTime(Member $member, EventDetail $eventDetail, PrizeMember $prizeMember): void
    {
        if ($eventDetail->action_id != EventActionIdEnum::REG_BONUS->value || $eventDetail->expire_hour <= 0) {
            return;
        }

        $deadline = Carbon::parse($member->created_at)->addHours((int) $eventDetail->expire_hour);
        $verifiedAt = RealnameValidationService::getEarliestVerifiedAt($member);

        if ($verifiedAt === null && now()->lessThanOrEqualTo($deadline)) {
            // 尚未通過實名認證，但期限未到，仍有機會通過，先擋下領取
            throw new ApiException(__('error_message.2047'));
        }

        if ($verifiedAt !== null && $verifiedAt->lessThanOrEqualTo($deadline)) {
            // 通過時間在期限內
            return;
        }

        // 已不可能在期限內通過（通過時間晚於期限，或期限已過仍未通過），取消獎勵
        $prizeMember->update(['status' => self::PRIZE_MEMBER_STATUS_CANCELLED]);
        throw new ApiException(__('error_message.1953'));
    }

    /**
     * 領獎重複性檢查（IP / 手機 / 真實姓名 / 全站真實姓名 / Telegram），命中即寫入 event_payout_cancels
     *
     * 遷移自 API_Frontend PrizeController::checkPrizeBlock。
     */
    public function checkPrizeBlock(EventDetail $eventDetail, Prize $prize, int $prizeMemberId, Member $member): bool
    {
        // 已經判定過，代表一定有重複
        $judged = EventPayoutCancel::query()
            ->where('event_id', $eventDetail->event_id)
            ->where('member_id', $member->id)
            ->where('prize_member_id', $prizeMemberId)
            ->exists();

        if ($judged) {
            return true;
        }

        $isBlock = false;
        $blockType = 0;
        $blockMemberId = 0;
        $remark = '';
        $otherWords = '';

        // 每日充（55）只檢查今日（UTC+8）之後的紀錄
        $todayStart = now()->toFrontendTime()->startOfDay()->toSystemTime()->toDateTimeString();

        $claimedMemberIds = PrizeMember::query()
            ->where('prize_id', $prize->id)
            ->where('event_detail_id', $eventDetail->id)
            ->when(
                $eventDetail->action_id == EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
                fn ($query) => $query->where('created_at', '>=', $todayStart)
            )
            ->where('status', 1)
            ->where('member_id', '!=', $member->id)
            ->pluck('member_id')
            ->toArray();

        if ($eventDetail->ip_block == 1) {
            if (!$this->filled($member->register_ip)) {
                $isBlock = true;
                $blockType = 2;
                $remark = '無註冊IP';
            } else {
                $loginIp = explode(', ', Common::getIp())[0];
                $sameClaimIp = PrizeMember::query()
                    ->where('prize_id', $prize->id)
                    ->where('event_detail_id', $eventDetail->id)
                    ->where('ip', $loginIp)
                    ->where('member_id', '!=', $member->id)
                    ->where('status', 1)
                    ->first();

                if ($sameClaimIp) {
                    $isBlock = true;
                    $blockMemberId = (int) $sameClaimIp->member_id;
                    $blockType = 4;
                    $remark = '領取時領獎IP:【' . $loginIp . '】';
                }

                if (!$isBlock) {
                    $sameRegisterIp = Member::whereIn('id', $claimedMemberIds)
                        ->where('register_ip', $member->register_ip)
                        ->first();
                    if ($sameRegisterIp) {
                        $isBlock = true;
                        $blockMemberId = (int) $sameRegisterIp->id;
                        $blockType = 2;
                        $remark = '領取時註冊IP:【' . $member->register_ip . '】';
                    }
                }
            }
        }

        if ($eventDetail->phone_block == 1 && !$isBlock) {
            if (!$this->filled($member->mobile)) {
                $isBlock = true;
                $blockType = 3;
                $remark = '無手機號碼';
            } else {
                $sameMobile = Member::whereIn('id', $claimedMemberIds)->where('mobile', $member->mobile)->first();
                if ($sameMobile) {
                    $isBlock = true;
                    $blockMemberId = (int) $sameMobile->id;
                    $blockType = 3;
                    $remark = '領取時手機號碼:【' . $member->mobile . '】';
                } else {
                    // 手機號碼可以更改，所以要再檢查領取當下記錄的手機號碼
                    $claimed = DB::table('reward_claim_records')
                        ->where('event_detail_id', $eventDetail->id)
                        ->where('type', 3)
                        ->where('member_id', '!=', $member->id)
                        ->where('value', $member->mobile)
                        ->when($this->isDailyAction($eventDetail), function ($query) {
                            $query->whereRaw('DATE(DATE_ADD(reward_claim_records.created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))');
                        })
                        ->first();

                    if ($claimed) {
                        $otherWords = '領取時';
                        $isBlock = true;
                        $blockMemberId = (int) $claimed->member_id;
                        $blockType = 3;
                        $remark = '領取時手機號碼:【' . $member->mobile . '】';
                    }
                }
            }
        }

        if ($eventDetail->account_block == 1 && !$isBlock) {
            if (!$this->filled($member->first_name)) {
                $isBlock = true;
                $blockType = 1;
                $remark = '無真實姓名';
            } else {
                $sameName = Member::whereIn('id', $claimedMemberIds)->where('first_name', $member->first_name)->first();
                if ($sameName) {
                    $isBlock = true;
                    $blockMemberId = (int) $sameName->id;
                    $blockType = 1;
                    $remark = '領取時真實姓名:【' . $member->first_name . '】';
                }
            }
        }

        if ($eventDetail->global_account_block == 1 && !$isBlock) {
            if (!$this->filled($member->first_name)) {
                $isBlock = true;
                $blockType = 1;
                $remark = '無真實姓名';
            } else {
                $sameNameGlobally = Member::where('first_name', $member->first_name)
                    ->where('id', '!=', $member->id)
                    ->first();
                if ($sameNameGlobally) {
                    $isBlock = true;
                    $blockMemberId = (int) $sameNameGlobally->id;
                    $blockType = 7;
                    $remark = '領取時全站真實姓名:【' . $member->first_name . '】';
                }
            }
        }

        // 沿用舊版：tgid 檢查不看前面是否已命中
        if ($eventDetail->tgid_block == 1) {
            $rel = DB::table('member_telegram_rels')->where('member_id', $member->id)->first();
            if ($rel) {
                $sameTgId = DB::table('member_telegram_rels')
                    ->whereIn('member_id', $claimedMemberIds)
                    ->where('tg_user_id', $rel->tg_user_id)
                    ->first();
                if ($sameTgId) {
                    $isBlock = true;
                    // 舊版誤取 member_telegram_rels.id 當會員 id
                    $blockMemberId = (int) $sameTgId->member_id;
                    $blockType = 6;
                    $remark = '領取時Telegram User ID:【' . $rel->tg_user_id . '】';
                }
            }
        }

        if (!$isBlock) {
            return false;
        }

        if ($blockMemberId > 0) {
            // 舊版直接取 ->username，查無會員時會致命
            $blockUsername = Member::where('id', $blockMemberId)->value('username');
            if ($blockUsername) {
                $remark .= '與會員【' . $blockUsername . '】' . $otherWords . '重複';
            }
        }

        EventPayoutCancel::create([
            'event_id' => $eventDetail->event_id,
            'prize_member_id' => $prizeMemberId,
            'from_member_id' => $member->id,
            'member_id' => $member->id,
            'type' => $blockType,
            'block_member_id' => $blockMemberId,
            'remark' => $remark,
        ]);

        return true;
    }

    /**
     * 寫入領獎識別紀錄（真實姓名 / 註冊IP / 領獎IP / 手機 / Telegram）
     *
     * 遷移自 API_Frontend PrizeController::addRewardClaimRecord。
     */
    public function addRewardClaimRecord(Member $member, EventDetail $eventDetail): void
    {
        $loginIp = explode(', ', Common::getIp())[0];

        $values = [
            1 => $member->first_name,
            2 => $member->register_ip,
            4 => $loginIp,
            3 => $member->mobile,
        ];

        $rel = DB::table('member_telegram_rels')->where('member_id', $member->id)->first();
        if ($rel && !is_null($rel->tg_user_id)) {
            $values[5] = $rel->tg_user_id;
        }

        foreach ($values as $type => $value) {
            if (is_null($value)) {
                continue;
            }

            $exists = DB::table('reward_claim_records')
                ->where('event_detail_id', $eventDetail->id)
                ->where('type', $type)
                ->where('value', $value)
                ->when($this->isDailyAction($eventDetail), function ($query) {
                    $query->whereRaw('DATE(DATE_ADD(reward_claim_records.created_at, INTERVAL 8 HOUR)) = DATE(DATE_ADD(NOW(), INTERVAL 8 HOUR))');
                })
                ->exists();

            if ($exists) {
                continue;
            }

            RewardClaimRecord::create([
                'member_id' => $member->id,
                'event_detail_id' => $eventDetail->id,
                'type' => $type,
                'value' => $value,
            ]);
        }
    }

    /**
     * 是否已設定真實姓名與提款帳戶
     *
     * 遷移自 API_Frontend PrizeController::checkMemberFirstNameAndWithdrawAccount。
     *
     * @return int 0: 皆有, 1: 兩者皆無, 2: 缺真實姓名, 3: 缺提款帳戶
     */
    public function checkMemberFirstNameAndWithdrawAccount(Member $member, Agent $agent): int
    {
        $noFirstName = !$this->filled($member->first_name) || trim((string) $member->first_name) === '';

        $noWithdrawAccount = false;
        if ($agent->getSettingValue(AgentCustomizedSettingKeyEnum::EVENT_NEED_BANK_ACCOUNT)) {
            $noWithdrawAccount = !MemberBankAccount::where('member_id', $member->id)->where('status', 1)->exists();
        }

        return match (true) {
            $noFirstName && $noWithdrawAccount => 1,
            $noFirstName => 2,
            $noWithdrawAccount => 3,
            default => 0,
        };
    }

    /**
     * 每日型活動（55 每日領取一次、56 每日領取一次自動）只比對當日紀錄
     */
    private function isDailyAction(EventDetail $eventDetail): bool
    {
        return in_array($eventDetail->action_id, [
            EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
            EventActionIdEnum::PICK_UP_ONCE_A_DAY_AUTO->value,
        ]);
    }

    private function filled(int|string|null $value): bool
    {
        return $value !== null && $value !== '';
    }

    /**
     * 派發活動獎勵（平台點數 / 禮碼 / 優惠錢包 / 遊戲錢包）並寫入稽核流水
     *
     * 遷移自 API_Frontend PrizeController::sendPrize，取 tpower-master 與 vw-master 的聯集。
     *
     * @return array{content_point: string, content_point_type: int}
     */
    public function sendPrize(PrizeMember $prizeMember, int $contentId, Member $member, Agent $agent, object $details, string $remark): array
    {
        if ($prizeMember->status != 0) {
            return ['content_point' => '0', 'content_point_type' => 0];
        }

        $loginIp = explode(', ', Common::getIp())[0];
        $content = PrizeContent::find($contentId);

        // 舊版無此檢查，$content 為 null 時會在下一行讀 null 屬性致命
        if (!$content) {
            $prizeMember->update(['ip' => $loginIp, 'status' => 1]);

            return ['content_point' => '0', 'content_point_type' => 0];
        }

        // 最後一道防線：獎項內容必須屬於這筆待領獎勵的獎項，避免任何呼叫端把外部 content_id 帶進來派彩
        if ((int) $content->prize_id !== (int) $prizeMember->prize_id) {
            throw new ApiException(__('error_message.1901'));
        }

        $prize = Prize::find($content->prize_id);
        if (!$prize) {
            throw new ApiException(__('error_message.1901'));
        }

        $info = json_decode((string) $prize->info);
        $flowLimitMultiple = (string) ($content->flow_limit_multiple ?? 0);
        $includePrincipal = (int) ($info->flow_limit_include_principal ?? 0);
        // vw 獨有：流水限制的遊戲商 / 遊戲分類（tpower 走 prize_content_flow_limits 表）
        $flowLimitVendorIds = (array) ($info->flow_limit_vendor_id ?? []);
        $flowLimitGameTypes = (array) ($info->flow_limit_game_type ?? []);

        $type = TransactionEnum::ACTIVITY_AWARD->value;
        $transactionLogId = 0;
        $walletTransactionLogId = 0;
        $promotionWalletId = 0;
        $depositPoint = '0';

        // 需套用 point_percentage 的 action_id：首充、次次充、每日充、周期充值、每日連續儲值
        $depositPercentageActions = [
            EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value,
            EventActionIdEnum::DEPOSIT_MANUAL->value,
            EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
            EventActionIdEnum::DEPOSIT_CYCLE_TIME->value,
            EventActionIdEnum::DAILY_CONSECUTIVE_DEPOSIT->value,
        ];

        $lotteryPoint = Common::numAdd('0', (string) $prizeMember->lottery_point, 4);

        if (in_array($details->action_id, $depositPercentageActions)) {
            $depositPoint = $lotteryPoint;
            $lotteryPoint = Common::numMul($lotteryPoint, (string) $content->point_percentage, 4);
        } elseif ($details->action_id == EventActionIdEnum::WEEKLY_RESCUE_FUND->value) {
            // 每周救援金（tpower 獨有）
            $lotteryPoint = Common::numMul($lotteryPoint, (string) $content->point_percentage, 4);
            $depositPoint = $lotteryPoint;
        } elseif ($details->action_id == EventActionIdEnum::TPOWER_INVITE_PLAYER->value) {
            // 邀請玩家活動（tpower 獨有）：綁定本次使用掉的邀請紀錄
            $invited = $this->eventService->getInvitedMembers($member, (int) $details->event_id);
            $this->eventService->updateInviteRecord($invited->pluck('id')->all(), (int) $prizeMember->id);
        } else {
            // 預設活動獎勵不是儲值活動，流水不計入本金
            $includePrincipal = 0;
        }

        $maxPoint = (string) $prize->max_point;
        if (Common::numCompare($maxPoint, '0', 4) !== 0 && Common::numCompare($lotteryPoint, $maxPoint, 4) > 0) {
            $lotteryPoint = $maxPoint;
        }

        // 判斷獎勵金額是用 %數還是固定金額
        $contentPoint = $content->type == 1 ? $lotteryPoint : (string) $content->point;

        // 隨機獎勵（tpower 獨有）
        if ($content->type == 0 && !empty($content->random_point_max) && $content->random_point_max > $content->point) {
            $contentPoint = (string) rand((int) $content->point, (int) $content->random_point_max);
        }

        $prizeMember->update(['status' => 1, 'lottery_point' => $contentPoint, 'ip' => $loginIp]);

        // 判斷是否需要實名認證
        $agentSettings = $this->agentService->getAgentSettings($agent);
        if (!RealnameValidationService::isEventRewardRealnameValidated($agentSettings, $member)) {
            throw new ApiException(__('error_message.realname_invalid.event_reward'));
        }

        if (Common::numCompare($contentPoint, '0', 4) > 0) {
            match ((int) $content->point_type) {
                self::POINT_TYPE_ROLLING_CHIP => $transactionLogId = $this->creditRollingChip($member, $content, $details, $contentPoint),
                self::POINT_TYPE_PROMOTION_WALLET => $promotionWalletId = $this->creditPromotionWallet($member, $prize, $contentPoint),
                default => [$transactionLogId, $walletTransactionLogId] = $this->creditPlatformOrGameWallet(
                    $member,
                    $agent,
                    $prizeMember,
                    $contentPoint,
                    $flowLimitMultiple,
                    $type,
                    $remark,
                ),
            };

            // 增加稽核流水 log
            if (Common::numCompare($flowLimitMultiple, '0', 4) > 0) {
                if ($details->vendor_lock == 1) {
                    // 遊戲商鎖定要額外 type，用來清空流水後解鎖
                    $type = FlowLimitTypeEnum::EVENT_LOCK_MODE->value;
                    $member->update(['event_mode' => $contentId]);
                }

                $flowLimit = FlowLimit::create([
                    'member_id' => $member->id,
                    // 優惠錢包的 type 要改成 104（vw 獨有；tpower 此處會用到未定義的 $transaction_log_id）
                    'type' => $content->point_type == self::POINT_TYPE_PROMOTION_WALLET
                        ? TransactionEnum::PROMOTION_WALLET_REWARD->value
                        : $type,
                    'event_id' => $prize->event_id,
                    'reference_id' => $content->point_type == self::POINT_TYPE_PROMOTION_WALLET
                        ? $promotionWalletId
                        : $transactionLogId,
                    'wallet_transaction_log_id' => $walletTransactionLogId,
                    'flow_limit' => $this->calculateFlowLimit($contentPoint, $depositPoint, $flowLimitMultiple, $includePrincipal),
                    'reach' => 0,
                    'status' => 0,
                    'admin' => 0,
                ]);

                $this->createFlowLimitRestrictions($flowLimit->id, $contentId, $flowLimitVendorIds, $flowLimitGameTypes);
            }

            // 如果 cancel_other_reward 是 1，則取消其他同訂單活動獎勵
            if ($prize->cancel_other_reward == 1) {
                $this->cancelOtherRewardsOfSameOrder($prizeMember);
            }
        }

        return [
            'content_point' => $contentPoint,
            'content_point_type' => (int) $content->point_type,
        ];
    }

    /**
     * 稽核流水金額
     *
     * `flow_limit_include_principal = 1` 時以「本金＋獎金」計算倍數後再扣回本金。
     * 舊版 tpower 另以 `example.invalid.deduct_principal` 控制是否扣回（缺欄位預設不扣），
     * 但該欄位後台已不再寫入，且實測 tpower / vworld / me77 現有資料一律扣的結果
     * 與各自舊版完全相同，故統一為一律扣（見遷移文件 2-8）。
     */
    private function calculateFlowLimit(string $contentPoint, string $depositPoint, string $multiple, int $includePrincipal): string
    {
        if ($includePrincipal !== 1) {
            return Common::numMul($contentPoint, $multiple, 4);
        }

        $total = Common::numMul(Common::numAdd($depositPoint, $contentPoint, 4), $multiple, 4);

        return Common::numSub($total, $depositPoint, 4);
    }

    /**
     * 派發禮碼
     */
    private function creditRollingChip(Member $member, PrizeContent $content, object $details, string $contentPoint): int
    {
        // 禮碼照舊，用獎勵名稱當備註
        $type = match ((int) $details->action_id) {
            EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value => RollingChipLogTypeEnum::FIRST_DEPOSIT_REWARD,
            EventActionIdEnum::DEPOSIT_MANUAL->value => RollingChipLogTypeEnum::DEPOSIT_REWARD,
            EventActionIdEnum::PICK_UP_ONCE_A_DAY->value => RollingChipLogTypeEnum::DEPOSIT_ONCE_A_DAY_REWARD,
            EventActionIdEnum::INVITE_PLAYER_OFFERS->value => RollingChipLogTypeEnum::INVITE_PLAYER_REWARD,
            EventActionIdEnum::SLOT_LOSE_REBATE->value => RollingChipLogTypeEnum::SLOT_LOSE_REBATE,
            // 周期充值活動返利（tpower 獨有）
            EventActionIdEnum::DEPOSIT_CYCLE_TIME->value => RollingChipLogTypeEnum::DEPOSIT_CYCLE_TIME_REWARD,
            default => RollingChipLogTypeEnum::BET_EVENT_REWARD,
        };

        $wallet = $this->walletService->getLockedWallet($member->id, WalletEnum::CODE_ROLLING_CHIP);
        $beforeBalance = Common::numAdd('0', (string) $wallet->balance, 4);
        $afterBalance = Common::numAdd($beforeBalance, $contentPoint, 4);
        $wallet->update(['balance' => $afterBalance]);

        return (int) RollingChipLog::create([
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'type' => $type->value,
            'amount' => $contentPoint,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => $content->name,
            'admin' => 0,
        ])->id;
    }

    /**
     * 派發優惠錢包
     */
    private function creditPromotionWallet(Member $member, Prize $prize, string $contentPoint): int
    {
        $wallet = PromotionWallet::query()
            ->where('member_id', $member->id)
            ->where('event_id', $prize->event_id)
            ->where('status', 1)
            ->lockForUpdate()
            ->first();

        if ($wallet) {
            $beforeBalance = Common::numAdd('0', (string) $wallet->balance, 4);
            $afterBalance = Common::numAdd($beforeBalance, $contentPoint, 4);
            $wallet->update(['balance' => $afterBalance]);
        } else {
            // 沒有已鎖定的優惠錢包時，要先檢查是否與現有優惠錢包的遊戲商衝突
            $eventVendorIds = DB::table('event_promotions')
                ->where('event_id', $prize->event_id)
                ->pluck('vendor_id')
                ->toArray();

            $conflicts = DB::table('promotion_wallets as pw')
                ->join('event_promotions as ep', 'pw.event_id', 'ep.event_id')
                ->where('pw.member_id', $member->id)
                ->where('pw.status', 1)
                ->whereIn('ep.vendor_id', $eventVendorIds)
                ->exists();

            if ($conflicts) {
                throw new ApiException(__('error_message.2042'));
            }

            $beforeBalance = '0';
            $afterBalance = $contentPoint;
            $wallet = PromotionWallet::create([
                'parent' => $member->parent,
                'member_id' => $member->id,
                'event_id' => $prize->event_id,
                'balance' => $contentPoint,
                'status' => 1,
                'admin' => 0,
            ]);
        }

        PromotionWalletLog::create([
            'transaction_no' => '',
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'event_id' => $prize->event_id,
            'promotion_wallet_id' => $wallet->id,
            'type' => self::PROMOTION_WALLET_LOG_TYPE_EVENT_REWARD,
            'vendor_id' => 0,
            // 舊版靠 MySQL 非嚴格模式補這四欄的隱含預設值，apis 為嚴格模式必須明寫
            // （查 vworld 正式資料：活動獎勵的 44 筆全為 order_id=0、其餘三欄空字串）
            'order_id' => 0,
            'remark' => '',
            'result' => '',
            'operator' => '',
            'point' => $contentPoint,
            'vendor_point' => 0,
            'rate' => 1,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
        ]);

        return (int) $wallet->id;
    }

    /**
     * 派發平台點數；多錢包且有稽核流水時改派遊戲錢包（vw 獨有）
     *
     * @return array{0: int, 1: int} [transaction_log_id, wallet_transaction_log_id]
     */
    private function creditPlatformOrGameWallet(
        Member $member,
        Agent $agent,
        PrizeMember $prizeMember,
        string $contentPoint,
        string $flowLimitMultiple,
        int $type,
        string $remark,
    ): array {
        if ($agent->is_multi_wallet && Common::numCompare($flowLimitMultiple, '0', 4) > 0) {
            // type=2 已廢除（決議⑬）只查不建；多錢包為死路徑，查無存量即顯性中止
            $wallet = $this->walletService->getLockedWallet($member->id, WalletEnum::CODE_GAME)
                ?? throw new \LogicException("type=2 遊戲錢包已廢除且查無存量，member_id={$member->id}");
            $beforeBalance = Common::numAdd('0', (string) $wallet->balance, 4);
            $afterBalance = Common::numAdd($beforeBalance, $contentPoint, 4);
            $wallet->update(['balance' => $afterBalance]);

            $walletLogId = (int) WalletTransactionLog::create([
                'transaction_no' => $prizeMember->id,
                'wallet_type_id' => WalletEnum::CODE_GAME->value,
                'agent_id' => $member->parent,
                'member_id' => $member->id,
                'point' => $contentPoint,
                'before_balance' => $beforeBalance,
                'balance' => $afterBalance,
                'type' => WalletTransactionEnum::GAME_WALLET_EVENT_REWARD->value,
                'operator' => 'system',
                'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                'admin' => 0,
            ])->id;

            return [0, $walletLogId];
        }

        $lockedMember = Member::lockForUpdate()->find($member->id);
        $beforeBalance = Common::numAdd('0', (string) $lockedMember->balance, 4);
        $afterBalance = Common::numAdd($beforeBalance, $contentPoint, 4);
        $lockedMember->update(['balance' => $afterBalance]);
        $member->balance = $afterBalance;

        $logId = (int) TransactionLog::create([
            'transaction_no' => $prizeMember->id,
            'agent_id' => $member->parent,
            'member_id' => $member->id,
            'type' => $type,
            'rate' => 1,
            'point' => $contentPoint,
            'before_balance' => $beforeBalance,
            'balance' => $afterBalance,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => $remark,
            'operator' => 'system',
            'admin' => 0,
        ])->id;

        return [$logId, 0];
    }

    /**
     * 流水限制的遊戲商 / 遊戲分類；tpower 走 prize_content_flow_limits 表，vw 走 example.invalid
     */
    private function createFlowLimitRestrictions(int $flowLimitId, int $contentId, array $vendorIds, array $gameTypes): void
    {
        $rows = DB::table('prize_content_flow_limits')->where('prize_content_id', $contentId)->get();
        foreach ($rows as $row) {
            FlowLimitRestriction::create([
                'flow_limit_id' => $flowLimitId,
                'vendor_id' => $row->vendor_id,
                'type_id' => $row->game_type_id,
            ]);
        }

        foreach (array_values($vendorIds) as $i => $vendorId) {
            $gameType = (int) ($gameTypes[$i] ?? 0);
            if ((int) $vendorId <= 0 && $gameType <= 0) {
                continue;
            }

            FlowLimitRestriction::create([
                'flow_limit_id' => $flowLimitId,
                'vendor_id' => (int) $vendorId,
                'type_id' => $gameType,
            ]);
        }
    }

    /**
     * 取消同一張訂單下的其他活動獎勵
     */
    private function cancelOtherRewardsOfSameOrder(PrizeMember $prizeMember): void
    {
        $orderId = DB::table('prize_member_orders')
            ->where('prize_member_id', $prizeMember->id)
            ->value('order_id');

        if (!$orderId) {
            return;
        }

        $otherIds = DB::table('prize_member_orders')
            ->where('order_id', $orderId)
            ->where('prize_member_id', '!=', $prizeMember->id)
            ->pluck('prize_member_id')
            ->toArray();

        if (empty($otherIds)) {
            return;
        }

        PrizeMember::whereIn('id', $otherIds)->where('status', 0)->update(['status' => 2]);
    }

    /**
     * 檢查是否合法領取，並刷新進度條
     *
     * 遷移自 API_Frontend PrizeController::refreshProcess（兩分支完全相同）。
     */
    public function refreshProcess(int $eventId, int $memberId, int $isRepeatedly, int $detailId = 0): bool
    {
        $rows = DB::table('event_details as d')
            ->leftJoin('event_processes as p', 'p.detail_id', 'd.id')
            ->where('d.event_id', $eventId)
            ->when($detailId > 0, fn ($query) => $query->where('d.id', $detailId))
            ->where('d.status', 1)
            ->where('p.member_id', $memberId)
            ->select(['p.id', 'd.point'])
            ->get();

        // 沒有紀錄也是 false
        if ($rows->isEmpty()) {
            return false;
        }

        foreach ($rows as $row) {
            $process = EventProcess::find($row->id);
            // 檢查是否有進度未到卻領取的人
            if (Common::numCompare((string) $process->point, Common::numAdd((string) $process->received, (string) $row->point, 4), 4) < 0) {
                return false;
            }
            // 可重複領取的要刷新進度條
            if ($isRepeatedly == 1) {
                $process->increment('received', $row->point);
                break;
            }
        }

        return true;
    }

    private function claimedQuery(Member $member, int $languageId): Builder
    {
        return DB::table('prize_members as m')
            ->join('prize_contents as c', 'c.id', 'm.content_id')
            ->join('prizes as p', 'p.id', 'c.prize_id')
            ->join('events as e', 'e.id', 'p.event_id')
            ->leftJoin('event_names as n', function ($join) use ($languageId) {
                $join->on('n.event_id', 'e.id')->where('n.language_id', $languageId);
            })
            ->where('m.member_id', $member->id)
            ->where('m.status', 1);
    }
}
