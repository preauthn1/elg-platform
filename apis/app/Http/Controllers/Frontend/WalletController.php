<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\General\CacheTtl;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Enums\Transaction\WalletTransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\Wallet\WalletLogsRequest;
use App\Http\Requests\Frontend\Wallet\WalletTransferRequest;
use App\Http\Resources\Frontend\Wallet\GameWalletLogResource;
use App\Http\Resources\Frontend\Wallet\MainWalletLogResource;
use App\Http\Resources\PaginateResource;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Services\AgentService;
use App\Services\WalletService;
use Dedoc\Scramble\Attributes\Group;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

#[Group('Wallet (錢包, VWorld 獨佔)')]
/**
 * 目前這些接口都是 VWorld 獨有的錢包功能
 *
 * @knowledge rules/currency-and-wallet-currency
 */
class WalletController extends Controller
{
    public function __construct(
        private AgentService $agentService
    ) {}

    /**
     * 主錢包紀錄
     *
     * - VWorld 專有
     * - 查詢主錢包的 transaction_logs, 緩存5分鐘
     * - 若未帶時間區段則查詢最近一週
     *
     * OLD: /api/mainWalletLog
     */
    public function mainWalletLogs(WalletLogsRequest $request)
    {
        $input = $request->validated();
        $perPage = $input['per_page'] ?? 15;
        $member = $this->getUserContext()->member;

        $startTime = now()->toFrontendTime()->subDays(7)->toSystemTime()->format('Y-m-d H:i:s');
        if (!empty($input['start_time'])) {
            $startTime = Carbon::parseFrontend($input['start_time'])->toSystemTime()->format('Y-m-d H:i:s');
        }

        $endTime = now()->toFrontendTime()->toSystemTime()->format('Y-m-d H:i:s');
        if (!empty($input['end_time'])) {
            $endTime = Carbon::parseFrontend($input['end_time'])->toSystemTime()->format('Y-m-d H:i:s');
        }

        $cachePrefix = CacheKeys::memberWalletTransactionLog($member->id, __FUNCTION__);
        $cacheKey = Common::genCacheKey($input, $cachePrefix);
        $cacheData = Cache::store('json')->remember($cacheKey, CacheTtl::JIFFY->value, function () use ($member, $startTime, $endTime, $perPage) {
            $logs = TransactionLog::query()
                ->where('member_id', $member->id)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->select(['id', 'point', 'tax', 'before_balance', 'balance', 'type', 'status', 'created_at'])
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return PaginateResource::make(MainWalletLogResource::collection($logs));
        });

        return ApiResponse::success($cacheData);
    }

    /**
     * 遊戲錢包紀錄
     *
     * - VWorld 專有
     * - 查詢遊戲錢包 wallet_transaction_logs, 緩存3分鐘
     * - 若未帶時間區段則查詢最近一週
     *
     * OLD: /api/gameWalletLog
     */
    public function gameWalletLogs(WalletLogsRequest $request)
    {
        $input = $request->validated();
        $perPage = $input['per_page'] ?? 15;
        $member = $this->getUserContext()->member;

        $startTime = now()->toFrontendTime()->subDays(7)->toSystemTime()->format('Y-m-d H:i:s');
        if (!empty($input['start_time'])) {
            $startTime = Carbon::parseFrontend($input['start_time'])->toSystemTime()->format('Y-m-d H:i:s');
        }

        $endTime = now()->toFrontendTime()->toSystemTime()->format('Y-m-d H:i:s');
        if (!empty($input['end_time'])) {
            $endTime = Carbon::parseFrontend($input['end_time'])->toSystemTime()->format('Y-m-d H:i:s');
        }

        $cachePrefix = CacheKeys::memberWalletTransactionLog($member->id, __FUNCTION__);
        $cacheKey = Common::genCacheKey($input, $cachePrefix);
        $cacheData = Cache::store('json')->remember($cacheKey, CacheTtl::JIFFY->value, function () use ($member, $startTime, $endTime, $perPage) {
            $logs = WalletTransactionLog::query()
                ->where('member_id', $member->id)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->select(['id', 'point', 'tax', 'before_balance', 'balance', 'type', 'status', 'created_at'])
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return PaginateResource::make(GameWalletLogResource::collection($logs));
        });

        return ApiResponse::success($cacheData);
    }

