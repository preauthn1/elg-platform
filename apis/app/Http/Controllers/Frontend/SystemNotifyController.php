<?php

declare(strict_types=1);

namespace App\Http\Controllers\Frontend;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Exceptions\ApiException;
use App\Helpers\ApiResponse;
use App\Helpers\Common;
use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\SystemNotify\DetailRequest;
use App\Http\Requests\Frontend\SystemNotify\ListRequest;
use App\Http\Resources\OnlyPaginateResource;
use App\Models\MemberTransferLog;
use App\Models\RollingChipLog;
use App\Models\SystemNotificationRead;
use App\Models\ThreeHierarchyBonusReport;
use App\Models\TransactionLog;
use App\Services\SystemNotifyService;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

#[Group('SystemNotify (系統通知)')]
class SystemNotifyController extends Controller
{
    public function __construct(
        private SystemNotifyService $service,
    ) {}

    /**
     * 平台公告列表
     *
     * 分別查詢以下五種資料
     *
     * 1. 訂單（orders）：查詢 3 天內的充值訂單資料，整理欄位與狀態。
     * 2. 提款訂單（withdrawal_orders）：查詢 3 天內的提款訂單資料，整理欄位與狀態。
     * 3. 現金交易紀錄（transaction_logs）：查詢 3 天內的現金交易紀錄，依 type 分類（獎勵、交易、系統）。
     * 4. 禮碼紀錄（rolling_chip_logs）：查詢 3 天內的禮碼相關紀錄，依 type 分類（獎勵、系統）。
     * 5. 會員綁定紀錄（member_binding_logs）：查詢 3 天內的會員綁定/修改紀錄。
     * 6. 實名驗證審核（realname_validation_requests）：查詢 3 天內的實名驗證審核紀錄。
     * 將五種資料整理為相同格式後 unionAll 以供查詢
     *
     * OLD: /api/systemNotification/list
     */
    #[ExcludeRouteFromDocs] // Scramble 對多來源 merge 出來的異質集合做型別推斷時會誤判成呼叫 RealnameValidationRequest::imageAttribute() 而崩潰，暫時排除
    public function list(ListRequest $request)
    {
        $input = $request->validated();
        $perPage = $input['per_page'] ?? 10;

        $userData = $this->getUserContext();
        $languageId = $userData->languageId;
        $member = $userData->member;
        $memberId = $member->id;

        $cacheKey = CacheKeys::systemNotify($memberId);

        // 三天內的紀錄緩存為 json, 取出後轉 collect 再處理分頁
        $logs = Cache::store('json')->remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($memberId, $languageId) {
            // UTC+8的日期
            $startTime = now()->toFrontendTime()->startOfDay()->subDays(2)->toSystemTime()->toDateTimeString();

            $orderLogs = $this->service->getOrderLogs($startTime, $memberId, $languageId);
            $withdrawalOrderLogs = $this->service->getWithdrawalOrderLogs($startTime, $memberId, $languageId);
            $transactionLogs = $this->service->getTransactionLogs($startTime, $memberId);
            $rollingChipLogs = $this->service->getRollingChipLogs($startTime, $memberId);
            $memberBindingLogs = $this->service->getMemberBindingLogs($startTime, $memberId);
            $realnameValidationRequestLogs = $this->service->getRealnameValidationRequestLogs($startTime, $memberId);

            // 創建一個集合
            $collection = collect();

            // 將五個查詢結果的集合 merge 一起後調整排序
            return $collection
                ->merge($orderLogs)
                ->merge($withdrawalOrderLogs)
                ->merge($transactionLogs)
                ->merge($rollingChipLogs)
                ->merge($memberBindingLogs)
                ->merge($realnameValidationRequestLogs)
                ->sortByDesc('time')->values();
        });

        $logs = collect($logs);

        // 處理分頁
        $pageData = $logs->paginate($perPage);

