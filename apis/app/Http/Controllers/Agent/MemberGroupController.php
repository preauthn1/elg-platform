<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\MemberGroup\AssignMemberRequest;
use App\Http\Requests\Agent\MemberGroup\DropdownRequest;
use App\Http\Requests\Agent\MemberGroup\IndexRequest;
use App\Http\Requests\Agent\MemberGroup\StoreRequest;
use App\Http\Requests\Agent\MemberGroup\UpdateRequest;
use App\Http\Requests\Agent\MemberGroup\ViewRequest;
use App\Http\Resources\Agent\MemberGroup\MemberGroupResource;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\MemberGroupService;
use Dedoc\Scramble\Attributes\Group;

#[Group('member-group (會員群組)')]
class MemberGroupController extends Controller
{
    public function __construct(
        protected MemberGroupService $memberGroupService,
    ) {}

    public function list(IndexRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $this->memberGroupService->paginateForAgent($agent, $request->validated());

        return ApiResponse::successWithPagination(
            data: MemberGroupResource::collection($data),
            paginator: OnlyPaginateResource::make($data),
        );
    }

    public function dropdownList(DropdownRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $memberId = $request->member_id ? (int) $request->member_id : null;

        return ApiResponse::success($this->memberGroupService->dropdown($agent, $memberId));
    }

    public function view(ViewRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $group = $this->memberGroupService->findVisibleForAgent($agent, (int) $request->id);

        return ApiResponse::success(MemberGroupResource::make($group));
    }

    public function create(StoreRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();
        $bankAccountIds = $data['bank_account_ids'] ?? null;
        unset($data['bank_account_ids']);

        $group = $this->memberGroupService->create($agent, $data, $bankAccountIds);

        return ApiResponse::success(['id' => $group->id]);
    }

    public function update(UpdateRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $data = $request->validated();
        $id = (int) $data['id'];
        unset($data['id']);
        $bankAccountIds = $data['bank_account_ids'] ?? null;
        unset($data['bank_account_ids']);

        $group = $this->memberGroupService->findOwnedForAgent($agent, $id);
        $this->memberGroupService->update($agent, $group, $data, $bankAccountIds);

        return ApiResponse::success();
    }

    public function bankAccounts()
    {
        $agent = $this->getAgentContext()->agent;

        return ApiResponse::success($this->memberGroupService->bankAccounts($agent));
    }

    public function assignMember(AssignMemberRequest $request)
    {
        $agent = $this->getAgentContext()->agent;
        $this->memberGroupService->assignMember(
            $agent,
            (int) $request->member_id,
            $request->member_group_id ? (int) $request->member_group_id : null,
        );

        return ApiResponse::success();
    }
}
