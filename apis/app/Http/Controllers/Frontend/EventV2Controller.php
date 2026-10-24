<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\General\HttpCode;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\WalletEnum;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\EventV2\ClaimRecordsRequest;
use App\Http\Requests\Frontend\EventV2\DetailRequest;
use App\Http\Requests\Frontend\EventV2\JoinRequest;
use App\Http\Requests\Frontend\EventV2\ListRequest;
use App\Http\Resources\Frontend\EventV2\EventClaimRecordsResource;
use App\Http\Resources\Frontend\EventV2\EventListResource;
use App\Http\Resources\Frontend\EventV2\EventV2DetailResource;
use App\Http\Resources\Frontend\EventV2\EventV2JoinDetailResource;
use App\Http\Resources\Frontend\EventV2\EventV2JoinResource;
use App\Http\Resources\Frontend\EventV2\EventV2WithdrawResource;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\TotalBetRecord;
use App\Services\AgentService;
use App\Services\EventService;
use App\Services\EventV2\EventV2Service;
use App\Services\Frontend\EventInvite\EventInviteService;
use App\Services\Frontend\EventProgressiveJackpot\EventProgressiveJackpotService;
use App\Services\NewbieChallengeService;
use App\Services\WalletService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Group('EventV2 (活動 V2)')]
class EventV2Controller extends Controller
{
    public const int DEFAULT_LANGUAGE_id = 1;

    public const array BONUS_NOT_SHOW = [
        VendorIdEnum::PGSSW->value,
    ];

    public function __construct(
        protected EventService $eventService,
        protected EventV2Service $eventV2Service,
        protected EventProgressiveJackpotService $eventProgressiveJackpotService,
        protected NewbieChallengeService $newbieChallengeService,
        protected EventInviteService $eventInviteService,
        protected WalletService $walletService,
        protected AgentService $agentService,
    ) {}

    /**
     * 活動列表
     */
    public function list(ListRequest $request)
    {
        $userData = $this->getUserContext();
        $eventV1List = $this->eventService->getEventList($userData);
        $eventV2List = $this->eventV2Service->getEventV2List($userData);
        $newbieChallengeList = $this->newbieChallengeService->getEventListItems($userData);
        $eventInviteList = $this->eventInviteService->getEventInviteList($userData);
        $eventProgressiveJackpotList = $this->eventProgressiveJackpotService->getEventListItems($userData);

        $data = $eventV1List->concat($eventV2List)->concat($newbieChallengeList)->concat($eventInviteList)->concat($eventProgressiveJackpotList)->sortBy([
            ['sort', 'desc'],
            ['created_at', 'desc'],
        ]);

        return ApiResponse::success(EventListResource::collection($data));
    }

    /**
     * 領獎紀錄
     */
    public function claimRecords(ClaimRecordsRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $languageId = $userData->languageId;
        $yearMonth = $request->input('year_month', Carbon::now()->toFrontendTime()->format('Y-m'));

        $start = Carbon::parseFrontend($yearMonth . '-01')->startOfMonth()->toDateTimeString();
        $end = Carbon::parseFrontend($yearMonth . '-01')->endOfMonth()->toDateTimeString();

        $data = $this->eventService->getClaimRecords(
            member: $member,
            start: $start,
            end: $end,
            languageId: $languageId
        );

        return ApiResponse::success(EventClaimRecordsResource::collection($data));
    }