    /**
     * (新) 錢包交易紀錄
     *
     * - 依據 wallet_type 查詢 transaction_logs 或 wallet_transaction_logs, 緩存3分鐘
     * - 若未帶時間區段則查詢最近一週
     */
    public function walletHistories(WalletLogsRequest $request)
    {
        $input = $request->validated();
        $perPage = $input['per_page'] ?? 15;
        $member = $this->getUserContext()->member;

        $startTime = now()->toFrontendTime()->subDays(7)->toSystemTime()->format('Y-m-d H:i:s');
        if (!empty($input['start_time'])) {
            $startTime = Carbon::parseFrontend($input['start_time'])->toSystemTime()->format('Y-m-d H:i:s');
        }

        $endTime = now()->toFrontendTime()->toSystemTime()->format('Y-m-d H:i:s');
        if (!empty($input['end_time'])) {
            $endTime = Carbon::parseFrontend($input['end_time'])->toSystemTime()->format('Y-m-d H:i:s');
        }

        // wallet_type: main/game，預設 main
        $walletType = $input['wallet_type'] ?? 'main';

        if ($walletType === 'game') {
            $query = WalletTransactionLog::query();
            $resource = GameWalletLogResource::class;
        } else {
            $query = TransactionLog::query();
            $resource = MainWalletLogResource::class;
        }

        $cachePrefix = CacheKeys::memberWalletTransactionLog($member->id, __FUNCTION__ . ':' . $walletType);
        $cacheKey = Common::genCacheKey($input, $cachePrefix);
        $cacheData = Cache::store('json')->remember($cacheKey, CacheTtl::JIFFY->value, function () use ($query, $member, $startTime, $endTime, $perPage, $resource) {
            $logs = $query
                ->where('member_id', $member->id)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->select(['id', 'point', 'tax', 'before_balance', 'balance', 'type', 'status', 'created_at'])
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return PaginateResource::make($resource::collection($logs));
        });

        return ApiResponse::success($cacheData);
    }

    /**
     * 錢包餘額轉移
     *
     * - VWorld 專有
     *
     * OLD: /api/transferGameWallet
     *
     * @noinspection D
     */
    public function walletTransfer(WalletTransferRequest $request)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;

        $input = $request->validated();
        $type = (int) ($input['type'] ?? 1); // 1=主錢包轉入遊戲錢包, 2=遊戲錢包轉回主錢包
        $amount = (string) ($input['amount'] ?? '0');

        // 參數驗證
        if (!in_array($type, [1, 2], true)) {
            throw new ApiException(__('error_message.1001')); // 非法轉帳型別
        }
        if (!is_numeric($amount) || Common::numCompare($amount, '0', 2) <= 0) {
            throw new ApiException(__('error_message.1002')); // 金額錯誤
        }

        // 鎖分中, 不允許轉移
        if ($member->event_mode > 0) {
            throw new ApiException(__('error_message.1934'));
        }

        $lockKey = CacheKeys::memberWalletTransactionLog($member->id, __FUNCTION__);

