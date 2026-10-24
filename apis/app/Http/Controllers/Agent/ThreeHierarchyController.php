<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\ThreeHierarchy\PayoutRecordDetailRequest;
use App\Http\Requests\Agent\ThreeHierarchy\PayoutRecordRequest;
use App\Http\Requests\Agent\ThreeHierarchy\UserSearchRequest;
use App\Http\Requests\Agent\ThreeHierarchy\WithdrawRecordRequest;
use App\Http\Resources\Agent\ThreeHierarchy\PayoutRecordDetailResource;
use App\Http\Resources\Agent\ThreeHierarchy\PayoutRecordResource;
use App\Http\Resources\Agent\ThreeHierarchy\UserSearchResource;
use App\Http\Resources\Agent\ThreeHierarchy\WithdrawRecordResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\ThreeHierarchyPayoutRecordService;
use App\Services\Agent\ThreeHierarchyUserSearchService;
use App\Services\Agent\ThreeHierarchyWithdrawRecordService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 三層代（含代理）模式：用戶查詢（target/tickets#4190）、派發紀錄（target/tickets#4199）、
 * 提現紀錄（target/tickets#4214）。對應舊系統 API_Agent 的接口（舊路徑見各 Request），
 * 並多了各 Request 說明的篩選、欄位、排序與統計。
 */
#[Group('three-hierarchy (三層代)')]
class ThreeHierarchyController extends Controller
{
    public function __construct(
        private readonly ThreeHierarchyUserSearchService $userSearchService,
        private readonly ThreeHierarchyPayoutRecordService $payoutRecordService,
        private readonly ThreeHierarchyWithdrawRecordService $withdrawRecordService,
    ) {}

    /**
     * 用戶查詢
     *
     * 會員與代理的返利累計清單（unionAll），附兩組統計卡（summary）。
     * 支援 identity 身分篩選與白名單欄位排序。
     */
    public function userSearch(UserSearchRequest $request): JsonResponse
    {
        $agent = $this->getAgentContext()->agent;

        $result = $this->userSearchService->query(
            agent: $agent,
            filters: [
                'agent' => (string) $request->input('agent', ''),
                'member' => (string) $request->input('member', ''),
                'identity' => (string) $request->input('identity', ''),
                'sort' => (string) $request->input('sort', ''),
                'direction' => (string) $request->input('direction', 'desc'),
            ],
            page: (int) $request->input('page', 1),
            perPage: (int) $request->input('per_page', 20),
        );

        return ApiResponse::success([
            'items' => UserSearchResource::collection($result['paginator']),
            'pagination' => OnlyPaginateResource::make($result['paginator']),
            'summary' => $result['summary'],
        ]);
    }

    /**
     * 派發紀錄
     *
     * 受益人（會員／代理）× 結算日的返利彙總清單，附兩組統計卡（summary）、
     * 計算基數、結算期間、提現單號；支援 identity 身分篩選，期間上限 32 天。
     */
    public function payoutRecord(PayoutRecordRequest $request): JsonResponse
    {
        $agent = $this->getAgentContext()->agent;

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);

        $result = $this->payoutRecordService->query(
            agent: $agent,
            filters: [
                'agent' => (string) $request->input('agent', ''),
                'member' => (string) $request->input('member', ''),
                'identity' => (string) $request->input('identity', ''),
                'start_time' => (string) $request->input('start_time'),
                'end_time' => (string) $request->input('end_time'),
            ],
            page: $page,
            perPage: $perPage,
        );

        $paginator = new LengthAwarePaginator($result['rows'], $result['total'], $perPage, $page);

        return ApiResponse::success([
            'items' => PayoutRecordResource::collection($result['rows']),
            'pagination' => OnlyPaginateResource::make($paginator),
            'summary' => $result['summary'],
        ]);
    }

    /**
     * 派發紀錄——下鑽明細
     *
     * 主表一列（受益人 × 結算日）展開為源頭粒度（來源會員 × 遊戲商 × 遊戲類別 × 反水類別），
     * 與前台會員版「我的業績」同粒度；附全量合計與反水類別小計。
     */
    public function payoutRecordDetail(PayoutRecordDetailRequest $request): JsonResponse
    {
        $agent = $this->getAgentContext()->agent;

        $result = $this->payoutRecordService->detail(
            agent: $agent,
            identity: (string) $request->input('identity'),
            targetId: (int) $request->input('id'),
            summaryTime: (string) $request->input('summary_time'),
            page: (int) $request->input('page', 1),
            perPage: (int) $request->input('per_page', 10),
        );

        return ApiResponse::success([
            'items' => PayoutRecordDetailResource::collection($result['paginator']),
            'pagination' => OnlyPaginateResource::make($result['paginator']),
            'summary' => $result['summary'],
        ]);
    }

    /**
     * 提現紀錄
     *
     * 返利實際入帳的收據清單，附統計卡（提現總額／筆數／去重人數）；
     * 支援代理商帳號篩選（該代理線下所有會員）、來源欄，期間上限 32 天。
     */
    public function withdrawRecord(WithdrawRecordRequest $request): JsonResponse
    {
        $agent = $this->getAgentContext()->agent;

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);

        $result = $this->withdrawRecordService->query(
            agent: $agent,
            filters: [
                'agent' => (string) $request->input('agent', ''),
                'member' => (string) $request->input('member', ''),
                'order_no' => (string) $request->input('order_no', ''),
                'start_time' => (string) $request->input('start_time'),
                'end_time' => (string) $request->input('end_time'),
            ],
            page: $page,
            perPage: $perPage,
        );

        $paginator = new LengthAwarePaginator($result['rows'], $result['total'], $perPage, $page);

        return ApiResponse::success([
            'items' => WithdrawRecordResource::collection($result['rows']),
            'pagination' => OnlyPaginateResource::make($paginator),
            'summary' => $result['summary'],
        ]);
    }
}