        return ApiResponse::successWithPagination(
            data: $pageData->items(),
            paginator: OnlyPaginateResource::make($pageData)
        );
    }

    /**
     * 平台公告未讀數量
     *
     * OLD: /api/systemNotification/unread
     */
    public function unread()
    {
        $userData = $this->getUserContext();
        $languageId = $userData->languageId;
        $member = $userData->member;
        $memberId = $member->id;

        $record = SystemNotificationRead::query()
            ->where('member_id', $memberId)
            ->first();

        $lastReadTime = $record?->last_read_time ?? '';

        $cacheKey = CacheKeys::systemNotify($memberId);

        // 三天內的紀錄緩存為 json, 取出後在篩選未讀數量
        $logs = Cache::store('json')->remember($cacheKey, CacheTtl::DEFAULT->value, function () use ($memberId, $languageId) {
            // UTC+8的日期
            $startTime = now()->toFrontendTime()->startOfDay()->subDays(2)->toSystemTime()->toDateTimeString();

            $orderLogs = $this->service->getOrderLogs($startTime, $memberId, $languageId);
            $withdrawalOrderLogs = $this->service->getWithdrawalOrderLogs($startTime, $memberId, $languageId);
            $transactionLogs = $this->service->getTransactionLogs($startTime, $memberId);
            $rollingChipLogs = $this->service->getRollingChipLogs($startTime, $memberId);
            $memberBindingLogs = $this->service->getMemberBindingLogs($startTime, $memberId);

            // 創建一個集合
            $collection = collect();

            // 將五個查詢結果的集合 merge 一起後調整排序
            return $collection
                ->merge($orderLogs)
                ->merge($withdrawalOrderLogs)
                ->merge($transactionLogs)
                ->merge($rollingChipLogs)
                ->merge($memberBindingLogs)
                ->sortByDesc('time')->values();
        });

        $logs = collect($logs);

        // 篩選未讀的紀錄
        if ($lastReadTime) {
            $unreadCount = $logs->filter(function ($log) use ($lastReadTime) {
                return Carbon::parse($log['time'])->gt($lastReadTime->toFrontendTime()->toDateTimeString());
            })->count();
        } else {
            $unreadCount = $logs->count();
        }

        return ApiResponse::success([
            'unread' => $unreadCount,
        ]);
    }

    /**
     * 平台公告已讀全部
     *
     * OLD: /api/systemNotification/readAll
     */
    public function readAll()
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $memberId = $member->id;

        SystemNotificationRead::updateOrCreate([
            'member_id' => $member->id,
        ], [
            'last_read_time' => now(),
        ]);

        // 清除緩存
        $cacheKey = CacheKeys::systemNotify($memberId);
        Cache::forget($cacheKey);

        return ApiResponse::success();
    }

    /**
     * 平台公告詳情, 僅適用交易紀錄與禮碼紀錄
     *
     * OLD: /api/systemNotification/getDetail
     */
    public function detail(DetailRequest $request)
    {
        $input = $request->validated();

        return match ($request->data_name) {
            'transaction_logs' => $this->getTransactionLogsDetail($input),
            'rolling_chip_logs' => $this->getRollingChipLogsDetail($input),
            default => throw new ApiException(__('error_message.unknown exception')),
        };
    }

    /**
     * 取得交易紀錄詳情
     *
     * type=17: 現金傭金詳情
     * type=37: 轉帳(轉出)詳情
     * type=38: 轉帳(轉入)詳情
     *
     * @noinspection t
     */
    private function getTransactionLogsDetail(array $input)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $memberId = $member->id;

        switch ($input['type']) {
            case 17:
                // 現金傭金詳情
                $transactionLog = TransactionLog::query()
                    ->where('member_id', $memberId)
                    ->where('id', $input['data_id'])
                    ->first();

                if (!$transactionLog) {
                    return ApiResponse::success([], __('error_message.1010'));
                }

                $createdAt = $transactionLog->created_at;
                $summaryTime = $createdAt->startOfDay()->subDay()->toDateTimeString();

                $reports = ThreeHierarchyBonusReport::query()
                    ->with(['fromMember', 'vendor'])
                    ->where('member_id', $memberId)
                    ->where('reward_type', 1)
                    ->where('summary_time', $summaryTime)
                    ->orderByDesc('created_at')
                    ->get();

                $data = $reports->map(function ($item) {
                    $username = $item->fromMember->username ?? '';
                    $len = mb_strlen($username);
                    if ($len === 0) {
                        $fromMemberName = '';
                    } else {
                        $maskLen = (int) floor($len * 0.6);
                        $left = (int) floor(($len - $maskLen) / 2);
                        $right = (int) ceil(($len - $maskLen) / 2);
                        $fromMemberName = mb_substr($username, 0, $left)
                            . str_repeat('*', $maskLen)
                            . mb_substr($username, $len - $right, $right);
                    }

                    return [
                        'from_member_name' => $fromMemberName,
                        'vendor_name' => $item->vendor->name ?? '',
                        'game_type_id' => $item->game_type_id,
                        'bet' => $item->rebate_type == 1 ? ($item->bet + $item->down_bet + $item->down2_bet) : 0,
                        'lose' => $item->rebate_type == 2 ? ($item->bet + $item->down_bet + $item->down2_bet) : 0,
                        'rebate' => ($item->rebate + $item->down_rebate + $item->down2_rebate),
                        'rebate_type' => $item->rebate_type,
                    ];
                });

                // 處理分頁
                $pageData = $data->paginate($input['per_page']);

                $sumData = $reports->reduce(function ($carry, $item) {
                    $betSum = $item->bet + $item->down_bet + $item->down2_bet;
                    $rebateSum = $item->rebate + $item->down_rebate + $item->down2_rebate;

                    if ($item->rebate_type == 1) {
                        $carry['bet'] += $betSum;
                    }
                    if ($item->rebate_type == 2) {
                        $carry['lose'] += $betSum;
                    }
                    $carry['rebate'] += $rebateSum;

                    return $carry;
                }, ['bet' => 0, 'lose' => 0, 'rebate' => 0]);

                return ApiResponse::success([
                    'items' => $pageData->items(),
                    'pagination' => OnlyPaginateResource::make($pageData),
                    'sum_data' => $sumData,
                    'time' => $createdAt->toFrontendTime()->toDateTimeString(),
                ]);

            case 37:
                // 轉帳(轉出)詳情
                $memberTransferLog = MemberTransferLog::query()
                    ->with(['operatorMember', 'member'])
                    ->where('operator', $memberId)
                    ->where('operator_transaction_log_id', $input['data_id'])
                    ->first();

                if (!$memberTransferLog) {
                    return ApiResponse::success([], __('error_message.1010'));
                }

                return ApiResponse::success([
                    'sender' => $memberTransferLog->operatorMember->username ?? '',
                    'receiver' => $memberTransferLog->member->username ?? '',
                    'amount' => $memberTransferLog->amount,
                    'tax' => $memberTransferLog->tax,
                    'time' => $memberTransferLog->created_at->toFrontendTime()->toDateTimeString(),
                ]);

            case 38:
                // 轉帳(轉入)詳情
                $memberTransferLog = MemberTransferLog::with(['operatorMember', 'member'])
                    ->where('member_id', $memberId)
                    ->where('transaction_log_id', $input['data_id'])
                    ->first();

                if (!$memberTransferLog) {
                    return ApiResponse::success([], __('error_message.1010'));
                }

                return ApiResponse::success([
                    'sender' => $memberTransferLog->operatorMember->username ?? '',
                    'receiver' => $memberTransferLog->member->username ?? '',
                    'amount' => $memberTransferLog->amount,
                    'tax' => $memberTransferLog->tax,
                    'time' => $memberTransferLog->created_at->toFrontendTime()->toDateTimeString(),
                ]);

            default:
                return ApiResponse::success([], __('error_message.1010'));
        }
    }

    private function getRollingChipLogsDetail(array $input)
    {
        $userData = $this->getUserContext();
        $member = $userData->member;
        $memberId = $member->id;

        switch ($input['type']) {
            // 取得禮碼的傭金詳情 type=>4:傭金
            case 4:
                // 只能透過rolling_chip_logs的傭金發放時間, 取得前一天的團隊成員流水與傭金
                $rollingChipLog = RollingChipLog::query()
                    ->where('member_id', $memberId)
                    ->where('id', $input['data_id'])
                    ->first();

                if (!$rollingChipLog) {
                    return ApiResponse::success([], __('error_message.1010'));
                }

                $createdAt = $rollingChipLog->created_at;
                $summaryTime = $createdAt->startOfDay()->subDay()->toDateTimeString();

                $reports = ThreeHierarchyBonusReport::query()
                    ->with(['fromMember', 'vendor'])
                    ->where('member_id', $memberId)
                    ->where('reward_type', 2) // 1:真金 2:禮碼
                    ->where('summary_time', $summaryTime)
                    ->orderByDesc('id')
                    ->get();

                $data = $reports
                    ->groupBy(function ($item) {
                        return $item->from_member_id . '-' . $item->vendor_id;
                    })
                    ->map(function ($group) {
                        $item = $group->first();
                        $username = $item->fromMember->username ?? '';
                        $len = mb_strlen($username);

                        if ($len === 0) {
                            $fromMemberName = '';
                        } else {
                            $maskLen = (int) floor($len * 0.6);
                            $left = (int) floor(($len - $maskLen) / 2);
                            $right = $len - $maskLen - $left;
                            $fromMemberName = mb_substr($username, 0, $left)
                                . str_repeat('*', $maskLen)
                                . mb_substr($username, $left + $maskLen, $right);
                        }

                        $bet = $group->sum('bet') + $group->sum('down_bet') + $group->sum('down2_bet');
                        $rebate = $group->sum('rebate') + $group->sum('down_rebate') + $group->sum('down2_rebate');

                        return [
                            'from_member_name' => $fromMemberName,
                            'vendor_name' => $item->vendor->name ?? '',
                            'bet' => Common::numFloor($bet, 4),
                            'rebate' => Common::numFloor($rebate, 4),
                        ];
                    })
                    ->values();

                // 處理分頁
                $pageData = $data->paginate($input['per_page']);

                $sumData = $reports->reduce(function ($carry, $item) {
                    $carry['bet'] += $item->bet + $item->down_bet + $item->down2_bet;
                    $carry['rebate'] += $item->rebate + $item->down_rebate + $item->down2_rebate;

                    return $carry;
                }, ['bet' => 0, 'rebate' => 0]);

                return ApiResponse::success([
                    'data' => [
                        'items' => $pageData->items(),
                        'pagination' => OnlyPaginateResource::make($pageData),
                        'sum_data' => $sumData,
                        'time' => $createdAt->toFrontendTime()->toDateTimeString(),
                    ],
                ]);

            default:
                return ApiResponse::success([], __('error_message.1010'));
        }
    }
}