    /**
     * 活動 v2 詳情
     */
    public function detail(DetailRequest $request)
    {
        try {
            $languageId = $this->getUserContext()->languageId;
            $member = $this->getUserContext()->member;

            $eventClass = $this->eventV2Service->getEventClass((int) $request->event_detail_id);

            // 驗證活動是否能參加
            $validateResult = $eventClass->validate((int) $request->event_detail_id, $member);
            $validate = $validateResult->isSuccess();

            // 只有阻擋相關的錯誤才要記錄，未達成活動條件的錯誤不用紀錄
            if ($validate === false && $validateResult->isBlockFailed()) {
                $validateResult->createRecord((int) $request->event_detail_id, $member);
            }

            $message = $validateResult->translate();

            $eventDetail = EventV2Detail::select(['id', 'join_withdraw', 'end_time'])
                ->with([
                    'detailLang' => function ($query) use ($languageId) {
                        $query->whereIn('language_id', [self::DEFAULT_LANGUAGE_id, $languageId]);
                    },
                ])
                ->where('event_v2_details.id', $request->event_detail_id)
                ->first();

            // 若語系沒設定,預設抓英文
            $detailLang = $eventDetail->detailLang->where('language_id', $languageId)->isNotEmpty() ?
                $eventDetail->detailLang->where('language_id', $languageId)->first() :
                $eventDetail->detailLang->where('language_id', self::DEFAULT_LANGUAGE_id)->first();

            $eventDetail->content = $detailLang?->content;

            $bonusCode = EventV2UserJoin::select(['event_v2_bonus_codes.bonus_code', 'event_v2_bonus_codes.created_at'])
                ->join('event_v2_bonus_codes', 'event_v2_user_joins.id', '=', 'event_v2_bonus_codes.event_v2_user_join_id')
                ->where('event_v2_user_joins.member_id', $member->id)
                ->where('event_v2_user_joins.event_v2_detail_id', $request->event_detail_id)
                // 不需要顯示 bonus code 的遊戲商
                ->whereNotIn('vendor_id', self::BONUS_NOT_SHOW)
                ->get();

            return ApiResponse::success(EventV2DetailResource::make([
                'content' => $eventDetail->content,
                'join_withdraw' => $eventDetail->join_withdraw === 1,
                'validate' => $validate,
                'message' => $message,
                'bonus_code' => $bonusCode,
                'end_time' => $eventDetail->end_time,
            ]));
        } catch (\Exception $exception) {
            throw $exception;
        }
    }

