<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\IpRestriction\IndexRequest;
use App\Http\Requests\Agent\IpRestriction\StoreRequest;
use App\Http\Requests\Agent\IpRestriction\UpdateRequest;
use App\Http\Requests\Agent\IpRestriction\ViewRequest;
use App\Http\Resources\Agent\IpRestriction\IpRestrictionResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\IpRestrictionService;
use Dedoc\Scramble\Attributes\Group;

#[Group('ip-restriction (風控IP限制)')]
class IpRestrictionController extends Controller
{
    public function __construct(
        protected IpRestrictionService $ipRestrictionService,
    ) {}

    public function list(IndexRequest $request)
    {
        $data = $this->ipRestrictionService->paginate($request->validated());

        return ApiResponse::successWithPagination(
            data: IpRestrictionResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
        );
    }

    public function view(ViewRequest $request)
    {
        $rule = $this->ipRestrictionService->find((int) $request->id);

        return ApiResponse::success(IpRestrictionResource::make($rule));
    }

    public function create(StoreRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $rule = $this->ipRestrictionService->create($agent, $request->validated());

        return ApiResponse::success(['id' => $rule->id]);
    }

    public function update(UpdateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();

        $this->ipRestrictionService->update($agent, (int) $data['id'], $data);

        return ApiResponse::success();
    }
}
