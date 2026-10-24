<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\GgrCondition\IndexRequest;
use App\Http\Requests\Agent\GgrCondition\StoreRequest;
use App\Http\Requests\Agent\GgrCondition\UpdateRequest;
use App\Http\Requests\Agent\GgrCondition\ViewRequest;
use App\Http\Resources\Agent\GgrCondition\GgrConditionResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\GgrConditionService;
use Dedoc\Scramble\Attributes\Group;

#[Group('ggr/condition-setting (推廣代理GGR條件設置)')]
class GgrConditionController extends Controller
{
    public function __construct(
        protected GgrConditionService $ggrConditionService,
    ) {}

    public function list(IndexRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $this->ggrConditionService->paginate($agent, $request->validated());

        return ApiResponse::successWithPagination(
            data: GgrConditionResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
        );
    }

    public function view(ViewRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $condition = $this->ggrConditionService->find($agent, (int) $request->id);

        return ApiResponse::success(GgrConditionResource::make($condition));
    }

    public function create(StoreRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $condition = $this->ggrConditionService->create($agent, $request->validated());

        return ApiResponse::success(['id' => $condition->id]);
    }

    public function update(UpdateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();

        $this->ggrConditionService->update($agent, (int) $data['id'], $data);

        return ApiResponse::success();
    }
}
