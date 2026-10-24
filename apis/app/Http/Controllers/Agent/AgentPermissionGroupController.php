<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\AgentPermission\GroupAgentsRequest;
use App\Http\Requests\Agent\AgentPermission\GroupCreateRequest;
use App\Http\Requests\Agent\AgentPermission\GroupDropdownRequest;
use App\Http\Requests\Agent\AgentPermission\GroupIdRequest;
use App\Http\Requests\Agent\AgentPermission\GroupListRequest;
use App\Http\Requests\Agent\AgentPermission\GroupStatusRequest;
use App\Http\Requests\Agent\AgentPermission\GroupUpdateRequest;
use App\Http\Resources\OnlyPaginateResource;
use App\Services\Agent\AgentPermissionGroupService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

/**
 * 代理角色（權限群組）管理（target/tickets#4422 自 API_Agent /api/agentPermissionGroup/* 搬入，新後台改打這裡）。
 *
 * 規則見 AgentPermissionGroupService。路由除角色下拉外掛 CheckAgentPermission:permission_group_management；
 * 角色下拉掛 add_agent（只有建立／編輯營運商頁在用；API_Agent 版不檢查，這裡補上）。
 */
#[Group('agent permission (代理權限)')]
class AgentPermissionGroupController extends Controller
{
    public function __construct(
        private readonly AgentPermissionGroupService $service,
    ) {}

    /**
     * 角色列表
     *
     * super admin 看全部、可依所屬代理篩（0＝只看 admin 持有、1＝admin預設角色），代理持有的 can_edit=false；
     * 總代看自己＋下線建的（下線建的 can_edit=false）；
     * 一般代理只看自己建的（target/tickets#4543）。
     */
    public function list(GroupListRequest $request): JsonResponse
    {
        $paginator = $this->service->list($this->getAgentContext()->agent, [
            'name' => $request->input('name'),
            'status' => $request->filled('status') ? (int) $request->input('status') : null,
            'owner_agent_id' => $request->filled('owner_agent_id') ? (int) $request->input('owner_agent_id') : null,
            'page' => $request->filled('page') ? (int) $request->input('page') : null,
            'per_page' => $request->filled('per_page') ? (int) $request->input('per_page') : null,
        ]);

        return ApiResponse::successWithPagination(
            data: $paginator->items(),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /** 角色詳情（含勾選選單與 action） */
    public function view(GroupIdRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->view($this->getAgentContext()->agent, (int) $request->input('id')));
    }

    /** 新增角色（所屬代理＝建立者：super admin 記 admin 持有、其他人記自己的主帳號；送別的值回 9003） */
    public function create(GroupCreateRequest $request): JsonResponse
    {
        $id = $this->service->create(
            $this->getAgentContext()->agent,
            $this->groupInput($request),
            $request->has('default_setting'),
        );

        return ApiResponse::success(['id' => $id]);
    }

    /**
     * 修改角色（只能改自己持有的；總代對下線建的只能檢視）
     *
     * 所屬代理建立後不可改（送了不同的值 9003）；default_setting 沒送沿用原值。只有持有者能改，admin 對代理持有的角色只能檢視（4543）。
     */
    public function update(GroupUpdateRequest $request): JsonResponse
    {
        $this->service->update(
            $this->getAgentContext()->agent,
            ['id' => (int) $request->input('id')] + $this->groupInput($request),
            $request->has('owner_agent_id'),
            $request->has('default_setting'),
        );

        return ApiResponse::success();
    }

    /** 角色啟用／停用（預設角色不得停用） */
    public function status(GroupStatusRequest $request): JsonResponse
    {
        $this->service->status($this->getAgentContext()->agent, (int) $request->input('id'), (int) $request->input('status'));

        return ApiResponse::success();
    }

    /** 掛在角色底下的代理 */
    public function agents(GroupAgentsRequest $request): JsonResponse
    {
        $paginator = $this->service->agents(
            $this->getAgentContext()->agent,
            (int) $request->input('id'),
            $request->filled('per_page') ? (int) $request->input('per_page') : null,
            $request->filled('page') ? (int) $request->input('page') : null,
        );

        return ApiResponse::successWithPagination(
            data: $paginator->items(),
            paginator: OnlyPaginateResource::make($paginator),
        );
    }

    /**
     * 角色下拉（建立／編輯營運商）
     *
     * 編輯帶 agent_id、新增帶 is_sub_account＋parent，目標必須在操作者範疇內。
     * 可選集合（target/tickets#4543）：admin 給 admin 持有的角色（目標是 admin 子帳號再加 admin預設角色）；
     * 總代給自己建的；一般代理給所屬總代的預設角色＋自己建的。存檔時後端用同一套規則再檢查。
     */
    public function dropdown(GroupDropdownRequest $request): JsonResponse
    {
        return ApiResponse::success($this->service->dropdown(
            $this->getAgentContext()->agent,
            $request->filled('agent_id') ? (int) $request->input('agent_id') : null,
            $request->filled('is_sub_account') ? (int) $request->input('is_sub_account') : null,
            $request->filled('parent') ? (int) $request->input('parent') : null,
        ));
    }

    /** 所屬代理選項，給列表篩選用（super admin 第一項是 admin（子帳號專用）；其他人只有自己）；建立／編輯時一律不可選 */
    public function ownerDropdown(): JsonResponse
    {
        return ApiResponse::success($this->service->ownerDropdown($this->getAgentContext()->agent));
    }

    /**
     * @return array{name: string, owner_agent_id: ?int, remark: ?string, status: int, default_setting: ?int, menus: array<mixed>}
     */
    private function groupInput(GroupCreateRequest|GroupUpdateRequest $request): array
    {
        return [
            'name' => (string) $request->input('name'),
            'owner_agent_id' => $request->filled('owner_agent_id') ? (int) $request->input('owner_agent_id') : null,
            'remark' => $request->input('remark'),
            'status' => (int) $request->input('status'),
            'default_setting' => $request->filled('default_setting') ? (int) $request->input('default_setting') : null,
            'menus' => (array) $request->input('menus', []),
        ];
    }
}
