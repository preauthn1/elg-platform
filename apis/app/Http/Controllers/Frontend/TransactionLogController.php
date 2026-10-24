<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\PaginationRequest;
use App\Http\Requests\Frontend\TransactionLog\GameTransferLogsRequest;
use App\Http\Requests\Frontend\TransactionLog\RedEnvelopeLogsRequest;
use App\Http\Requests\Frontend\TransactionLog\SearchRequest;
use App\Http\Resources\Frontend\TransactionLog\CustomerServiceLogResource;
use App\Http\Resources\Frontend\TransactionLog\GameTransferLogResource;
use App\Http\Resources\Frontend\TransactionLog\RedEnvelopeLogsResource;
use App\Http\Resources\Frontend\TransactionLog\TransactionLogsResource;
use App\Http\Resources\PaginateResource;
use App\Models\TransactionLog;
use App\Models\WalletTransactionLog;
use App\Services\TransactionLogQueryService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

#[Group('TransactionLog (交易紀錄)')]
class TransactionLogController extends Controller
{
    public function __construct(
        protected TransactionLogQueryService $queryService,
    ) {}

    /**
     * 交易紀錄
     *
     * - 查詢 transaction_logs, 緩存 200 秒
     * - 只允許查3天(含當日)
     * - 類型: 0=全部類型(預設), 1=進分, 2=洗分, 3=進出遊戲, 4=活動獎勵, 5=全民代理(Reward), 6=轉帳, 7=補分, 8=紅包(轉帳)
     *
     * OLD: /api/transaction/getTransactionList
     */
    public function searchTransactionLogs(SearchRequest $request)
    {
        $input = $request->validated();
        $startTime = $input['start_time'];
        $endTime = $input['end_time'];
        $type = $input['type'] ?? 0;
        $perPage = $input['per_page'] ?? 15;

        $userData = $this->getUserContext();
        $member = $userData->member;

        // 限制N天內查詢
        $dayLimit = 3;

        // UTC +8
        $threeDaysAgo = now()->toFrontendTime()->subDays($dayLimit);
        $today = now()->toFrontendTime();
        // UTC +0
        $startTime = Carbon::parseFrontend($startTime)->toSystemTime();
        $endTime = Carbon::parseFrontend($endTime)->toSystemTime();
        // lad($threeDaysAgo, $today, $startTime, $endTime);

        // 查詢時間區段必須在三天內
        if ($startTime->lt($threeDaysAgo) || $startTime->gt($today)) {
            return ApiResponse::error(__('error_message.2044', ['values' => $dayLimit]));
        }

        $cachePrefix = 'member:' . $member->id . ':' . __FUNCTION__;
        $cacheKey = Common::genCacheKey($input, $cachePrefix);
        $cacheData = Cache::store('json')->remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($member, $type, $startTime, $endTime, $perPage) {
            // 查詢的交易日誌類型
            $transactionLogType = $this->queryService->getTransactionLogType($type);
            // 是否需要查詢泥碼交易紀錄
            $showRollingChip = $this->queryService->shouldShowRollingChip($type);

            // 交易紀錄
            $transactionLogs = $this->queryService->queryTransactionLog(
                $member,
                $transactionLogType,
                $startTime->toDateTimeString(),
                $endTime->toDateTimeString()
            );

            if ($showRollingChip) {
                // 查詢的泥碼類型
                $rollingChipLogType = $this->queryService->getRollingChipLogType($type);
                // 泥碼紀錄
                $rollingChipLogs = $this->queryService->queryRollingChipLog(
                    $member,
                    $rollingChipLogType,
                    $startTime->toDateTimeString(),
                    $endTime->toDateTimeString()
                );

                // 合併查詢後排序
                $transactionLogs = $transactionLogs->unionAll($rollingChipLogs)->orderByDesc('created_at');
            }

            // 分頁
            $logs = $transactionLogs->paginate($perPage);

            return PaginateResource::make(TransactionLogsResource::collection($logs));
        });