    /**
     * 活動 v2 參加
     */
    public function join(JoinRequest $request)
    {
        $member = $this->getUserContext()->member;
        $eventDetailId = (int) $request->event_detail_id;

        // 用同一把 "event_v2:validate:{member_id}" 鎖包住 check + join/withdraw + commit
        // 全程，避免併發請求各自通過驗證後才各自建立參加紀錄造成重複參加。
        return $this->withLock(
            key: "event_v2:validate:{$member->id}",
            ttl: 10,
            waitSeconds: 3,
            callback: function () use ($eventDetailId, $member) {
                return DB::transaction(function () use ($eventDetailId, $member) {
                    $eventClass = $this->eventV2Service->getEventClass($eventDetailId);
                    $validateResult = $eventClass->validate($eventDetailId, $member);
                    if ($validateResult->isFailed()) {
                        return ApiResponse::error($validateResult->translate());
                    }

                    $wallet = $this->walletService->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);
                    $eventDetail = EventV2Detail::where('id', $eventDetailId)->first();
                    $userJoin = $eventClass->join($eventDetail, $member, $wallet, $validateResult);

                    // 積分型在 join() 內已直接發放並 COMPLETE（target/tickets#4231），沒有領取步驟可走
                    if ($eventDetail->join_withdraw == 1 && (int) $userJoin->status === EventV2UserJoinStatusEnum::JOIN->value) {
                        $eventClass->withdraw($userJoin, $member, $wallet);
                    }

                    $bonusVendorId = $eventDetail->bonus_vendor_id;
                    $bonusCode = ($bonusVendorId !== null && !in_array($bonusVendorId, self::BONUS_NOT_SHOW))
                        ? ($userJoin->eventV2BonusCode?->bonus_code ?? null)
                        : null;

                    return ApiResponse::success(EventV2JoinResource::make([
                        'bonus_code' => $bonusCode,
                        'redirect_path' => $eventDetail->redirect_path,
                        // 紅包畫面要秀「抽到多少」（target/tickets#4234）；其他類型就是獎勵金額
                        'reward' => Common::numAdd('0', (string) $userJoin->reward, 4),
                        'join_withdraw' => (int) $eventDetail->join_withdraw === 1,
                    ]));
                });
            },
        );
    }

    /**
     * 活動 v2 提領
     */
    public function withdraw()
    {
        try {
            return DB::transaction(function () {
                $member = $this->getUserContext()->member;
                $agent = $this->getUserContext()->agent;

                // 取得代理組別設置
                $agentSettings = $this->agentService->getAgentSettings($agent);
                // 檢查是否需要綁定手機
                if ($agentSettings[AgentCustomizedSettingKeyEnum::WITHDRAWAL_HAS_MOBILE_VALIDATE] == 1) {
                    if ($member->mobile_validate != 1 || $member->mobile == '') {
                        // 要求前端轉跳手機綁定頁
                        return ApiResponse::error(
                            message: __('error_message.9007'),
                            code: HttpCode::HTTP_REDIRECT_SET_MOBILE->value,
                        );
                    }
                }

                $userJoin = EventV2UserJoin::select(['id', 'event_v2_detail_id', 'reward', 'balance_limit', 'flow_limit', 'withdraw_limit', 'status', 'created_at'])
                    ->where('member_id', $member->id)
                    ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
                    ->lockForUpdate()
                    ->first();

                if ($userJoin === null) {
                    return ApiResponse::error(__('event_v2.validation.no_join'), HttpCode::HTTP_NOT_IMPLEMENTED->value); // 您尚未參加任何活動
                }

                $wallet = $this->walletService->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);
                if ($userJoin->balance_limit !== null && $wallet->balance < $userJoin->balance_limit) {
                    return ApiResponse::error(__('event_v2.validation.wallet_balance'), HttpCode::HTTP_BAD_GATEWAY->value); // 活動錢包餘額不足
                }

                $flow = TotalBetRecord::where('member_id', $member->id)->where('bet_time', '>=', $userJoin->created_at)->sum('valid_bet');
                if ($userJoin->flow_limit !== null && $flow < $userJoin->flow_limit) {
                    return ApiResponse::error(__('event_v2.validation.flow'), HttpCode::HTTP_SERVICE_UNAVAILABLE->value); // 流水不足
                }

                $eventClass = $this->eventV2Service->getEventClass($userJoin->event_v2_detail_id);
                $withdraw = $eventClass->withdraw($userJoin, $member, $wallet);

                return ApiResponse::success(EventV2WithdrawResource::make([
                    'withdraw' => $withdraw,
                ]));
            });
        } catch (QueryException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            return ApiResponse::error($exception->getMessage(), 500);
        }
    }

    /**
     * 活動 v2 退出
     */
    public function quit()
    {
        $member = $this->getUserContext()->member;

        if ($member->is_event_v2 === false) {
            return ApiResponse::error(__('event_v2.validation.no_join'), 501); // 您尚未參加任何活動
        }

        try {
            return DB::transaction(function () use ($member) {
                // 重置活動
                $userJoin = EventV2UserJoin::where('member_id', $member->id)->where('status', EventV2UserJoinStatusEnum::JOIN->value)->first();
                $userJoin->withdraw = 0;
                $userJoin->status = EventV2UserJoinStatusEnum::QUIT->value;
                $this->eventV2Service->initEventV2($member, $userJoin);

                return ApiResponse::success();
            });
        } catch (QueryException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            return ApiResponse::error($exception->getMessage(), 500);
        }
    }

    public function joinDetail()
    {
        $member = $this->getUserContext()->member;

        if ($member->is_event_v2 === false) {
            return ApiResponse::customCode(501, __('event_v2.validation.no_join')); // 您尚未參加任何活動
        }

        $userJoin = EventV2UserJoin::select('id', 'event_v2_detail_id', 'reward', 'balance_limit', 'flow_limit', 'status', 'created_at')
            ->where('member_id', $member->id)
            ->where('status', EventV2UserJoinStatusEnum::JOIN->value)
            ->first();
        $flow = TotalBetRecord::where('member_id', $member->id)->where('bet_time', '>=', $userJoin->created_at)->sum('valid_bet');
        $wallet = $this->walletService->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);

        return ApiResponse::success(EventV2JoinDetailResource::make([
            'event_detail_id' => $userJoin->event_v2_detail_id,
            'balance_limit' => $userJoin->balance_limit,
            'flow_limit' => $userJoin->flow_limit,
            'balance' => $wallet->balance,
            'flow' => $flow,
        ]));
    }
}