        return $this->withLock(
            key: $lockKey,
            ttl: 10,
            errorMsg: __('error_message.99993'),
            callback: function () use ($member, $type, $amount) {
                try {
                    DB::beginTransaction();

                    // 重新查詢鎖定主錢包
                    $member = Member::lockForUpdate()->find($member->id);
                    if (!$member) {
                        throw new ApiException(__('error_message.1005')); // 會員不存在
                    }

                    // 取得鎖定的遊戲錢包。type=2 遊戲錢包已廢除，這裡只查不建。
                    // 本 API 是 VWorld 專有的一般錢包↔遊戲錢包互轉。
                    // 它沒有多錢包開關防護，仍是可被呼叫的入口。
                    // 過渡期存量錢包仍可互轉。查無存量錢包就回業務錯誤，不自動建立。
                    // 實測 VWorld 營運清倉後零流量，其餘五站本 API 零使用。
                    // ApiException 不寫入預設例外日誌，也不發 BotNotify 通知（見 bootstrap/app.php 的 report 設定），
                    // 所以這裡另記一條 log，方便追查缺少存量遊戲錢包的拒絕原因。
                    $gameWallet = (new WalletService)->getLockedMemberWallet($member->id, WalletEnum::CODE_GAME);
                    if (is_null($gameWallet)) {
                        Log::info('walletTransfer 拒絕：會員無 type=2 存量錢包（已廢除、不再自動建立）', [
                            'member_id' => $member->id,
                            'type' => $type,
                        ]);
                        throw new ApiException('Game wallet not found');
                    }
                    if ($gameWallet->status != 1) {
                        throw new ApiException(__('error_message.90062'));
                    }

                    // 取款前檢查稽核流水
                    if ($type === 2 && $this->checkFlowLimit($member, $gameWallet, $amount)) {
                        throw new ApiException(__('error_message.1980'));
                    }

                    // 當��餘額
                    $memberBeforeBalance = $member->balance;
                    $walletBeforeBalance = $gameWallet->balance;

                    // 計算轉帳後餘額
                    if ($type === 1) {
                        // 主錢包轉到遊戲錢包
                        $transactionLogsType = TransactionEnum::TO_GAME_WALLET;
                        $walletLogsType = WalletTransactionEnum::FROM_MAIN_WALLET;
                        $memberBalance = Common::numSub($memberBeforeBalance, $amount);
                        $walletBalance = Common::numAdd($walletBeforeBalance, $amount);

                        if (Common::numCompare($memberBalance, '0') < 0) {
                            throw new ApiException(__('error_message.1022')); // 餘額不足
                        }
                    } else {
                        // 遊戲錢包轉到主錢包
                        $transactionLogsType = TransactionEnum::FROM_GAME_WALLET;
                        $walletLogsType = WalletTransactionEnum::TO_MAIN_WALLET;
                        $memberBalance = Common::numAdd($memberBeforeBalance, $amount);
                        $walletBalance = Common::numSub($walletBeforeBalance, $amount);

                        if (Common::numCompare($walletBalance, '0') < 0) {
                            throw new ApiException(__('error_message.1022')); // 餘額不足
                        }
                    }

                    // 建立主錢包交易紀錄
                    $transactionLog = TransactionLog::create([
                        'transaction_no' => Common::getTransactionNo(),
                        'agent_id' => $member->parent,
                        'point' => $amount,
                        'vendor_point' => 0,
                        'rate' => 1,
                        'before_balance' => $memberBeforeBalance,
                        'balance' => $memberBalance,
                        'type' => $transactionLogsType,
                        'member_id' => $member->id,
                        'status' => TransactionStatusEnum::STATUS_FAIL->value,
                        'remark' => '',
                        'checksum' => '',
                        'result' => '',
                        'operator' => $member->username,
                        'admin' => $member->id,
                    ]);

                    // 建立遊戲錢包交易紀錄
                    $walletTransactionLog = WalletTransactionLog::create([
                        'transaction_no' => Common::getTransactionNo(true),
                        'wallet_type_id' => WalletEnum::CODE_GAME->value,
                        'agent_id' => $member->parent,
                        'point' => $amount,
                        'before_balance' => $walletBeforeBalance,
                        'balance' => $walletBalance,
                        'type' => $walletLogsType,
                        'member_id' => $member->id,
                        'status' => TransactionStatusEnum::STATUS_FAIL->value,
                        'remark' => '',
                        'checksum' => '',
                        'result' => '',
                        'operator' => $member->username,
                        'admin' => $member->id,
                    ]);

                    // 更新餘額
                    $member->update(['balance' => $memberBalance]);
                    $gameWallet->update(['balance' => $walletBalance]);

                    // 主錢包轉入遊戲錢包要綁定1倍的稽核流水
                    if ($type === 1) {
                        FlowLimit::create([
                            'member_id' => $member->id,
                            'type' => TransactionEnum::TO_GAME_WALLET->value,
                            'event_id' => 0,
                            'reference_id' => $transactionLog->id,
                            'flow_limit' => $amount,
                            'reach' => 0,
                            'admin' => 0,
                        ]);
                    }

                    // 更新交易紀錄狀態
                    $transactionLog->update([
                        'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                    ]);
                    $walletTransactionLog->update([
                        'status' => TransactionStatusEnum::STATUS_SUCCESS->value,
                    ]);

                    DB::commit();
                } catch (Exception $e) {
                    DB::rollBack();
                    // 可加強日誌記錄
                    // \Log::error('walletTransfer error', ['member_id' => $member->id, 'msg' => $e->getMessage()]);
                    throw $e;
                }

                return ApiResponse::success();
            });
    }

    /**
     * 檢查會員是否符合取款稽核流水限制, 只適用 VW
     *
     * @return bool true: 不允許取款或轉帳，false: 允許
     */
    private function checkFlowLimit(Member $member, MemberWallet $gameWallet, $withdrawAmount): bool
    {
        $agentSetting = $this->agentService->getAgentSettings($member->agent);

        // 稽核流水檢查開關
        // $flowLimitVisible = $agentSetting[AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE];

        // 多錢包轉帳流水需歸零
        $multipleWalletFlowLimitSetting = $agentSetting[AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_FLOW_LIMIT_SETTING];

        $flowLimit = FlowLimit::query()
            ->where('member_id', $member->id)
            ->where('type', '!=', TransactionEnum::PROMOTION_WALLET_REWARD->value) // 優惠錢包除外
            ->where('status', 0)
            ->selectRaw('IFNULL(SUM(flow_limit), 0) AS flow_limit, IFNULL(SUM(reach), 0) AS reach')
            ->first();

        // 若設定為必須��零且尚有未達成的稽核流水，則不允許轉入主錢包
        if ($multipleWalletFlowLimitSetting == 1 && $flowLimit->flow_limit > $flowLimit->reach) {
            // 未達成流水目標
            return true;
        }

        // 稽核流水不必歸零時，僅允許轉入扣除未達成稽核流水後的金額
        // 計算尚未達成的稽核流水
        $unreachedFlowLimit = Common::numSub($flowLimit->flow_limit, $flowLimit->reach);

        // 可轉帳金額 = 遊戲錢包餘額 - 尚未達成的稽核流水
        $transferableAmount = Common::numSub($gameWallet->balance, $unreachedFlowLimit);

        if ($transferableAmount >= $withdrawAmount) {
            return false;
        }

        return true;
    }
}
