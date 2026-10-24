<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\GgrPlan\IndexRequest;
use App\Http\Requests\Agent\GgrPlan\StoreRequest;
use App\Http\Requests\Agent\GgrPlan\UpdateRequest;
use App\Http\Requests\Agent\GgrPlan\ViewRequest;
use App\Http\Resources\Agent\GgrPlan\GgrPlanResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\GgrPlanService;
use Dedoc\Scramble\Attributes\Group;

#[Group('ggr/plan (推廣代理GGR方案設定)')]
class GgrPlanController extends Controller
{
    public function __construct(
        protected GgrPlanService $ggrPlanService,
    ) {}

    public function list(IndexRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $this->ggrPlanService->paginate($agent, $request->validated());

        return ApiResponse::successWithPagination(
            data: GgrPlanResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
        );
    }

    public function view(ViewRequest $request)
    {
        $agent = $this->getAgentContext()->agent;

        return ApiResponse::success(GgrPlanResource::make($this->ggrPlanService->find($agent, (int) $request->id)));
    }

    public function create(StoreRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $plan = $this->ggrPlanService->create($agent, $request->validated());

        return ApiResponse::success(['id' => $plan->id]);
    }

    public function update(UpdateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();

        $this->ggrPlanService->update($agent, (int) $data['id'], $data);

        return ApiResponse::success();
    }

    /** 4121 表單下拉：本總代啟用中的遊戲商設定（id+name） */
    public function vendorSettingDropdown()
    {
        $agent = $this->getAgentContext()->agent;

        return ApiResponse::success($this->ggrPlanService->vendorSettingDropdown($agent));
    }

    /** 4121 表單下拉：本總代啟用中的條件設定（id+name） */
    public function conditionDropdown()
    {
        $agent = $this->getAgentContext()->agent;

        return ApiResponse::success($this->ggrPlanService->conditionDropdown($agent));
    }
}
