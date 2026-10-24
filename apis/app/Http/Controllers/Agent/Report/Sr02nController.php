<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent\Report;

use App\Data\Report\Sr02nFilter;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\Report\Sr02nListRequest;
use App\Http\Requests\Agent\Report\Sr02nTotalRequest;
use App\Http\Resources\Agent\Report\Sr02nRowResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\Report\Sr02nCashFlowTreeService;
use App\Services\Agent\Report\Sr02nReportService;
use Dedoc\Scramble\Attributes\Group;

/**
 * SR02N 開洗分明細表（target/tickets#4356）。
 *
 * 介面複製 SR02，差異：篩選改多選配對、來源支付商三層、變更點數拆出手續費／實際金額、拿掉存入對象。
 * 查詢邏輯見 {@see Sr02nReportService}。
 *
 * @knowledge rules/report-sr02n
 */
#[Group('報表')]
class Sr02nController extends Controller
{
    public function __construct(
        private Sr02nReportService $reportService,
        private Sr02nCashFlowTreeService $cashFlowTreeService,
    ) {}

    /**
     * SR02N 開洗分明細表列表
     *
     * 四段（會員主錢包、會員遊戲錢包、代理額度、代理佔成錢包）合併後依時間新到舊分頁。
     */
    public function list(Sr02nListRequest $request)
    {
        $context = $this->getAgentContext();
        $validated = $request->validated();

        $paginator = $this->reportService->list(
            viewer: $context->agent,
            filter: Sr02nFilter::fromValidated($validated),
            languageId: $context->languageId,
            page: (int) $validated['page'],
            perPage: (int) $validated['per_page'],
        );

        return ApiResponse::successWithPagination(
            Sr02nRowResource::collection($paginator->items()),
            OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * SR02N 開洗分明細表合計
     *
     * 轉入、轉出、手續費、實際金額四項全量加總（篩選須與列表相同）。
     */
    public function total(Sr02nTotalRequest $request)
    {
        $context = $this->getAgentContext();

        return ApiResponse::success($this->reportService->total(
            viewer: $context->agent,
            filter: Sr02nFilter::fromValidated($request->validated()),
            languageId: $context->languageId,
        ));
    }

    /**
     * SR02N 來源支付商三層樹
     *
     * 支付商 → 支付商戶 → 支付通道，一次回完供前端級聯；商戶底下沒有通道時 children 為空。
     */
    public function cashFlowTree()
    {
        $context = $this->getAgentContext();

        return ApiResponse::success($this->cashFlowTreeService->tree($context->agent, $context->languageId));
    }
}
