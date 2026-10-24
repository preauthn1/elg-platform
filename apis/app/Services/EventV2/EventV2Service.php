<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\CacheKeys\CacheKeys;
use App\Data\EventV2ListItemData;
use App\Data\UserData;
use App\Data\VendorBonusCodeRequestData;
use App\Enums\EventV2\EventV2RecycleStatusEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\General\CacheTtl;
use App\Enums\StatusEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\Vendor\VendorInfoTypeEnum;
use App\Enums\WalletEnum;
use App\Exceptions\VendorSetupException;
use App\Helpers\Common;
use App\Models\EventV2;
use App\Models\EventV2Detail;
use App\Models\EventV2Recycle;
use App\Models\EventV2UserJoin;
use App\Models\EventV2WalletTransactionLog;
use App\Models\Game;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\VendorInfo;
use App\Models\WalletTransactionLog;
use App\Services\MemberTransferService;
use App\Services\VendorService;
use App\Services\WalletService;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\AbstractTransferWallet;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventV2Service
{
    public const int DEFAULT_LANGUAGE_id = 1;

    public function getEventClass(int $eventDetailId): AbstractEventV2
    {
        $event = EventV2::select('event_v2.*')
            ->join('event_v2_details', function ($join) use ($eventDetailId) {
                $join->on('event_v2.id', '=', 'event_v2_details.event_v2_id')
                    ->where('event_v2_details.id', $eventDetailId);
            })->first();

        $class = 'App\\Services\\EventV2\\' . $event->handler_class;
        if (!class_exists($class)) {
            throw new \Exception('class not found', 500);
        }

        Log::info('Get event v2 class "' . $class . '"');

        return App::make($class);
    }

    public function getEventV2List(UserData $userData): Collection
    {
        $languageId = $userData->languageId;
        $member = $userData->member;
        $agent = $member->agent ?? $userData->agent;

        $now = Carbon::now();
        $time = $now->toDateTimeString();
        $timeOffset = $now->copy()->toFrontendTime()->offsetHours;

        $eventDetails = EventV2Detail::select([
            'event_v2.id AS event_v2_id',
            'event_v2.handler_class',
            'event_v2_details.id',
            'event_v2_details.start_time',
            'event_v2_details.end_time',
            'event_v2_details.sort',
            'event_v2_details.created_at',
        ])
            ->with([
                'detailLang' => function ($query) use ($languageId) {
                    $query->whereIn('language_id', [self::DEFAULT_LANGUAGE_id, $languageId]);
                },
                'detailGameTypes',
            ])
            ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
            ->whereIn('event_v2_details.agent_id', $agent->hierarchy_array)
            // 排除代理（含下線）
            ->whereDoesntHave('detailExcludeAgents', function ($query) use ($agent) {
                $query->whereIn('agent_id', $agent->hierarchy_array);
            })
            ->where('event_v2_details.start_time', '<=', $time)
            ->where('event_v2_details.end_time', '>=', $time)
            ->where('event_v2_details.status', StatusEnum::ENABLE->value)
            ->get();

        // $memberId = $member->id ?? 0;
        // $participants = EventV2UserJoin::select('event_v2.id AS event_v2_id', 'event_v2_user_joins.event_v2_detail_id', 'event_v2_user_joins.status')
        //     ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS date', [$timeOffset, '%Y-%m-%d'])
        //     ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS week', [$timeOffset, '%x-%v'])
        //     ->selectRaw('DATE_FORMAT(DATE_ADD(event_v2_user_joins.created_at, INTERVAL ? HOUR), ?) AS month', [$timeOffset, '%Y-%m'])
        //     ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
        //     ->leftJoin('event_v2', 'event_v2.id', '=', 'event_v2_details.event_v2_id')
        //     ->where('event_v2_user_joins.member_id', $memberId)
        //     ->get();

        $collection = new Collection;
        foreach ($eventDetails as $key => $eventDetail) {
            // 無法參加的活動也要顯示, 參考 http://example.invalid:1688/target/tickets/-/work_items/3587
            // $eventClass = $this->getEventClass($eventDetail->id);
            // if (isset($member) && method_exists($eventClass, 'listValidate')) { // @phpstan-ignore function.alreadyNarrowedType (@method PHPDoc 宣告使 PHPStan 認為方法恆存在，但實際只在部分子類實作)
            //     if ($eventClass->listValidate($eventDetail, $participants, $member) === false) {
            //         continue;
            //     }
            // }

            // 若語系沒設定,預設抓英文
            $detailLang = $eventDetail->detailLang->where('language_id', $languageId)->isNotEmpty() ?
                $eventDetail->detailLang->where('language_id', $languageId)->first() :
                $eventDetail->detailLang->where('language_id', self::DEFAULT_LANGUAGE_id)->first();

            if ($detailLang !== null) {
                $eventDetails[$key]->name = $detailLang->name;
                $eventDetails[$key]->image = $detailLang->image;
            }

            $collection->push(EventV2ListItemData::from([
                'event_v1_id' => null,
                'event_v2_id' => $eventDetail->id,
                'tp_event' => null,
                'handler_class' => $eventDetail->handler_class,
                'name' => $eventDetail->name,
                'image' => $eventDetail->image,
                'params' => null,
                'game_type_ids' => $eventDetail->detailGameTypes->pluck('id')->toArray(),
                'sort' => $eventDetail->sort,
                'created_at' => $eventDetail->created_at?->toDateTimeString(),
                'end_time' => $eventDetail->end_time,
            ]));
        }

        return $collection;
    }

    /**
     * 是否有活動 V2 目前符合參加資格（邏輯比照 EventV2Controller::detail() 對單一活動的 validate()）。
     * 僅用於驚嘆號提醒，找到第一個符合資格的活動就回傳，不需要跑完全部活動。
     */
    public function hasJoinableEventV2(array $agentIds, Member $member): bool
    {
        $detailIds = $this->findAvailableEventV2DetailIds($agentIds);

        foreach ($detailIds as $detailId) {
            try {
                $eventClass = $this->getEventClass($detailId);
                $validateResult = $eventClass->validate($detailId, $member);
            } catch (Throwable $e) {
                Log::error('活動 V2 驚嘆號檢查 validate 失敗', [
                    'event_v2_detail_id' => $detailId,
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);

                continue;
            }

            if ($validateResult->isSuccess()) {
                return true;
            }
        }

        return false;
    }

    private function findAvailableEventV2DetailIds(array $agentIds): array
    {
        return Cache::safeRemember(
            CacheKeys::availableEventV2DetailIds($agentIds),
            CacheTtl::ONE_MINUTE->value,
            function () use ($agentIds) {
                $now = now();

                return EventV2Detail::whereIn('agent_id', $agentIds)
                    ->where('status', StatusEnum::ENABLE->value)
                    ->where('start_time', '<=', $now)
                    ->where('end_time', '>=', $now)
                    ->whereDoesntHave('detailExcludeAgents', function ($query) use ($agentIds) {
                        $query->whereIn('agent_id', $agentIds);
                    })
                    ->pluck('id')
                    ->toArray();
            }
        );
    }

    public function getVendorBonusCode(int $vendorId, Member $member): string
    {
        $vendorService = app(VendorService::class);
        $vendorDriver = $vendorService->getDriver($vendorId, $member);
        $bonusData = $vendorDriver->getBonusCode(VendorBonusCodeRequestData::from());

        if (empty($bonusData->bonus_code)) {
            throw new \Exception('vendor id ' . $vendorId . ' bonus code is empty', 500);
        }

        return $bonusData->bonus_code;
    }

    /**
     * 大廳遊戲清單灰化用：跟 GameController::checkGameAvailable() 判斷會員是否可以進入這款遊戲的
     * 遊戲類型限制邏輯完全一致（該處是真的擋進遊戲，這裡只是把同一個規則拿來給大廳列表灰化用，
     * 避免兩處各寫一份互相飄移）。目前 EventV2 只在遊戲類型這個維度有真的擋進遊戲，遊戲商限制
     * （restrict_vendor）現況只用在大廳「遊戲商列表」層級過濾，未在進遊戲當下驗證，故這裡不比照
     * 遊戲商，維持跟既有真實限制範圍一致。
     *
     * 獨立於 FlowLimitService::isGameDisabled()（該服務目前的 EventV2 分支對這個用途有既知的資料
     * 型別不符問題，設定vendor限制時會誤把全部遊戲灰化、只設遊戲類型時完全不會灰化——本方法不修
     * 那個既有邏輯，只另外提供這一條正確判斷，呼叫端 OR 起來合併即可）。
     */
    public function isGameDisabled(Game $game, ?Member $member): bool
    {
        if ($member === null || !$member->is_event_v2) {
            return false;
        }

        $availableGameType = EventV2UserJoin::query()
            ->leftJoin('event_v2_details', 'event_v2_details.id', '=', 'event_v2_user_joins.event_v2_detail_id')
            ->leftJoin('event_v2_detail_game_types', 'event_v2_detail_game_types.event_v2_detail_id', '=', 'event_v2_details.id')
            ->where('event_v2_user_joins.member_id', $member->id)
            ->where(function ($query) use ($game) {
                $query->where('event_v2_detail_game_types.game_type_id', $game->type_id)
                    ->orWhereNull('event_v2_detail_game_types.game_type_id');
            })
            ->where('event_v2_user_joins.status', EventV2UserJoinStatusEnum::JOIN->value)
            ->exists();

        return !$availableGameType;
    }

    public function initEventV2(Member $member, EventV2UserJoin $userJoin): void
    {
        if ($userJoin->created_at === null) {
            throw new \Exception('created_at is null', 500);
        }

        // 關閉遊戲帳號
        $this->_closeGameAccount($member, $userJoin);

        // 活動錢包歸零
        $wallet = app(WalletService::class)->getLockedMemberWallet($member->id, WalletEnum::CODE_EVENT_WALLET);
        $beforeBalance = $wallet->balance;
        $wallet->update([
            'balance' => 0,
        ]);

        $this->insertWalletTransactionLog($member, $userJoin, [
            'type' => WalletTransactionEnum::EVENT_WALLET_INIT->value,
            'point' => $beforeBalance,
            'before_balance' => $beforeBalance,
            'balance' => 0,
        ]);

        if ($userJoin->eventV2Detail->is_recycle == 1) {
            // 新增回收紀錄
            EventV2Recycle::create([
                'event_v2_user_join_id' => $userJoin->id,
                'final_balance' => $beforeBalance,
                'withdraw' => $userJoin->withdraw,
                'recycle_amount' => Common::numSub($beforeBalance, $userJoin->withdraw),
                'status' => match ($userJoin->status) {
                    EventV2UserJoinStatusEnum::COMPLETE->value => EventV2RecycleStatusEnum::COMPLETE->value,
                    EventV2UserJoinStatusEnum::QUIT->value => EventV2RecycleStatusEnum::QUIT->value,
                    EventV2UserJoinStatusEnum::SYSTEM_INIT->value => EventV2RecycleStatusEnum::SYSTEM_INIT->value,
                    EventV2UserJoinStatusEnum::OPERATOR_QUIT->value => EventV2RecycleStatusEnum::OPERATOR_QUIT->value,
                    default => EventV2RecycleStatusEnum::SYSTEM_SCHEDULE->value
                },
            ]);
        }

        // 關閉參加紀錄
        $userJoin->end_time = date('Y-m-d H:i:s');
        $userJoin->final_balance = $beforeBalance;
        $userJoin->save();
    }

    private function _closeGameAccount(Member $member, EventV2UserJoin $userJoin): void
    {
        $vendorIds = WalletTransactionLog::select(['vendor_id'])
            ->where('member_id', $member->id)
            ->where('created_at', '>=', $userJoin->created_at)
            ->whereIn('type', [WalletTransactionEnum::EVENT_WALLET_TO_VENDOR->value, WalletTransactionEnum::SEAMLESS_WALLET_BET_SETTLE->value])
            ->orderBy('created_at', 'DESC')
            ->groupBy(['vendor_id'])
            ->get()
            ->pluck('vendor_id')
            ->toArray();

        $vendorService = app(VendorService::class);

        foreach ($vendorIds as $key => $vendorId) {
            try {
                $vendorDriver = $vendorService->getDriver($vendorId, $member);
            } catch (VendorSetupException $e) {
                // 遊戲商被下架後(vendor_customized_items.status = 0)，玩家才結束活動
                $vendorDriver = $vendorService->getDriver($vendorId);
                if ($vendorDriver instanceof AbstractSingleWallet) {
                    continue;
                }

                $vendorDriver->setMember($member);

                $vendorInfo = VendorInfo::where('vendor_id', $vendorId)->where('type', VendorInfoTypeEnum::EVENT_V2->value)->first();
                $vendorDriver->setVendorInfo($vendorInfo);

                $accountModel = $vendorDriver->getAccountModel();
                $eventV2account = $userJoin->eventV2account->where('vendor_id', $vendorId)->first();

                if (empty($eventV2account)) {
                    Log::error('活動V2 沒有遊戲帳號', ['user_join_id' => $userJoin->id, 'vendor_id' => $vendorId]);

                    continue;
                }

                $account = $accountModel::find($eventV2account->account_id);
                $vendorDriver->setVendorAccount($account);
            }

            // 檢查前三個轉帳錢包遊戲商並轉回
            $countTransferWallet = 0;
            if ($countTransferWallet < 3 && !empty($vendorDriver) && $vendorDriver instanceof AbstractTransferWallet) {
                try {
                    $countTransferWallet++;
                    $result = app(MemberTransferService::class)->transferToLocal($vendorDriver);
                    Log::debug('活動V2 transferBack 轉回本地', $result);
                } catch (Throwable $e) {
                    Log::error('活動V2 transferBack 轉回本地失敗', ['exception' => $e]);
                }
            }
            // 關閉遊戲帳號
            $account = $vendorDriver->getAccount();
            $account->update([
                'status' => StatusEnum::DISABLE->value,
            ]);
        }
    }

    public function insertTransactionLog(Member $member, array $data): void
    {
        $defaultData = [
            'transaction_no' => Common::getTransactionNo(),
            'agent_id' => $member->parent,
            'vendor_id' => 0,
            'order_id' => 0,
            'tax' => 0,
            'vendor_point' => 0,
            'vendor_balance' => 0,
            'member_id' => $member->id,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => '',
            'checksum' => '',
            'result' => '',
            'operator' => $member->username,
            'admin' => $member->id,
        ];

        $data = array_merge($defaultData, $data);

        TransactionLog::create($data);
    }

    public function insertWalletTransactionLog(Member $member, EventV2UserJoin $userJoin, array $log, array $eventV2Log = []): void
    {
        $defaultLog = [
            'transaction_no' => Common::getTransactionNo(true),
            'wallet_type_id' => WalletEnum::CODE_EVENT_WALLET,
            'agent_id' => $member->parent,
            'vendor_id' => 0,
            'order_id' => 0,
            'tax' => 0,
            'vendor_point' => 0,
            'vendor_balance' => 0,
            'member_id' => $member->id,
            'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
            'remark' => '',
            'checksum' => '',
            'result' => '',
            'operator' => $member->username,
            'admin' => $member->id,
        ];

        $log = array_merge($defaultLog, $log);

        $walletTransactionLog = WalletTransactionLog::create($log);

        $eventV2DefaultLog = [
            'wallet_transaction_log_id' => $walletTransactionLog->id,
            'event_v2_user_join_id' => $userJoin->id,
        ];

        $eventV2Log = array_merge($eventV2DefaultLog, $eventV2Log);

        EventV2WalletTransactionLog::create($eventV2Log);
    }
}
