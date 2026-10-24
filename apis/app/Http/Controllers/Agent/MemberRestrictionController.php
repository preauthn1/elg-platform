<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MemberRestriction\DeleteRequest;
use App\Http\Requests\Agent\MemberRestriction\IndexRequest;
use App\Http\Requests\Agent\MemberRestriction\StoreRequest;
use App\Http\Requests\Agent\MemberRestriction\UpdateRequest;
use App\Http\Requests\Agent\MemberRestriction\ViewRequest;
use App\Http\Resources\Agent\MemberRestriction\MemberRestrictionResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\MemberRestrictionService;
use Dedoc\Scramble\Attributes\Group;

#[Group('member-restriction (風控會員限制)')]
class MemberRestrictionController extends Controller
{
    public function __construct(
        protected MemberRestrictionService $memberRestrictionService,
    ) {}

    public function list(IndexRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $this->memberRestrictionService->paginateForAgent($agent, $request->validated());

        return ApiResponse::successWithPagination(
            data: MemberRestrictionResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
        );
    }

    public function view(ViewRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $rule = $this->memberRestrictionService->findVisibleForAgent($agent, (int) $request->id);

        return ApiResponse::success(MemberRestrictionResource::make($rule));
    }

    public function create(StoreRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $rule = $this->memberRestrictionService->create($agent, $request->validated());

        return ApiResponse::success(['id' => $rule->id]);
    }

    public function update(UpdateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();

        $this->memberRestrictionService->update($agent, (int) $data['id'], $data);

        return ApiResponse::success();
    }

    public function delete(DeleteRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $this->memberRestrictionService->delete($agent, (int) $request->id);

        return ApiResponse::success();
    }
}
