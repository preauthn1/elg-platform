<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\GgrSettlement\IndexRequest;
use App\Http\Requests\Agent\GgrSettlement\ViewRequest;
use App\Http\Resources\Agent\GgrSettlement\GgrSettlementResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\GgrSettlementService;
use Dedoc\Scramble\Attributes\Group;

#[Group('ggr/payout-record (推廣代理GGR派發紀錄)')]
class GgrSettlementController extends Controller
{
    public function __construct(
        protected GgrSettlementService $ggrSettlementService,
    ) {}

    public function list(IndexRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $this->ggrSettlementService->paginate($agent, $request->validated());

        return ApiResponse::successWithPagination(
            data: GgrSettlementResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
        );
    }

    public function view(ViewRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        return ApiResponse::success(GgrSettlementResource::make($this->ggrSettlementService->find($agent, (int) $request->id)));
    }
}