        return ApiResponse::success($cacheData);
    }

    /**
     * 紅包交易紀錄
     *
     * - 查詢 transaction_logs, 緩存5分鐘
     * - 類型: 1=發送記錄(預設), 2=領取記綠, 3=用戶手動退回OR系統退還
     * - type in 6=發紅包, 7=領紅包, 8=紅包取消(用戶手動), 9=紅包退還(系統自動),
     *
     * OLD: /api/transaction/redEnvelope
     */
    public function redEnvelopeLogs(RedEnvelopeLogsRequest $request)
    {
        $input = $request->validated();
        $type = $input['type'] ?? 1;
        $perPage = $input['per_page'] ?? 15;

        $userData = $this->getUserContext();
        $member = $userData->member;

        $cachePrefix = 'member:' . $member->id . ':' . __FUNCTION__;
        $cacheKey = Common::genCacheKey($input, $cachePrefix);
        $cacheData = Cache::store('json')->remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($member, $type, $perPage) {
            $logs = TransactionLog::query()
                ->when($type, function ($query) use ($type) {
                    return match ($type) {
                        1 => $query->where('type', 6), // 6是紅包
                        2 => $query->where('type', 7), // 7是紅包兌換
                        3 => $query->whereIn('type', [8, 9]), // 8是紅包發放, 9是紅包兌換失敗
                        default => $query,
                    };
                })
                ->where('member_id', $member->id)
                ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
                ->select(['id', 'point', 'created_at'])
                ->selectRaw('"' . addslashes($member->nickname) . '" as nickname')
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return PaginateResource::make(RedEnvelopeLogsResource::collection($logs));
        });

        return ApiResponse::success($cacheData);
    }

    /**
     * 客服交易記錄
     *
     * - 查詢 transaction_logs, 緩存5分鐘
     * - type in 4=開分, 5=洗分, 11=拒絕洗分, 12=退還洗分
     *
     * OLD: /api/transaction/customerService
     */
    public function customerServiceLogs(PaginationRequest $request)
    {
        $input = $request->validated();
        $perPage = $input['per_page'] ?? 15;

        $userData = $this->getUserContext();
        $member = $userData->member;

        $cachePrefix = CacheKeys::memberWalletTransactionLog($member->id, __FUNCTION__);
        $cacheKey = Common::genCacheKey($input, $cachePrefix);
        $cacheData = Cache::store('json')->remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($member, $perPage) {
            $logs = TransactionLog::query()
                ->whereIn('type', [4, 5, 11, 12])
                ->where('member_id', $member->id)
                ->where('status', TransactionStatusEnum::STATUS_SUCCESS->value)
                ->select(['id', 'point', 'created_at', 'transaction_no', 'type'])
                ->orderByDesc('created_at')
                ->paginate($perPage);

            return PaginateResource::make(CustomerServiceLogResource::collection($logs));
        });

        return ApiResponse::success($cacheData);
    }

    /**
     * 轉點記錄列表
     *
     * - 遊戲轉入轉出的交易紀錄
     * - 多錢包, 查詢 type 3=轉入遊戲商, 4=轉出遊戲商
     * - 單錢包, 查詢 type 1=平台轉入遊戲商, 2=遊戲商轉入平台
     */
    public function gameTransferLogs(GameTransferLogsRequest $request)
    {
        $input = $request->validated();
        $params = [
            'vendor_id' => $input['vendor_id'] ?? null,
            'start_time' => $input['start_time'] ?? null,
            'end_time' => $input['end_time'] ?? null,
            'page' => $input['page'] ?? 1,
            'per_page' => $input['per_page'] ?? 15,
        ];

        $userData = $this->getUserContext();
        $member = $userData->member;

        $cachePrefix = CacheKeys::memberWalletTransactionLog($member->id, __FUNCTION__);
        $cacheKey = Common::genCacheKey($params, $cachePrefix);
        $cacheData = Cache::store('json')->remember($cacheKey, CacheTtl::JIFFY->value, function () use ($params, $member) {
            // 檢查代理是否多錢包
            $agent = $member->agent;
            $isMultiWallet = $agent->is_multi_wallet;

            $baseQuery = $isMultiWallet ? WalletTransactionLog::query() : TransactionLog::query();

            $typeArr = $isMultiWallet
                ? [TransactionEnum::ORDER_DEPOSIT, TransactionEnum::DEPOSIT]  // 3=轉入遊戲商, 4=轉出遊戲商
                : [TransactionEnum::TO_VENDOR, TransactionEnum::FROM_VENDOR]; // 1=平台轉入遊戲商, 2=遊戲商轉入平台

            $logs = $baseQuery
                ->where('member_id', $member->id)
                ->where('status', TransactionStatusEnum::STATUS_SUCCESS)
                ->whereIn('type', $typeArr)
                ->when($params['vendor_id'], function ($query) use ($params) {
                    $query->where('vendor_id', $params['vendor_id']);
                })
                ->when($params['start_time'], function ($query) use ($params) {
                    $startTime = Carbon::parseFrontend($params['start_time'])->toSystemTime()->format('Y-m-d H:i:s');
                    $query->where('created_at', '>=', $startTime);
                })
                ->when($params['end_time'], function ($query) use ($params) {
                    $startTime = Carbon::parseFrontend($params['end_time'])->toSystemTime()->format('Y-m-d H:i:s');
                    $query->where('created_at', '<=', $startTime);
                })
                ->with([
                    'vendor',
                    'vendor.vendorImage' => function ($query) use ($agent) {
                        $query->where('agent_group_id', $agent->vendor_customize_group_id);
                        $query->select('id', 'vendor_id', 'name', 'image');
                    },
                ])
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($params['per_page']);

            return PaginateResource::make(GameTransferLogResource::collection($logs));
        });

        return ApiResponse::success($cacheData);
    }
}
